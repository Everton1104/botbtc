<?php

namespace App\Services;

use App\Models\BotConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Backtest fiel ao BotExecutor.
 *
 * Replica EXATAMENTE a lógica de BotExecutor::analisarTendencia() + criarOrdensNovas(),
 * mas sobre SÉRIES HISTÓRICAS (klines 1h + 4h + Fear & Greed histórico) em vez de
 * klines ao vivo. Mesmo timeframe do bot (1h p/ indicadores, 4h p/ ATR/tendência),
 * mesmas constantes (SALTO_MIN/MAX, ATR_MULT, ALLIN_CAP), mesmos boosts de fator
 * com clamp único, mesmo gate de all-in por RSI 4h, mesmo min_notional com bump.
 *
 * READ-ONLY: só lê endpoints públicos da Binance e da Alternative.me. Não cria
 * ordens, não toca saldo, não depende da API key — seguro de rodar da dev box.
 *
 * Os cálculos de indicador (EMA/RSI/MACD/Bollinger/ATR) são CÓPIAS LITERAIS dos
 * métodos privados do BotExecutor (linhas 664-766) para garantir fidelidade total.
 */
class BacktestService
{
    private const SYMBOL     = 'BTCBRL';
    private const KLINES_API = 'https://api.binance.com/api/v3/klines';

    // ── Constantes espelhadas do BotExecutor ────────────────────────────
    private const SALTO_MIN  = 3000;
    private const SALTO_MAX  = 10000;
    private const ATR_MULT   = 0.8;
    // Janelas de vol diária para o salto (espelho do BotExecutor — mistura 30d/14d).
    private const ATR_DIAS_LONG  = 30;
    private const ATR_DIAS_CURTA = 14;
    private const ATR_PESO_LONG  = 0.7;
    private const ATR_RUPTURA    = 1.5;
    private const ALLIN_CAP  = 0.95;
    private const TAXA       = 0.00075; // 0,075% por ordem (maker com BNB)
    private const WARMUP_D   = 5;       // dias extras de pré-aquecimento p/ ter 50 candles

