<?php

namespace App\Services;

use App\Models\BotTrade;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * P&L realizado do bot, derivado dos trades persistidos em bot_trades.
 *
 * Método: FIFO. Cada SELL é casada com as BUYs mais antigas que a originaram
 * (cost basis). O lucro de um dia = Σ (preço_venda − custo_aquisição) × qty_consumida
 * − fees, para as vendas daquele dia.
 *
 * O FIFO roda SEMPRE sobre TODOS os trades desde o início (cost basis fiel), mesmo
 * para o relatório mensal — lotes atravessam meses, então não dá pra fatiar. Apenas
 * a agregação (série + totais) é que se restringe à janela escolhida.
 *
 * Fees são convertidas pra BRL: BRL direto · BTC × preço do trade · BNB × preço
 * BNB do dia (série BNBBRL diária casada por data, cacheada). A série BNB é
 * buscada de forma robusta e cacheada: se a Binance der rate limit/erro, usa a
 * última série válida conhecida (e nunca zera o fee — bug anterior inflava o P&L).
 *
 * BTC vendido sem lote anterior (pré-histórico/depositado) usa custo = preço da
 * própria venda (pnl 0 p/ essa parcela — conservador, não infla o lucro).
 *
 * Agrupamento por dia em BRT (America/Sao_Paulo). Resultado cacheado.
 */
class PnlService
{
    private const SYMBOL    = 'BTCBRL';
    private const TZ        = 'America/Sao_Paulo';
    private const BNB_CACHE = 'pnl:bnb:dia'; // série BNB diária cacheada
    private const BNB_TTL   = 21600;         // 6h

    /**
     * P&L realizado do dashboard: totais rolling + série diária (últimos 30 dias BRT).
     */
    public function realizado(): array
    {
        return Cache::remember('pnl:realizado', 300, fn () => $this->dashboard());
    }

    /**
     * P&L realizado de um mês civil (BRT). null = mês/ano correntes.
     * Meses passados são imutáveis → cache 24h. Mês corrente → 5 min.
     */
    public function mensal(?int $ano = null, ?int $mes = null): array
    {
        $hoje = Carbon::now(self::TZ);
        $ano  = $ano ?? $hoje->year;
        $mes  = $mes ?? $hoje->month;
        $chave = 'pnl:mensal:' . $ano . '-' . str_pad((string) $mes, 2, '0', STR_PAD_LEFT);
        $ehAtual = ($ano === $hoje->year && $mes === $hoje->month);
        return Cache::remember($chave, $ehAtual ? 300 : 86400, fn () => $this->relatorioMensal($ano, $mes));
    }

    // ── FIFO central ─────────────────────────────────────────────────────

