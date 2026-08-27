<?php

namespace App\Services;

use App\Models\BotTrade;
use App\Models\BotTransfer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    private const BTC_CACHE = 'pnl:btc:dia'; // série BTC diária cacheada
    private const BTC_TTL   = 3600;          // 1h (close do dia corrente precisa andar)

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
     * Eventos FIFO de BTC mesclados: trades BTCBRL + transfers diretos da
     * conta (bot_transfers), ordenados por data. Tipos: BUY/SELL (trade),
     * DEPOSIT (vira lote ao preço do dia — custo null se série indisponível)
     * e WITHDRAW (consome lote sem realização). Reconcilia o FIFO com o saldo
     * real: sem isso, saque direto vira estoque fantasma no btc_aberto.
     */
    private function eventosFifo(): array
    {
        $eventos = [];

        $trades = BotTrade::where('symbol', self::SYMBOL)->orderBy('traded_at')->get();
        foreach ($trades as $t) {
            $eventos[] = ['ts' => Carbon::parse($t->traded_at), 'tipo' => $t->side, 'qty' => (float) $t->qty, 'px' => (float) $t->price, 'trade' => $t];
        }

        $transfers = BotTransfer::where('coin', 'BTC')->orderBy('applied_at')->get();
        foreach ($transfers as $tr) {
            $eventos[] = [
                'ts'    => Carbon::parse($tr->applied_at),
                'tipo'  => $tr->transfer_type === BotTransfer::TIPO_WITHDRAW ? 'WITHDRAW' : 'DEPOSIT',
                'qty'   => (float) $tr->amount,
                'px'    => null,
                'trade' => null,
            ];
        }

        usort($eventos, fn($a, $b) => $a['ts']->timestamp <=> $b['ts']->timestamp);

        return $eventos;
    }

    /**
     * Roda o FIFO sobre TODOS os eventos BTC (trades + transfers) e devolve
     * estrutura bruta para os agregadores consumirem. Sem cache (a chamadora
     * cacheia o resultado final).
     */
    private function fifo(): array
    {
        $eventos = $this->eventosFifo();

        if (empty($eventos)) {
            return ['vazio' => true];
        }

        $haDepositos = (bool) array_filter($eventos, fn($e) => $e['tipo'] === 'DEPOSIT');
        $btcDia = $haDepositos
            ? $this->precoBtcPorDia($eventos[0]['ts']->copy()->startOfDay(), Carbon::now())
            : [];

        $bnbDia = $this->precoBnbPorDia(
            $eventos[0]['ts']->copy()->startOfDay(),
            Carbon::now()
        );

        $lotes        = []; // [['qty' => float, 'preco' => float|null (cost basis c/ fee compra; null = custo neutro)]]
        $pnlPorDia    = [];
        $tradesDia    = [];
        $volumeDia    = [];
        $feesBrlPorDia = [];
        $feesBrlTotal = 0.0;
        $semLote      = false;

        foreach ($eventos as $ev) {
            $dia = $ev['ts']->timezone(self::TZ)->format('Y-m-d');

            if ($ev['tipo'] === 'WITHDRAW') {
                // Saque direto: reduz lotes FIFO sem realização de P&L.
                $restante = $ev['qty'];
                while ($restante > 1e-10 && !empty($lotes)) {
                    $lote    = &$lotes[0];
                    $consome = min($restante, $lote['qty']);
                    $lote['qty'] -= $consome;
                    $restante    -= $consome;
                    if ($lote['qty'] <= 1e-10) {
                        array_shift($lotes);
                        unset($lote);
                    }
                }
                unset($lote);
                continue;
            }

            if ($ev['tipo'] === 'DEPOSIT') {
                // Depósito direto: vira lote ao preço do dia (custo de aquisição
                // desconhecido — aproximação do close diário; sem série, custo
                // neutro na venda futura, mesmo tratamento do pré-histórico).
                $preco = $this->precoBtcEm($ev['ts'], $btcDia);
                $lotes[] = ['qty' => $ev['qty'], 'preco' => $preco > 0 ? $preco : null];
                continue;
            }

            $t   = $ev['trade'];
            $qty = $ev['qty'];
            $px  = $ev['px'];

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
                    $custo   += $consome * ($lote['preco'] ?? $px); // lote null (depósito sem preço) → neutro
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

        // Posição comprada residual (lotes não consumidos). Lotes de depósito
        // sem preço (null) entram no btc mas não no custo — pm só sobre lotes
        // com cost basis conhecido.
        $btcAberto  = 0.0;
        $costAberto = 0.0;
        foreach ($lotes as $l) {
            $btcAberto  += $l['qty'];
            $costAberto += $l['preco'] !== null ? $l['qty'] * $l['preco'] : 0.0;
        }

        return [
            'vazio'             => false,
            'desde'             => $eventos[0]['ts']->timezone(self::TZ)->format('d/m/Y'),
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

    // ── Decomposição: grid vs oscilação ──────────────────────────────────

    /**
     * P&L de uma janela rolling decomposto em duas parcelas que somam a variação
     * total do patrimônio (BRL + BTC marcado a mercado) no período:
     *
     *  · GRID — ciclos de compra→venda INICIADOS e fechados dentro da janela:
     *    cada venda casada (FIFO) com lotes comprados na própria janela. É o
     *    "trabalho" do bot, independentemente do preço subir ou cair.
     *
     *  · OSCILAÇÃO — todo efeito de PREÇO sobre o BTC segurado, em duas partes:
     *    realizada (estoque herdado do início da janela, remarcado ao preço da
     *    data, que foi vendido durante a janela — a valorização anterior à
     *    janela nunca vira grid) e não realizada (lotes ainda abertos, do
     *    custo de referência até o preço de fim — inclui compras da janela
     *    que ainda não foram vendidas).
     *
     * Fees: rateados pela receita de cada parcela na venda (descarregar estoque
     * herdado não penaliza o grid); os de compra sobem o custo do lote (afetam
     * grid se vendido, oscilação se ficou aberto).
     */
    public function decomposicao(int $dias): array
    {
        $dias = $dias > 0 ? min($dias, 365) : 7;
        return Cache::remember('pnl:decomp:' . $dias, 300, fn () => $this->calcularDecomposicao($dias));
    }

    private function calcularDecomposicao(int $dias): array
    {
        $hoje   = Carbon::now(self::TZ);
        $inicio = $hoje->copy()->subDays($dias - 1)->startOfDay();
        $fim    = $hoje->copy()->endOfDay();

        $eventos = $this->eventosFifo();
        if (empty($eventos)) {
            return ['vazio' => true, 'periodo' => ['inicio' => $inicio->format('Y-m-d'), 'fim' => $fim->format('Y-m-d')]];
        }

        // Preços de referência: close diário do BTCBRL (mesmo mecanismo robusto
        // da série BNB). Fallback: preço real do último trade antes do marco.
        $btcDia = $this->precoBtcPorDia($inicio->copy()->subDay(), $hoje);
        $pIni   = $this->precoBtcEm($inicio->copy()->startOfDay(), $btcDia);
        $pFim   = $this->precoBtcEm($hoje->copy()->startOfDay(), $btcDia);

        if ($pIni <= 0) {
            foreach ($eventos as $ev) { // último trade antes do início da janela
                if ($ev['ts']->lt($inicio) && $ev['px'] !== null) $pIni = $ev['px'];
            }
        }
        if ($pFim <= 0) {
            for ($i = count($eventos) - 1; $i >= 0; $i--) { // trade mais recente
                if ($eventos[$i]['px'] !== null) { $pFim = $eventos[$i]['px']; break; }
            }
        }
        if ($pIni <= 0 || $pFim <= 0) {
            // Sem preço de referência confiável: não devolve número enganoso.
            Log::warning('PnlService: sem preço BTC de referência — decomposição indisponível agora.');
            return ['vazio' => true, 'periodo' => ['inicio' => $inicio->format('d/m/Y'), 'fim' => $fim->format('d/m/Y')]];
        }

        // Estoque no início da janela: FIFO real dos eventos anteriores ao marco
        // (trades + transfers — withdraw direto consome lote, depósito cria).
        // O remanescente é remarcado a pIni — é isso que separa a valorização
        // anterior à janela do lucro do grid.
        $lotes = [];
        foreach ($eventos as $ev) {
            if ($ev['ts']->lt($inicio)) {
                if ($ev['tipo'] === 'BUY' || $ev['tipo'] === 'DEPOSIT') {
                    $lotes[] = ['qty' => $ev['qty'], 'preco' => (float) ($ev['px'] ?? 0)];
                    continue;
                }
                // SELL ou WITHDRAW: consome FIFO; excesso sem lote (BTC de fora
                // dos registros) não reduz o estoque — custo desconhecido.
                $restante = $ev['qty'];
                while ($restante > 1e-10 && !empty($lotes)) {
                    $lote    = &$lotes[0];
                    $consome = min($restante, $lote['qty']);
                    $lote['qty'] -= $consome;
                    $restante    -= $consome;
                    if ($lote['qty'] <= 1e-10) {
                        array_shift($lotes);
                        unset($lote);
                    }
                }
                unset($lote);
            }
        }
        $estoqueIni = 0.0;
        foreach ($lotes as $l) {
            $estoqueIni += $l['qty'];
        }
        $lotes = [];
        if ($estoqueIni > 1e-10) {
            $lotes[] = ['qty' => $estoqueIni, 'preco' => $pIni, 'herdado' => true];
        }

        $bnbDia   = $this->precoBnbPorDia($inicio, $hoje);
        $grid     = 0.0;   // ciclos: vendas casadas com compras da própria janela
        $oscReal  = 0.0;   // oscilação realizada: venda do estoque herdado
        $fees     = 0.0;
        $vendas   = 0;
        $compras  = 0;
        $volume   = 0.0;

        foreach ($eventos as $ev) {
            if ($ev['ts']->lt($inicio)) continue;

            // Transferência direta DENTRO da janela: withdraw some do estoque
            // (sem grid nem oscilação — o BTC saiu da conta); deposit vira lote
            // próprio ao preço do dia (vira oscilação enquanto segurado).
            if ($ev['tipo'] === 'WITHDRAW' || $ev['tipo'] === 'DEPOSIT') {
                if ($ev['tipo'] === 'WITHDRAW') {
                    $restante = $ev['qty'];
                    while ($restante > 1e-10 && !empty($lotes)) {
                        $lote    = &$lotes[0];
                        $consome = min($restante, $lote['qty']);
                        $lote['qty'] -= $consome;
                        $restante    -= $consome;
                        if ($lote['qty'] <= 1e-10) {
                            array_shift($lotes);
                            unset($lote);
                        }
                    }
                    unset($lote);
                } else {
                    $precoD = $this->precoBtcEm($ev['ts'], $btcDia);
                    $lotes[] = ['qty' => $ev['qty'], 'preco' => $precoD > 0 ? $precoD : $pFim, 'herdado' => false];
                }
                continue;
            }

            $t   = $ev['trade'];
            $qty = $ev['qty'];
            $px  = $ev['px'];
            $fee = $this->feeBrl($t, $bnbDia);
            $fees += $fee;
            $volume += (float) $t->quote_qty;

            if ($t->side === 'BUY') {
                $compras++;
                $lotes[] = ['qty' => $qty, 'preco' => $qty > 0 ? $px + ($fee / $qty) : $px, 'herdado' => false];
                continue;
            }

            $vendas++;
            $restante    = $qty;
            $recCiclo    = 0.0; // receita vinda de lotes comprados na janela
            $custoCiclo  = 0.0;
            $recHerd     = 0.0; // receita vinda do estoque herdado
            $custoHerd   = 0.0;
            while ($restante > 1e-10) {
                if (empty($lotes)) {
                    // BTC sem origem registrada (fora dos registros): custo neutro,
                    // mesmo tratamento conservador do fifo().
                    $recCiclo   += $restante * $px;
                    $custoCiclo += $restante * $px;
                    $restante    = 0.0;
                    break;
                }
                $lote    = &$lotes[0];
                $consome = min($restante, $lote['qty']);
                if (!empty($lote['herdado'])) {
                    $recHerd   += $consome * $px;
                    $custoHerd += $consome * $lote['preco'];
                } else {
                    $recCiclo   += $consome * $px;
                    $custoCiclo += $consome * $lote['preco'];
                }
                $lote['qty'] -= $consome;
                $restante    -= $consome;
                if ($lote['qty'] <= 1e-10) {
                    array_shift($lotes);
                    unset($lote);
                }
            }
            unset($lote);

            // Fee da venda rateado pela receita de cada parcela — o custo de
            // descarregar estoque herdado não penaliza o grid.
            $recTot  = $recCiclo + $recHerd;
            $fracCic = $recTot > 0 ? $recCiclo / $recTot : 1.0;
            $grid    += ($recCiclo - $custoCiclo) - $fee * $fracCic;
            $oscReal += ($recHerd - $custoHerd) - $fee * (1 - $fracCic);
        }

        // Oscilação não realizada: lotes ainda abertos, de pIni/pCompra até pFim.
        $oscNao   = 0.0;
        $btcAber  = 0.0;
        $custoAb  = 0.0;
        foreach ($lotes as $l) {
            $var     = $l['qty'] * ($pFim - $l['preco']);
            if (!empty($l['herdado'])) $oscReal += $var; else $oscNao += $var;
            $btcAber += $l['qty'];
            $custoAb += $l['qty'] * $l['preco'];
        }
        $osc = $oscReal + $oscNao;

        // Base de retorno pro simulador ("R$ X teriam rendido Y"): patrimônio do
        // bot no início da janela. Fonte preferida: série diária bot_patrimonio
        // (snapshot por dia, gravada pelo cron). Fallback enquanto a série diária
        // não cobre o marco (começou em 27/08/2026): último registro da série
        // esparsa de bot_withdrawal_requests (um por saque). Se o registro mais
        // próximo do marco for antigo, o percentual vira aproximação — sinalizado.
        $reg = DB::table('bot_patrimonio')
            ->where('dia', '<=', $inicio->format('Y-m-d'))
            ->orderByDesc('dia')
            ->first();
        if ($reg) {
            $patrimonioIni = (float) $reg->total;
            $patrimonioEm  = Carbon::parse($reg->dia)->format('d/m/Y');
            $defasagemDias = (int) round(Carbon::parse($reg->dia)->diffInDays($inicio));
            $fontePatrimonio = 'diaria';
        } else {
            $reg = DB::table('bot_withdrawal_requests')
                ->where('created_at', '<=', $inicio)
                ->orderByDesc('created_at')
                ->first();
            $patrimonioIni = $reg ? (float) $reg->patrimonio_bot : null;
            $patrimonioEm  = $reg ? Carbon::parse($reg->created_at)->timezone(self::TZ)->format('d/m/Y') : null;
            $defasagemDias = $reg ? (int) round(Carbon::parse($reg->created_at)->diffInDays($inicio)) : null;
            $fontePatrimonio = 'saques';
        }

        $pct = $patrimonioIni && $patrimonioIni > 0;

        return [
            'vazio'      => false,
            'periodo'    => ['inicio' => $inicio->format('d/m/Y'), 'fim' => $fim->format('d/m/Y')],
            'dias'       => $dias,
            'grid'       => round($grid, 2),
            'oscilacao'  => round($osc, 2),
            'oscilacao_realizada'      => round($oscReal, 2),
            'oscilacao_nao_realizada'  => round($oscNao, 2),
            'total'      => round($grid + $osc, 2),
            'fees_brl'   => round($fees, 2),
            'vendas'     => $vendas,
            'compras'    => $compras,
            'volume'     => round($volume, 2),
            'estoque_ini' => round($estoqueIni, 8),
            'p_ini'      => (int) round($pIni),
            'p_fim'      => (int) round($pFim),
            'btc_aberto' => round($btcAber, 8),
            'pm_aberto'  => $btcAber > 0 ? (int) round($custoAb / $btcAber) : 0,
            // Retornos % da janela (base: patrimônio no início) p/ o simulador.
            'retorno_grid'      => $pct ? round(100 * $grid / $patrimonioIni, 4) : null,
            'retorno_oscilacao' => $pct ? round(100 * $osc / $patrimonioIni, 4) : null,
            'retorno_total'     => $pct ? round(100 * ($grid + $osc) / $patrimonioIni, 4) : null,
            'patrimonio_ini'    => $pct ? round($patrimonioIni, 2) : null,
            'patrimonio_ini_em' => $patrimonioEm,
            'patrimonio_fonte'  => $fontePatrimonio,
            'patrimonio_aproximado' => $defasagemDias !== null && $defasagemDias > 7,
        ];
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
        // dos lotes até cada venda). Mescla transfers diretos como o fifo() para
        // manter os dois caminhos consistentes.
        $eventos = $this->eventosFifo();
        if (empty($eventos)) return 0.0;

        $haDepositos = (bool) array_filter($eventos, fn($e) => $e['tipo'] === 'DEPOSIT');
        $btcDia = $haDepositos
            ? $this->precoBtcPorDia($eventos[0]['ts']->copy()->startOfDay(), Carbon::now())
            : [];
        $bnbDia = $this->precoBnbPorDia($eventos[0]['ts']->copy()->startOfDay(), Carbon::now());

        $corte = Carbon::now()->subDay();
        $lotes = [];
        $acc   = 0.0;

        foreach ($eventos as $ev) {
            if ($ev['tipo'] === 'WITHDRAW') {
                $restante = $ev['qty'];
                while ($restante > 1e-10 && !empty($lotes)) {
                    $lote    = &$lotes[0];
                    $consome = min($restante, $lote['qty']);
                    $lote['qty'] -= $consome;
                    $restante    -= $consome;
                    if ($lote['qty'] <= 1e-10) {
                        array_shift($lotes);
                        unset($lote);
                    }
                }
                unset($lote);
                continue;
            }
            if ($ev['tipo'] === 'DEPOSIT') {
                $preco  = $this->precoBtcEm($ev['ts'], $btcDia);
                $lotes[] = ['qty' => $ev['qty'], 'preco' => $preco > 0 ? $preco : null];
                continue;
            }

            $t   = $ev['trade'];
            $qty = $ev['qty'];
            $px  = $ev['px'];
            $fee = $this->feeBrl($t, $bnbDia);

            if ($t->side === 'BUY') {
                $lotes[] = ['qty' => $qty, 'preco' => $qty > 0 ? $px + ($fee / $qty) : $px];
                continue;
            }

            $dentro   = $ev['ts']->gte($corte);
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
                $custo   += $consome * ($lote['preco'] ?? $px); // lote null (depósito sem preço) → neutro
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
            unset($map['__at'], $map['__desde']);
            return $map;
        }

        // Primeira carga (cache frio): busca síncrona.
        $this->atualizarBnbCache($dias);
        $cachada = Cache::get(self::BNB_CACHE);
        if (is_array($cachada) && !empty($cachada)) {
            unset($cachada['__at'], $cachada['__desde']);
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
        $this->atualizarSerieCache(self::BNB_CACHE, 'BNBBRL', $dias, self::BNB_TTL);
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

    // ── Série BTC robusta (mesmo padrão da série BNB) ────────────────────

    /**
     * Série diária do preço BTC (close) desde $desde. Mapa ['Y-m-d' => float].
     * Cacheada por 1h (TTL curto: o close do dia corrente precisa acompanhar
     * o mercado pra manter a parcela de oscilação viva). Mesma resiliência da
     * série BNB: mantém a última série válida em caso de rate limit/erro.
     */
    private function precoBtcPorDia(Carbon $desde, Carbon $ate): array
    {
        $cachada = Cache::get(self::BTC_CACHE);
        $dias    = (int) round(max(1, $desde->diffInDays($ate) + 2));

        $cobre = static function (array $c) use ($desde): bool {
            // '__desde' guarda o primeiro dia da série: sem ele, uma série curta
            // (ex.: gerada p/ janela de 7d) seria usada p/ atender 30d — e o
            // fallback de média viraria p_ini de janelas maiores.
            return isset($c['__desde']) && $c['__desde'] <= $desde->format('Y-m-d');
        };

        if (is_array($cachada) && !empty($cachada) && $cobre($cachada)) {
            if (!isset($cachada['__at']) || now()->timestamp - (int) $cachada['__at'] > self::BTC_TTL) {
                $this->atualizarSerieCache(self::BTC_CACHE, 'BTCBRL', $dias, self::BTC_TTL);
            }
            unset($cachada['__at'], $cachada['__desde']);
            return $cachada;
        }

        // Cache frio ou sem cobertura da janela pedida: busca síncrona.
        $this->atualizarSerieCache(self::BTC_CACHE, 'BTCBRL', $dias, self::BTC_TTL);
        $cachada = Cache::get(self::BTC_CACHE);
        if (is_array($cachada) && !empty($cachada) && $cobre($cachada)) {
            unset($cachada['__at'], $cachada['__desde']);
            return $cachada;
        }

        return [];
    }

    /**
     * Busca klines 1d de $symbol na Binance e grava no cache $chave. Trata rate
     * limit/erro de estrutura mantendo o cache anterior (se houver).
     */
    private function atualizarSerieCache(string $chave, string $symbol, int $dias, int $ttl): void
    {
        $map = [];
        try {
            $resp = Http::timeout(10)->connectTimeout(5)->get(
                'https://api.binance.com/api/v3/klines?symbol=' . $symbol . '&interval=1d&limit=' . min(1000, $dias)
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
                Log::warning("PnlService: resposta inesperada da série {$symbol} (HTTP " . $resp->status() . ') — mantendo cache anterior se houver.');
            }
        } catch (\Throwable $e) {
            Log::warning("PnlService: falha ao buscar série {$symbol}. " . $e->getMessage());
        }

        if (empty($map)) {
            return; // mantém o cache anterior (se houver); não estraga com vazio.
        }

        $map['__at']    = now()->timestamp;
        $map['__desde'] = min(array_keys($map)); // 1º dia coberto (chave Y-m-d)
        Cache::put($chave, $map, $ttl);
    }

    private function precoBtcEm(Carbon $ts, array $btcDia): float
    {
        // Casa por dia UTC (klines 1d da Binance são UTC); mesmo fallback do BNB.
        $dia = $ts->copy()->utc()->format('Y-m-d');
        if (isset($btcDia[$dia])) return $btcDia[$dia];
        $ante = $ts->copy()->utc()->subDay()->format('Y-m-d');
        if (isset($btcDia[$ante])) return $btcDia[$ante];
        return !empty($btcDia) ? (array_sum($btcDia) / count($btcDia)) : 0.0;
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