    /**
     * Roda o backtest dos últimos $dias, partindo de $patrimonioInicial em BRL.
     *
     * @return array{
     *   serie: array<int, array{date:string, close:float, patrimonio:float, trades_dia:int, salto:int, fng:int}>,
     *   totals: array{brl:float, btc:float, patrimonio:float, roi_pct:float, n_trades:int, n_compras:int, n_vendas:int, n_allins:int, drawdown_max_pct:float, salto_medio:float, fng_medio:float},
     *   config: array{dias:int, patrimonio_inicial:float, niveis:array, allin_threshold:int, min_notional:float, salto_min:int, salto_max:int, atr_mult:float}
     * }
     */
    public function rodar(int $dias, float $patrimonioInicial = 1000.0, ?int $saltoMinOverride = null): array
    {
        $dias        = max(1, $dias);
        $saltoMin    = $saltoMinOverride !== null ? max(500, $saltoMinOverride) : self::SALTO_MIN;
        $cfg         = BotConfig::atual();
        $niveis      = $cfg->niveis();
        $allinTh     = (int) $cfg->allin_threshold;
        $minNotional = (float) $cfg->min_notional;

        // ── Fontes de dados (públicas) ──────────────────────────────────
        $klines1h = $this->buscarKlines('1h', $dias + self::WARMUP_D);
        $klines4h = $this->buscarKlines('4h', $dias + self::WARMUP_D);
        $klines1d = $this->buscarKlines('1d', $dias + self::ATR_DIAS_LONG + self::WARMUP_D); // vol diária do salto, sem look-ahead
        $fngMap   = app(FearGreedService::class)->historico($dias + self::WARMUP_D);

        if (count($klines1h) < 60 || count($klines4h) < 22) {
            Log::warning('BacktestService: klines insuficientes.', ['1h' => count($klines1h), '4h' => count($klines4h)]);
            return $this->vazio($dias, $patrimonioInicial, $niveis, $allinTh, $minNotional);
        }

        // Pré-decodifica 4h em arrays planos (openTime, highs, lows, closes).
        $t4h  = [];
        foreach ($klines4h as $k) {
            $t4h[] = [
                'open'  => (int) $k[0],
                'high'  => (float) $k[2],
                'low'   => (float) $k[3],
                'close' => (float) $k[4],
            ];
        }
        // Pré-decodifica 1d (janelas de vol diária para o salto).
        $t1d  = [];
        foreach ($klines1d as $k) {
            $t1d[] = [
                'open'  => (int) $k[0],
                'high'  => (float) $k[2],
                'low'   => (float) $k[3],
                'close' => (float) $k[4],
            ];
        }

        // ── Estado da carteira (espelha BotState + criarOrdensNovas) ─────
        $brl               = $patrimonioInicial;
        $btc               = 0.0;
        $direcao           = null;
        $contadorSubidas   = 0;
        $contadorQuedas    = 0;
        $contadorAnterior  = 0;
        $ultimaOrdem       = (float) $klines1h[0][4]; // referência de preço do salto

        $nCompras = $nVendas = $nAllins = 0;
        $ponto4h  = 0; // ponteiro crescente p/ alinhar 4h com cada candle 1h
        $ponto1d  = 0; // ponteiro crescente p/ alinhar 1d (vol diária do salto)

        // ── Acumuladores diários (snapshot ao mudar o dia UTC) ───────────
        $serie          = [];
        $diaAtual       = null;
        $diaClose       = 0.0;
        $diaSalto       = 0;
        $diaFng         = 50;
        $diaTrades      = 0;
        $limiteDia      = gmdate('Y-m-d', time() - $dias * 86400); // só snapshot a partir daqui

        for ($i = 1, $n = count($klines1h); $i < $n; $i++) {
            $openTime = (int) $klines1h[$i][0];
            $price    = (float) $klines1h[$i][4];

            // ── Indicadores no ponto (janela 1h deslizante de 50) ───────
            $inicio = max(0, $i - 49);
            $closes = [];
            $highs  = [];
            $lows   = [];
            for ($j = $inicio; $j <= $i; $j++) {
                $closes[] = (float) $klines1h[$j][4];
                $highs[]  = (float) $klines1h[$j][2];
                $lows[]   = (float) $klines1h[$j][3];
            }

            $ind = $this->indicadoresNoPonto($closes, $highs, $lows, $price);

            // ── Alinha 4h: maior candle 4h com open <= openTime do 1h ────
            while (isset($t4h[$ponto4h + 1]) && $t4h[$ponto4h + 1]['open'] <= $openTime) {
                $ponto4h++;
            }
            $slice4h = array_slice($t4h, max(0, $ponto4h - 49), 50);
            $closes4h = array_column($slice4h, 'close');
            $highs4h  = array_column($slice4h, 'high');
            $lows4h   = array_column($slice4h, 'low');
            $atr      = count($closes4h) >= 15 ? $this->calcularATR($highs4h, $lows4h, $closes4h, 14) : 0.0;

            // ── ATR diário (mistura 30d/14d, sem look-ahead) → base do salto ──
            while (isset($t1d[$ponto1d + 1]) && $t1d[$ponto1d + 1]['open'] <= $openTime) {
                $ponto1d++;
            }
            $atrSalto = $atr; // fallback: ATR 4h se faltar histórico diário
            $slice1d  = array_slice($t1d, 0, $ponto1d + 1);
            $closes1d = array_column($slice1d, 'close');
            if (count($slice1d) >= self::ATR_DIAS_LONG + 1) {
                $highs1d = array_column($slice1d, 'high');
                $lows1d  = array_column($slice1d, 'low');
                $atrLongaDia = $this->calcularATR($highs1d, $lows1d, $closes1d, self::ATR_DIAS_LONG);
                $atrCurtaDia = $this->calcularATR($highs1d, $lows1d, $closes1d, self::ATR_DIAS_CURTA);
                if ($atrLongaDia > 0 && $atrCurtaDia > 0) {
                    $atrSalto = ($atrCurtaDia > self::ATR_RUPTURA * $atrLongaDia)
                        ? $atrCurtaDia
                        : self::ATR_PESO_LONG * $atrLongaDia + (1 - self::ATR_PESO_LONG) * $atrCurtaDia;
                }
            }

            $ma21_4h = count($closes4h) >= 21 ? array_sum(array_slice($closes4h, -21)) / 21 : 0.0;
            $ema9_4h = count($closes4h) >= 9 ? $this->calcularEMA($closes4h, 9) : 0.0;
            $rsi4h   = $this->calcularRSI($closes4h, 14);

            $trend4h = 0;
            if ($ma21_4h > 0) {
                if ($price > $ma21_4h && $ema9_4h > $ma21_4h)       $trend4h =  1;
                elseif ($price < $ma21_4h && $ema9_4h < $ma21_4h)   $trend4h = -1;
            }

            // ── Salto dinâmico (mistura diária × MULT × modulador Bollinger) ──
            $widthMod = max(0.65, min(1.5, 0.65 + ($ind['boll_width'] / 0.04) * 0.35));
            $salto = $atrSalto > 0
                ? (int) round(max($saltoMin, min(self::SALTO_MAX, $atrSalto * self::ATR_MULT * $widthMod)) / 500) * 500
                : 2500;

            // ── Fear & Greed do dia ─────────────────────────────────────
            $diaKey  = gmdate('Y-m-d', (int) ($openTime / 1000));
            $fngVal  = $fngMap[$diaKey] ?? 50;

            // ── Fatores (acúmulo + clamp único — igual analisarTendencia) ─
            [$fC, $fV] = $this->fatores($ind, $trend4h, $rsi4h, $fngVal);

            // ── Gate de cruzamento do grid ──────────────────────────────
            $movimento = abs($price - $ultimaOrdem);
            if ($movimento >= $salto) {
                // Direção: sinal do movimento ANTES de atualizar a referência.
                // Preço subiu >= salto → SELL executou → SUBIDA; caiu → BUY → QUEDA.
                $subiu       = $price > $ultimaOrdem;
                $ultimaOrdem = $price;
                $newDir      = $subiu ? 'up' : 'down';

                if ($newDir !== $direcao) {
                    $contadorAnterior = $direcao === 'up' ? $contadorSubidas : ($direcao === 'down' ? $contadorQuedas : 0);
                    $contadorSubidas  = 0;
                    $contadorQuedas   = 0;
                    $direcao          = $newDir;
                }
                if ($direcao === 'up') $contadorSubidas++;
                else                   $contadorQuedas++;

                $contadorAtual = $direcao === 'up' ? $contadorSubidas : $contadorQuedas;
                $nivelMaximo   = $contadorAnterior;

                // All-in com gate de RSI 4h (exaustão confirmada).
                $allin = $contadorAtual >= $allinTh
                    && ($direcao === 'down' ? $rsi4h <= 40 : $rsi4h >= 60);

                // ── COMPRA ──────────────────────────────────────────────
                if ($allin && $direcao === 'down') {
                    $valorCompra = $brl * self::ALLIN_CAP;
                } elseif ($direcao === 'down') {
                    $valorCompra = $brl * ($niveis[max(1, $contadorAtual)] ?? 0.01) * $fC;
                } else { // up ou null
                    $valorCompra = $brl * $niveis[1] * $fC;
                }

                // Bump até o min_notional (espelho do bot).
                if ($valorCompra > 0 && $valorCompra < $minNotional && $brl >= $minNotional) {
                    $valorCompra = $minNotional;
                }

                if ($valorCompra >= $minNotional && $valorCompra <= $brl) {
                    $btc += ($valorCompra / $price) * (1 - self::TAXA);
                    $brl -= $valorCompra;
                    $nCompras++;
                    $diaTrades++;
                    if ($allin && $direcao === 'down') $nAllins++;
                }

                // ── VENDA ───────────────────────────────────────────────
                if ($allin && $direcao === 'up') {
                    $pctVenda = self::ALLIN_CAP;
                } elseif ($direcao === 'up') {
                    $offset   = $nivelMaximo >= 3 ? 1 : 0;
                    $pctVenda = ($niveis[$contadorAtual + $offset] ?? 0.01) * $fV;
                } else { // down ou null
                    $pctVenda = ($niveis[max(1, $contadorAtual)] ?? 0.01) * $fV;
                }

                $qtyVenda   = $btc * $pctVenda;
                $valorVenda = $qtyVenda * $price;

                // Bump até o min_notional (espelho do bot).
                if ($valorVenda < $minNotional && $pctVenda > 0 && $btc > 0 && $btc * $price >= $minNotional) {
                    $qtyVenda   = min($btc, $minNotional / $price);
                    $valorVenda = $qtyVenda * $price;
                }

                if ($pctVenda > 0 && $btc > 0 && $valorVenda >= $minNotional) {
                    $brl += $qtyVenda * $price * (1 - self::TAXA);
                    $btc -= $qtyVenda;
                    $nVendas++;
                    $diaTrades++;
                    if ($allin && $direcao === 'up') $nAllins++;
                }
            }

            // ── Snapshot diário (ao mudar o dia UTC, dentro da janela) ───
            $diaSalto = $salto;
            $diaFng   = $fngVal;
            $diaClose = $price;

            if ($diaKey !== $diaAtual) {
                if ($diaAtual !== null && $diaAtual >= $limiteDia) {
                    $serie[] = [
                        'date'       => gmdate('d/m', strtotime($diaAtual)),
                        'close'      => $diaClose,
                        'patrimonio' => $brl + $btc * $diaClose,
                        'trades_dia' => $diaTrades,
                        'salto'      => $diaSalto,
                        'fng'        => $diaFng,
                    ];
                }
                $diaAtual  = $diaKey;
                $diaTrades = 0;
            }
        }

        // Último dia
        if ($diaAtual !== null && $diaAtual >= $limiteDia) {
            $serie[] = [
                'date'       => gmdate('d/m', strtotime($diaAtual)),
                'close'      => $diaClose,
                'patrimonio' => $brl + $btc * $diaClose,
                'trades_dia' => $diaTrades,
                'salto'      => $diaSalto,
                'fng'        => $diaFng,
            ];
        }

        // Trunca para exatamente $dias dias mais recentes.
        if (count($serie) > $dias) {
            $serie = array_slice($serie, -$dias);
        }

        return $this->consolidar(
            $serie, $brl, $btc, $diaClose, $patrimonioInicial, $dias,
            $nCompras, $nVendas, $nAllins, $niveis, $allinTh, $minNotional
        );
    }