    /**
     * Roda o FIFO sobre TODOS os trades BTCBRL e devolve estrutura bruta para os
     * agregadores consumirem. Sem cache (a chamadora cacheia o resultado final).
     */
    private function fifo(): array
    {
        $trades = BotTrade::where('symbol', self::SYMBOL)->orderBy('traded_at')->get();

        if ($trades->isEmpty()) {
            return ['vazio' => true];
        }

        $bnbDia = $this->precoBnbPorDia(
            Carbon::parse($trades->first()->traded_at)->startOfDay(),
            Carbon::now()
        );

        $lotes        = []; // [['qty' => float, 'preco' => float (cost basis c/ fee compra)]]
        $pnlPorDia    = [];
        $tradesDia    = [];
        $volumeDia    = [];
        $feesBrlPorDia = [];
        $feesBrlTotal = 0.0;
        $semLote      = false;

        foreach ($trades as $t) {
            $dia = Carbon::parse($t->traded_at)->timezone(self::TZ)->format('Y-m-d');
            $qty = (float) $t->qty;
            $px  = (float) $t->price;

            $pnlPorDia[$dia]     ??= 0.0;
            $tradesDia[$dia]     ??= 0;
            $volumeDia[$dia]     ??= 0.0;
            $feesBrlPorDia[$dia] ??= 0.0;

            $tradesDia[$dia]++;
            $volumeDia[$dia] += (float) $t->quote_qty;

            $feeBrl = $this->feeBrl($t, $bnbDia);
            $feesBrlPorDia[$dia] += $feeBrl;
            $feesBrlTotal        += $feeBrl;

            if ($t->side === 'BUY') {
                $precoEfeit = $qty > 0 ? $px + ($feeBrl / $qty) : $px;
                $lotes[]    = ['qty' => $qty, 'preco' => $precoEfeit];
            } else { // SELL
                $restante = $qty;
                $receita  = 0.0;
                $custo    = 0.0;

                while ($restante > 1e-10) {
                    if (empty($lotes)) {
                        // BTC sem lote anterior (pré-histórico/depositado): custo =
                        // preço da própria venda → pnl 0 p/ essa parcela (conservador).
                        $semLote  = true;
                        $receita += $restante * $px;
                        $custo   += $restante * $px;
                        $restante = 0.0;
                        break;
                    }
                    $lote    = &$lotes[0];
                    $consome = min($restante, $lote['qty']);
                    $receita += $consome * $px;
                    $custo   += $consome * $lote['preco'];
                    $lote['qty'] -= $consome;
                    $restante    -= $consome;
                    if ($lote['qty'] <= 1e-10) {
                        array_shift($lotes);
                        unset($lote);
                    }
                }
                unset($lote);

                $pnlPorDia[$dia] += $receita - $custo - $feeBrl;
            }
        }

        if ($semLote) {
            Log::info('PnlService: houve SELL sem lote anterior (BTC pré-histórico/depositado) — parcela contada com custo neutro (pnl 0).');
        }

        // Posição comprada residual (lotes não consumidos).
        $btcAberto   = 0.0;
        $costAberto  = 0.0;
        foreach ($lotes as $l) {
            $btcAberto  += $l['qty'];
            $costAberto += $l['qty'] * $l['preco'];
        }

        return [
            'vazio'             => false,
            'desde'             => Carbon::parse($trades->first()->traded_at)->timezone(self::TZ)->format('d/m/Y'),
            'pnlPorDia'         => $pnlPorDia,
            'tradesDia'         => $tradesDia,
            'volumeDia'         => $volumeDia,
            'feesBrlPorDia'     => $feesBrlPorDia,
            'feesBrlTotal'      => $feesBrlTotal,
            'sem_lote'          => $semLote,
            'btc_aberto'        => round($btcAberto, 8),
            'cost_aberto_brl'   => round($costAberto, 2),
            'pm_aberto'         => $btcAberto > 0 ? (int) round($costAberto / $btcAberto) : 0,
        ];
    }

    // ── Agregador: dashboard rolling 30 dias ─────────────────────────────

    private function dashboard(): array
    {
        $f = $this->fifo();
        if ($f['vazio'] ?? false) {
            return $this->vazio();
        }

        $serie = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::now(self::TZ)->subDays($i)->format('Y-m-d');
            $serie[] = [
                'date'   => Carbon::createFromFormat('Y-m-d', $d, self::TZ)->format('d/m'),
                'key'    => $d,
                'pnl'    => round((float) ($f['pnlPorDia'][$d] ?? 0), 2),
                'trades' => (int) ($f['tradesDia'][$d] ?? 0),
                'volume' => round((float) ($f['volumeDia'][$d] ?? 0), 2),
            ];
        }

        $soma = static function (int $dias) use ($f): float {
            $acc = 0.0;
            for ($i = 0; $i < $dias; $i++) {
                $d   = Carbon::now(self::TZ)->subDays($i)->format('Y-m-d');
                $acc += (float) ($f['pnlPorDia'][$d] ?? 0);
            }
            return round($acc, 2);
        };

        $hojeBrt  = Carbon::now(self::TZ)->format('Y-m-d');
        $ontemBrt = Carbon::now(self::TZ)->subDay()->format('Y-m-d');
        $d7       = $soma(7);
        $d30      = $soma(30);

        // Rolling 24h: reprocessa o FIFO filtrando só vendas dentro da janela.
        $hj24h = $this->pnlRolling24h($f);