    /**
     * Indicadores 1h no ponto: MA21, EMA9, RSI14, MACD, Bollinger, distância.
     * (Cópia literal da lógica de analisarTendencia — só a parte 1h.)
     */
    private function indicadoresNoPonto(array $closes, array $highs, array $lows, float $preco): array
    {
        $n = count($closes);
        if ($n < 9) {
            return ['ma21' => 0.0, 'ema9' => 0.0, 'rsi' => 50.0, 'distancia' => 0.0,
                    'macd' => 0.0, 'macd_signal' => 0.0, 'macd_hist' => 0.0,
                    'boll_width' => 0.0, 'boll_pct_b' => 0.5];
        }

        $ma21 = $n >= 21 ? array_sum(array_slice($closes, -21)) / 21 : array_sum($closes) / $n;
        $ema9 = $this->calcularEMA($closes, 9);
        $rsi  = $this->calcularRSI($closes, 14);

        $macd = $this->calcularMACD($closes);
        $boll = $this->calcularBollinger($closes, 21);

        return [
            'ma21'       => $ma21,
            'ema9'       => $ema9,
            'rsi'        => $rsi,
            'distancia'  => $ma21 > 0 ? ($preco - $ma21) / $ma21 : 0.0,
            'macd'       => $macd['macd'],
            'macd_signal'=> $macd['signal'],
            'macd_hist'  => $macd['histogram'],
            'boll_width' => $boll['width'],
            'boll_pct_b' => $boll['pct_b'],
        ];
    }

    /**
     * Fatores compra/venda: base responsiva + acúmulo de todos os boosts +
     * CLAMP ÚNICO no final. Cópia literal de BotExecutor::analisarTendencia().
     *
     * @return array{0:float,1:float} [fatorCompra, fatorVenda]
     */
    private function fatores(array $ind, int $trend4h, float $rsi4h, int $fngVal): array
    {
        $preco = ($ind['ma21'] > 0) ? $ind['ma21'] * (1 + $ind['distancia']) : 0.0;

        $distancia  = $ind['distancia'];
        $baseCompra = 0.5 - ($distancia / 0.03);
        $baseVenda  = 0.5 + ($distancia / 0.03);

        $ajCompra = 0.0;
        $ajVenda  = 0.0;

        // EMA9 × MA21 (±0.10)
        $boost = $ind['ema9'] > $ind['ma21'] ? 0.10 : -0.10;
        $ajCompra -= $boost;
        $ajVenda  += $boost;

        // RSI 1h: ±0.20/∓0.10 nos extremos
        if ($ind['rsi'] <= 30)     { $ajCompra += 0.20; $ajVenda  -= 0.10; }
        elseif ($ind['rsi'] >= 70) { $ajVenda  += 0.20; $ajCompra -= 0.10; }

        // MACD com DEADBAND (ignora ruído < 0,1% do preço)
        $macdDeadband = $preco * 0.001;
        if (abs($ind['macd_hist']) > $macdDeadband) {
            if ($ind['macd'] > $ind['macd_signal']) { $ajVenda  += 0.10; $ajCompra -= 0.05; }
            else                                     { $ajCompra += 0.10; $ajVenda  -= 0.05; }
        }

        // Bollinger %B: ±0.10 nos extremos
        if ($ind['boll_pct_b'] <= 0.20)     { $ajCompra += 0.10; }
        elseif ($ind['boll_pct_b'] >= 0.80) { $ajVenda  += 0.10; }

        // Tendência 4h: ±0.15/∓0.08 (reforçado)
        if ($trend4h === 1)      { $ajVenda  += 0.15; $ajCompra -= 0.08; }
        elseif ($trend4h === -1) { $ajCompra += 0.15; $ajVenda  -= 0.08; }

        // RSI 4h: +0.10 nos extremos
        if ($rsi4h <= 35)     $ajCompra += 0.10;
        elseif ($rsi4h >= 65) $ajVenda  += 0.10;

        // Fear & Greed: medo extremo favorece compra; ganância, venda
        if ($fngVal <= 25)      { $ajCompra += 0.15; $ajVenda  -= 0.08; }
        elseif ($fngVal >= 75)  { $ajVenda  += 0.15; $ajCompra -= 0.08; }

        // Clamp único
        return [
            max(0.45, min(1.0, $baseCompra + $ajCompra)),
            max(0.45, min(1.0, $baseVenda  + $ajVenda)),
        ];
    }