        return [
            'totais' => [
                'hj_24h'    => $hj24h,
                'hoje_brt'  => round((float) ($f['pnlPorDia'][$hojeBrt] ?? 0), 2),
                'ontem_brt' => round((float) ($f['pnlPorDia'][$ontemBrt] ?? 0), 2),
                'd7'        => $d7,
                'd30'       => $d30,
                'acumulado' => round(array_sum($f['pnlPorDia']), 2),
                'media_d7'  => round($d7 / 7, 2),
                'media_d30' => round($d30 / 30, 2),
                'fees_brl'  => round($f['feesBrlTotal'], 2),
                'desde'     => $f['desde'],
                'sem_lote'  => $f['sem_lote'],
                'btc_aberto'      => $f['btc_aberto'],
                'pm_aberto'       => $f['pm_aberto'],
            ],
            'serie' => $serie,
        ];
    }

    // ── Agregador: janela genérica (mês civil ou rolling) ────────────────

    private function relatorioMensal(int $ano, int $mes): array
    {
        $hoje   = Carbon::now(self::TZ);
        $inicio = Carbon::createFromDate($ano, $mes, 1, self::TZ)->startOfDay();
        $fim    = $inicio->copy()->endOfMonth();
        if ($fim->greaterThan($hoje)) {
            $fim = $hoje->copy()->endOfDay();
        }
        return $this->relatorioJanela($inicio, $fim, ($ano === $hoje->year && $mes === $hoje->month), $inicio->translatedFormat('F/Y'));
    }

    /**
     * Últimos N dias corridos (rolling, default 30). Sempre com dados até hoje
     * — evita a impressão de "zerado" do mês civil parcial ainda em andamento.
     */
    public function rolling(?int $dias = null): array
    {
        $dias = $dias && $dias > 0 ? min($dias, 365) : 30;
        return Cache::remember('pnl:rolling:' . $dias, 300, fn () => $this->calcularRolling($dias));
    }

    private function calcularRolling(int $dias): array
    {
        $hoje   = Carbon::now(self::TZ);
        $fim    = $hoje->copy()->endOfDay();
        $inicio = $hoje->copy()->subDays($dias - 1)->startOfDay();
        return $this->relatorioJanela($inicio, $fim, true, 'Últimos ' . $dias . ' dias');
    }

    private function relatorioJanela(Carbon $inicio, Carbon $fim, bool $ehAtual, string $rotulo): array
    {
        $f = $this->fifo();
        if ($f['vazio'] ?? false) {
            return $this->vazio();
        }

        // Série diária da janela (preenchida com 0 nos dias sem trade).
        $serie     = [];
        $pnlMes    = 0.0;
        $tradesMes = 0;
        $volumeMes = 0.0;
        $feesMes   = 0.0;
        $diasOp    = 0;
        $melhor    = ['dia' => null, 'pnl' => -INF];
        $pior      = ['dia' => null, 'pnl' => INF];

        // Drawdown dentro do mês sobre o acumulado do mês.
        $acumMes  = 0.0;
        $picoMes  = 0.0;
        $ddMaxMes = 0.0;

        for ($dia = $inicio->copy(); $dia->lessThanOrEqualTo($fim); $dia->addDay()) {
            $key  = $dia->format('Y-m-d');
            $pnl  = (float) ($f['pnlPorDia'][$key] ?? 0);
            $tr   = (int) ($f['tradesDia'][$key] ?? 0);
            $vol  = (float) ($f['volumeDia'][$key] ?? 0);
            $fee  = (float) ($f['feesBrlPorDia'][$key] ?? 0);

            $serie[] = [
                'date'   => $dia->format('d/m'),
                'key'    => $key,
                'pnl'    => round($pnl, 2),
                'trades' => $tr,
                'volume' => round($vol, 2),
                'fees'   => round($fee, 2),
            ];

            $pnlMes    += $pnl;
            $tradesMes += $tr;
            $volumeMes += $vol;
            $feesMes   += $fee;
            if ($tr > 0) $diasOp++;

            if ($pnl > $melhor['pnl']) $melhor = ['dia' => $key, 'pnl' => $pnl];
            if ($pnl < $pior['pnl'])   $pior   = ['dia' => $key, 'pnl' => $pnl];

            $acumMes  += $pnl;
            $picoMes  = max($picoMes, $acumMes);
            $ddMaxMes = max($ddMaxMes, $picoMes - $acumMes);
        }

        return [
            'mes'      => $rotulo,
            'periodo'  => ['inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d')],
            'atual'    => $ehAtual,
            'totais' => [
                'pnl'          => round($pnlMes, 2),
                'trades'       => $tradesMes,
                'volume'       => round($volumeMes, 2),
                'fees_brl'     => round($feesMes, 2),
                'dias_op'      => $diasOp,
                'dias_totais'  => count($serie),
                'melhor_dia'   => $melhor['dia'] !== null ? ['dia' => $melhor['dia'], 'pnl' => round($melhor['pnl'], 2)] : null,
                'pior_dia'     => $pior['dia'] !== null ? ['dia' => $pior['dia'], 'pnl' => round($pior['pnl'], 2)] : null,
                'drawdown_max' => round($ddMaxMes, 2),
                'media_dia_op' => $diasOp > 0 ? round($pnlMes / $diasOp, 2) : 0.0,
                'bruto'        => round($pnlMes + $feesMes, 2), // pnl antes dos fees
            ],
            'posicao' => [
                'btc_aberto'    => $f['btc_aberto'],
                'cost_aberto'   => $f['cost_aberto_brl'],
                'pm_aberto'     => $f['pm_aberto'],
            ],
            'desde'    => $f['desde'],
            'sem_lote' => $f['sem_lote'],
            'serie'    => $serie,
        ];
    }

    /**
     * P&L realizado das vendas com traded_at >= agora−24h (rolling), reaproveitando
     * o FIFO já processado para preservar o cost basis fiel dos lotes.
     */
    private function pnlRolling24h(array $f): float
    {
        if ($f['vazio'] ?? false) return 0.0;

        // Reprocessa apenas para isolar vendas na janela 24h (precisa do estado
        // dos lotes até cada venda). Mantém a implementação fiel ao FIFO global.
        $trades = BotTrade::where('symbol', self::SYMBOL)->orderBy('traded_at')->get();
        $bnbDia = $this->precoBnbPorDia(
            Carbon::parse($trades->first()->traded_at)->startOfDay(),
            Carbon::now()
        );

        $corte = Carbon::now()->subDay();
        $lotes = [];
        $acc   = 0.0;

        foreach ($trades as $t) {
            $qty = (float) $t->qty;
            $px  = (float) $t->price;
            $fee = $this->feeBrl($t, $bnbDia);

            if ($t->side === 'BUY') {
                $lotes[] = ['qty' => $qty, 'preco' => $qty > 0 ? $px + ($fee / $qty) : $px];
                continue;
            }

            $dentro   = Carbon::parse($t->traded_at) >= $corte;
            $restante = $qty;
            $receita  = 0.0;
            $custo    = 0.0;
            while ($restante > 1e-10) {
                if (empty($lotes)) {
                    $receita  += $restante * $px;
                    $custo    += $restante * $px;
                    $restante  = 0.0;
                    break;
                }
                $lote    = &$lotes[0];
                $consome = min($restante, $lote['qty']);
                $receita += $consome * $px;
                $custo   += $consome * $lote['preco'];
                $lote['qty'] -= $consome;
                $restante    -= $consome;
                if ($lote['qty'] <= 1e-10) {
                    array_shift($lotes);
                    unset($lote);
                }
            }
            unset($lote);

            if ($dentro) {
                $acc += $receita - $custo - $fee;
            }
        }

        return round($acc, 2);
    }

    // ── Conversão de fees ────────────────────────────────────────────────

    private function feeBrl(BotTrade $t, array $bnbDia): float
    {
        $asset = $t->commission_asset;
        $comm  = (float) $t->commission;
        if ($comm <= 0) return 0.0;

        return match ($asset) {
            'BRL'  => $comm,
            'BTC'  => $comm * (float) $t->price,
            'BNB'  => $comm * ($this->precoBnbEm(Carbon::parse($t->traded_at), $bnbDia)),
            default => 0.0, // USDT/outros: ignora (raro); log evita ruído.
        };
    }

    // ── Série BNB robusta (cacheada + fallback, nunca vazia) ─────────────

    /**
     * Série diária do preço BNB (close) desde $desde. Mapa ['Y-m-d' => float].
     * Cacheada por 6h. Se a Binance der rate limit/erro, devolve a última série
     * válida conhecida; se nunca houver série, usa um preço único (ticker atual)
     * aplicado a todos os dias — approximação, mas nunca zero.
     */
    private function precoBnbPorDia(Carbon $desde, Carbon $ate): array
    {
        $cachada = Cache::get(self::BNB_CACHE);
        $dias    = (int) round(max(1, $desde->diffInDays($ate) + 2));

        if (is_array($cachada) && !empty($cachada)) {
            // Atualiza em background só se a série estiver incompleta/velha.
            if (!isset($cachada['__at']) || now()->timestamp - (int) $cachada['__at'] > self::BNB_TTL) {
                $this->atualizarBnbCache($dias);
            }
            $map = $cachada;
            unset($map['__at']);
            return $map;
        }

        // Primeira carga (cache frio): busca síncrona.
        $this->atualizarBnbCache($dias);
        $cachada = Cache::get(self::BNB_CACHE);
        if (is_array($cachada) && !empty($cachada)) {
            unset($cachada['__at']);
            return $cachada;
        }

        return [];
    }

    /**
     * Busca a série BNB na Binance e atualiza o cache. Trata rate limit/erro de
     * estrutura e, em última instância, preenche com o ticker atual (fallback).
     */
    private function atualizarBnbCache(int $dias): void
    {
        $map = [];
        try {
            $resp = Http::timeout(10)->connectTimeout(5)->get(
                'https://api.binance.com/api/v3/klines?symbol=BNBBRL&interval=1d&limit=' . min(1000, $dias)
            );
            $j = $resp->json();
            // Só consome se a resposta for uma lista de velas (rate limit/erro vem como objeto).
            if ($resp->ok() && is_array($j) && array_is_list($j)) {
                foreach ($j as $k) {
                    if (is_array($k) && isset($k[0], $k[4])) {
                        $map[gmdate('Y-m-d', (int) ($k[0] / 1000))] = (float) $k[4]; // close
                    }
                }
            } else {
                Log::warning('PnlService: resposta inesperada da série BNB (HTTP ' . $resp->status() . ') — mantendo cache anterior se houver.');
            }
        } catch (\Throwable $e) {
            Log::warning('PnlService: falha ao buscar série BNB. ' . $e->getMessage());
        }

        if (empty($map)) {
            return; // mantém o cache anterior (se houver); não estraga com vazio.
        }

        $map['__at'] = now()->timestamp;
        Cache::put(self::BNB_CACHE, $map, self::BNB_TTL);
    }

    private function precoBnbEm(Carbon $ts, array $bnbDia): float
    {
        // Casa por dia UTC do trade (klines 1d da Binance são UTC).
        $dia = $ts->copy()->utc()->format('Y-m-d');
        if (isset($bnbDia[$dia])) return $bnbDia[$dia];
        // Tenta o dia anterior (caso o trade seja perto da virada).
        $ante = $ts->copy()->utc()->subDay()->format('Y-m-d');
        if (isset($bnbDia[$ante])) return $bnbDia[$ante];
        // Fallback: média da série (BNB varia pouco entre dias). Nunca zero se houver série.
        return !empty($bnbDia) ? (array_sum($bnbDia) / count($bnbDia)) : 0.0;
    }

    private function vazio(): array
    {
        $serie = [];
        for ($i = 29; $i >= 0; $i--) {
            $serie[] = [
                'date' => Carbon::now(self::TZ)->subDays($i)->format('d/m'),
                'key'  => Carbon::now(self::TZ)->subDays($i)->format('Y-m-d'),
                'pnl'  => 0.0, 'trades' => 0, 'volume' => 0.0, 'fees' => 0.0,
            ];
        }
        return [
            'totais' => [
                'hj_24h' => 0.0, 'hoje_brt' => 0.0, 'ontem_brt' => 0.0,
                'd7' => 0.0, 'd30' => 0.0, 'acumulado' => 0.0,
                'media_d7' => 0.0, 'media_d30' => 0.0, 'fees_brl' => 0.0,
                'desde' => null, 'sem_lote' => false,
                'btc_aberto' => 0.0, 'pm_aberto' => 0,
            ],
            'serie' => $serie,
        ];
    }
}