    // ── Cálculos de indicador (cópias literais do BotExecutor) ──────────

    private function calcularEMA(array $closes, int $periodo): float
    {
        $k   = 2 / ($periodo + 1);
        $ema = $closes[0];
        foreach (array_slice($closes, 1) as $close) {
            $ema = $close * $k + $ema * (1 - $k);
        }
        return $ema;
    }

    private function calcularRSI(array $closes, int $periodo = 14): float
    {
        if (count($closes) < $periodo + 1) return 50.0;

        $changes = [];
        for ($i = 1; $i < count($closes); $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        $avgGain = $avgLoss = 0.0;
        for ($i = 0; $i < $periodo; $i++) {
            if ($changes[$i] > 0) $avgGain += $changes[$i];
            else                  $avgLoss += abs($changes[$i]);
        }
        $avgGain /= $periodo;
        $avgLoss /= $periodo;

        for ($i = $periodo; $i < count($changes); $i++) {
            $gain    = $changes[$i] > 0 ? $changes[$i] : 0.0;
            $loss    = $changes[$i] < 0 ? abs($changes[$i]) : 0.0;
            $avgGain = ($avgGain * ($periodo - 1) + $gain) / $periodo;
            $avgLoss = ($avgLoss * ($periodo - 1) + $loss) / $periodo;
        }

        if ($avgLoss == 0) return 100.0;
        return round(100 - (100 / (1 + $avgGain / $avgLoss)), 2);
    }

    private function calcularMACD(array $closes): array
    {
        if (count($closes) < 26) return ['macd' => 0.0, 'signal' => 0.0, 'histogram' => 0.0];

        $k12 = 2 / 13; $k26 = 2 / 27; $k9 = 2 / 10;
        $e12 = $e26 = $closes[0];
        $macdSeries = [];

        foreach ($closes as $c) {
            $e12 = $c * $k12 + $e12 * (1 - $k12);
            $e26 = $c * $k26 + $e26 * (1 - $k26);
            $macdSeries[] = $e12 - $e26;
        }

        $signal = $macdSeries[0];
        foreach ($macdSeries as $m) {
            $signal = $m * $k9 + $signal * (1 - $k9);
        }

        $macd = end($macdSeries);
        return ['macd' => round($macd, 2), 'signal' => round($signal, 2), 'histogram' => round($macd - $signal, 2)];
    }

    private function calcularBollinger(array $closes, int $periodo = 21): array
    {
        if (count($closes) < $periodo) return ['upper' => 0.0, 'lower' => 0.0, 'width' => 0.0, 'pct_b' => 0.5];

        $slice = array_slice($closes, -$periodo);
        $ma    = array_sum($slice) / $periodo;
        $std   = sqrt(array_sum(array_map(fn($c) => ($c - $ma) ** 2, $slice)) / $periodo);
        $upper = $ma + 2 * $std;
        $lower = $ma - 2 * $std;
        $range = $upper - $lower;
        $pctB  = $range > 0 ? (end($closes) - $lower) / $range : 0.5;
        $width = $ma > 0 ? $range / $ma : 0.0;

        return [
            'upper' => round($upper, 2),
            'lower' => round($lower, 2),
            'width' => round($width, 4),
            'pct_b' => round($pctB, 4),
        ];
    }

    private function calcularATR(array $highs, array $lows, array $closes, int $periodo = 14): float
    {
        $n = count($closes);
        if ($n < 2) return 0.0;

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $trs[] = max(
                $highs[$i]  - $lows[$i],
                abs($highs[$i]  - $closes[$i - 1]),
                abs($lows[$i]   - $closes[$i - 1])
            );
        }

        $atr = array_sum(array_slice($trs, 0, $periodo)) / min($periodo, count($trs));
        foreach (array_slice($trs, $periodo) as $tr) {
            $atr = ($atr * ($periodo - 1) + $tr) / $periodo;
        }

        return round($atr, 2);
    }

    // ── Helpers de fetch e consolidação ─────────────────────────────────

    /**
     * Busca klines paginando (limite 1000/chamada) do passado até agora.
     */
    private function buscarKlines(string $intervalo, int $dias): array
    {
        $startMs = (time() - $dias * 86400) * 1000;
        $out     = [];
        $cursor  = $startMs;

        do {
            $url = self::KLINES_API . '?symbol=' . self::SYMBOL
                 . '&interval=' . $intervalo
                 . '&startTime=' . $cursor
                 . '&limit=1000';

            try {
                $resp = Http::timeout(15)->connectTimeout(5)->get($url);
                $batch = $resp->json() ?? [];
            } catch (\Throwable $e) {
                Log::warning('BacktestService: falha ao buscar klines.', ['intervalo' => $intervalo, 'msg' => $e->getMessage()]);
                break;
            }

            if (!is_array($batch) || empty($batch)) break;

            foreach ($batch as $k) {
                $out[] = $k;
            }

            $ultimoOpen = (int) end($batch)[0];
            $cursor     = $ultimoOpen + 1;

            // Sai se a última página veio incompleta ou já passou do agora.
            if (count($batch) < 1000 || $cursor >= time() * 1000) break;
        } while (true);

        return $out;
    }

    private function consolidar(
        array $serie, float $brl, float $btc, float $lastClose, float $inicial, int $dias,
        int $nCompras, int $nVendas, int $nAllins, array $niveis, int $allinTh, float $minNotional
    ): array {
        $patrimonioFinal = $brl + $btc * $lastClose;
        $roi             = $inicial > 0 ? ($patrimonioFinal - $inicial) / $inicial * 100 : 0.0;

        // Drawdown máx sobre a série diária de patrimônio.
        $peak          = $inicial;
        $drawdownMax   = 0.0;
        $saltos        = [];
        $fngs          = [];
        foreach ($serie as $d) {
            $peak        = max($peak, $d['patrimonio']);
            $dd          = $peak > 0 ? ($peak - $d['patrimonio']) / $peak * 100 : 0.0;
            $drawdownMax = max($drawdownMax, $dd);
            $saltos[]    = $d['salto'];
            $fngs[]      = $d['fng'];
        }

        return [
            'serie' => $serie,
            'totals' => [
                'brl'              => round($brl, 2),
                'btc'              => round($btc, 8),
                'patrimonio'       => round($patrimonioFinal, 2),
                'roi_pct'          => round($roi, 2),
                'n_trades'         => $nCompras + $nVendas,
                'n_compras'        => $nCompras,
                'n_vendas'         => $nVendas,
                'n_allins'         => $nAllins,
                'drawdown_max_pct' => round($drawdownMax, 2),
                'salto_medio'      => count($saltos) ? round(array_sum($saltos) / count($saltos)) : 0,
                'fng_medio'        => count($fngs) ? round(array_sum($fngs) / count($fngs)) : 50,
            ],
            'config' => [
                'dias'            => $dias,
                'patrimonio_inicial' => $inicial,
                'niveis'          => $niveis,
                'allin_threshold' => $allinTh,
                'min_notional'    => $minNotional,
                'salto_min'       => self::SALTO_MIN,
                'salto_max'       => self::SALTO_MAX,
                'atr_mult'        => self::ATR_MULT,
            ],
        ];
    }

    private function vazio(int $dias, float $inicial, array $niveis, int $allinTh, float $minNotional): array
    {
        return [
            'serie'   => [],
            'totals'  => [
                'brl' => round($inicial, 2), 'btc' => 0.0, 'patrimonio' => round($inicial, 2),
                'roi_pct' => 0.0, 'n_trades' => 0, 'n_compras' => 0, 'n_vendas' => 0, 'n_allins' => 0,
                'drawdown_max_pct' => 0.0, 'salto_medio' => 0, 'fng_medio' => 50,
            ],
            'config' => [
                'dias' => $dias, 'patrimonio_inicial' => $inicial, 'niveis' => $niveis,
                'allin_threshold' => $allinTh, 'min_notional' => $minNotional,
                'salto_min' => self::SALTO_MIN, 'salto_max' => self::SALTO_MAX, 'atr_mult' => self::ATR_MULT,
            ],
        ];
    }
}
