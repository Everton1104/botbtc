<?php

/**
 * Simulação comparativa de estratégias
 * BTC/BRL — últimos 30 dias (16/05/2026)
 *
 * Uso: php simulate_strategies.php
 *
 * ┌────────────────────────────────────────────────────────────────────┐
 * │  MODO A (atual)                                                    │
 * │  % fixas: p1=50% p2=25% p3=10% p4=5%                              │
 * │  Queda:  p1 → p2 → pausa                                          │
 * │  Subida: p1 → p2 → pausa                                          │
 * │                                                                    │
 * │  MODO B (espelho)                                                  │
 * │  % fixas: p1=50% p2=25% p3=10% p4=5%                              │
 * │  Queda:  p1 → p2 → p3 → pausa                                     │
 * │  Subida: p3 → p2 → p1 → pausa                                     │
 * │                                                                    │
 * │  MODO C (tendência MA/EMA + RSI + MACD + Bollinger)                │
 * │  Limites: n1=85% n2=60% n3=35% n4=18% n5=10% n6=6% n7=3%        │
 * │  % real = limite × fator_tendência (MA21 + EMA9)                  │
 * │  fator_compra  → maior quando preço está abaixo da MA21            │
 * │  fator_venda   → maior quando preço está acima da MA21            │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * Capital inicial: R$ 1.000 | Sem BTC inicial
 */

// Busca candles diários ao vivo da Binance (OHLC real — highs/lows para ATR preciso)
// Uso: php simulate_strategies.php [dias=30]
$limit = isset($argv[1]) ? max(15, min(90, (int) $argv[1])) : 30;
$url   = "https://api.binance.com/api/v3/klines?symbol=BTCBRL&interval=1d&limit={$limit}";
$raw   = json_decode(file_get_contents($url), true);
if (!is_array($raw) || count($raw) < 15) {
    fwrite(STDERR, "Erro: não foi possível buscar klines da Binance.\n");
    exit(1);
}
$closes = array_map(fn($k) => (float) $k[4], $raw);
$highs  = array_map(fn($k) => (float) $k[2], $raw);
$lows   = array_map(fn($k) => (float) $k[3], $raw);

// ── Helpers de percentual fixo (Modos A e B) ─────────────────────────

function pct(int $n): float
{
    return match ($n) {
        1       => 0.50,
        2       => 0.25,
        3       => 0.10,
        4       => 0.05,
        default => 0.01,
    };
}

function getPct(string $event, int $c, bool $modoB): float
{
    if (!$modoB) {
        return match (true) {
            $c === 1 => pct(1),
            $c === 2 => pct(2),
            default  => 0.0,
        };
    }

    if ($event === 'DOWN') {
        return match (true) {
            $c === 1 => pct(1),
            $c === 2 => pct(2),
            $c === 3 => pct(3),
            default  => 0.0,
        };
    } else {
        return match (true) {
            $c === 1 => pct(3),
            $c === 2 => pct(2),
            $c === 3 => pct(1),
            default  => 0.0,
        };
    }
}

// ── Helpers MA/EMA/RSI/ATR (Modo C) ──────────────────────────────────

function calcularEMA(array $closes, int $periodo): float
{
    $k   = 2 / ($periodo + 1);
    $ema = $closes[0];
    foreach (array_slice($closes, 1) as $close) {
        $ema = $close * $k + $ema * (1 - $k);
    }
    return $ema;
}

function calcularRSI(array $closes, int $periodo = 14): float
{
    if (count($closes) < $periodo + 1) return 50.0;
    $changes = [];
    for ($i = 1; $i < count($closes); $i++) $changes[] = $closes[$i] - $closes[$i - 1];

    $ag = $al = 0.0;
    for ($i = 0; $i < $periodo; $i++) {
        if ($changes[$i] > 0) $ag += $changes[$i]; else $al += abs($changes[$i]);
    }
    $ag /= $periodo; $al /= $periodo;

    for ($i = $periodo; $i < count($changes); $i++) {
        $ag = ($ag * ($periodo - 1) + ($changes[$i] > 0 ? $changes[$i] : 0)) / $periodo;
        $al = ($al * ($periodo - 1) + ($changes[$i] < 0 ? abs($changes[$i]) : 0)) / $periodo;
    }
    return $al == 0 ? 100.0 : round(100 - (100 / (1 + $ag / $al)), 2);
}

function calcularATRSimulado(array $closes, int $periodo = 14, array $highs = [], array $lows = []): float
{
    if (count($closes) < 2) return 0.0;
    $useOHLC = count($highs) === count($closes) && count($lows) === count($closes);
    $trs = [];
    for ($i = 1; $i < count($closes); $i++) {
        if ($useOHLC) {
            $trs[] = max($highs[$i] - $lows[$i], abs($highs[$i] - $closes[$i-1]), abs($lows[$i] - $closes[$i-1]));
        } else {
            $trs[] = abs($closes[$i] - $closes[$i - 1]);
        }
    }
    $atr = array_sum(array_slice($trs, 0, $periodo)) / min($periodo, count($trs));
    foreach (array_slice($trs, $periodo) as $tr) $atr = ($atr * ($periodo - 1) + $tr) / $periodo;
    return round($atr, 2);
}

function calcularMACDSim(array $closes): array
{
    if (count($closes) < 26) return ['macd' => 0.0, 'signal' => 0.0, 'histogram' => 0.0];
    $k12 = 2/13; $k26 = 2/27; $k9 = 2/10;
    $e12 = $e26 = $closes[0]; $series = [];
    foreach ($closes as $c) { $e12=$c*$k12+$e12*(1-$k12); $e26=$c*$k26+$e26*(1-$k26); $series[]=$e12-$e26; }
    $sig = $series[0];
    foreach ($series as $m) $sig = $m*$k9 + $sig*(1-$k9);
    $macd = end($series);
    return ['macd' => round($macd,2), 'signal' => round($sig,2), 'histogram' => round($macd-$sig,2)];
}

function calcularBollingerSim(array $closes, int $periodo = 21): array
{
    if (count($closes) < $periodo) return ['upper'=>0.0,'lower'=>0.0,'width'=>0.0,'pct_b'=>0.5];
    $slice = array_slice($closes, -$periodo);
    $ma    = array_sum($slice) / $periodo;
    $std   = sqrt(array_sum(array_map(fn($c)=>($c-$ma)**2, $slice)) / $periodo);
    $upper = $ma + 2*$std; $lower = $ma - 2*$std;
    $range = $upper - $lower;
    $pctB  = $range > 0 ? (end($closes) - $lower) / $range : 0.5;
    return ['upper'=>round($upper,2),'lower'=>round($lower,2),'width'=>round($ma>0?$range/$ma:0,4),'pct_b'=>round($pctB,4)];
}

/**
 * Níveis máximos por contador — espelha BotConfig::niveis() com os valores atuais.
 * Acima do nível 7 usa 0.01 (fallback), igual ao BotExecutor.
 */
const NIVEIS_C = [
    1 => 0.85,
    2 => 0.60,
    3 => 0.35,
    4 => 0.18,
    5 => 0.10,
    6 => 0.06,
    7 => 0.03,
];
const ALLIN_THRESHOLD = 15;
const ALLIN_CAP       = 0.95;

function limiteModoC(int $n): float
{
    return NIVEIS_C[$n] ?? 0.01;
}

function calcularFatores(array $allCloses, int $idx, float $precoAtual, array $allHighs = [], array $allLows = []): array
{
    $historico = array_slice($allCloses, 0, $idx);
    $histHighs = count($allHighs) ? array_slice($allHighs, 0, $idx) : [];
    $histLows  = count($allLows)  ? array_slice($allLows,  0, $idx) : [];

    if (count($historico) < 9) {
        return ['compra' => 0.50, 'venda' => 0.50, 'ma21' => null, 'ema9' => null, 'rsi' => 50.0, 'atr' => 0.0, 'salto' => 2500];
    }

    $n    = count($historico);
    $ma21 = $n >= 21
        ? array_sum(array_slice($historico, -21)) / 21
        : array_sum($historico) / $n;

    $ema9 = calcularEMA($historico, 9);
    $rsi    = calcularRSI($historico, 14);
    $atr    = calcularATRSimulado($historico, 14, $histHighs, $histLows);
    $salto  = $atr > 0 ? max(1500, min(15000, (int)(round($atr * 0.5 / 500) * 500))) : 2500;
    $macdD  = calcularMACDSim($historico);
    $bollD  = calcularBollingerSim($historico, 21);

    $distancia   = ($precoAtual - $ma21) / $ma21;
    $fatorCompra = max(0.45, min(1.0, 0.5 - ($distancia / 0.30)));
    $fatorVenda  = max(0.45, min(1.0, 0.5 + ($distancia / 0.30)));

    $boost       = $ema9 > $ma21 ? 0.10 : -0.10;
    $fatorCompra = max(0.45, min(1.0, $fatorCompra - $boost));
    $fatorVenda  = max(0.45, min(1.0, $fatorVenda  + $boost));

    // RSI boost
    if ($rsi <= 30)     { $fatorCompra = min(1.0, $fatorCompra + 0.20); $fatorVenda  = max(0.45, $fatorVenda  - 0.10); }
    elseif ($rsi >= 70) { $fatorVenda  = min(1.0, $fatorVenda  + 0.20); $fatorCompra = max(0.45, $fatorCompra - 0.10); }

    // MACD boost
    if ($macdD['macd'] > $macdD['signal']) { $fatorVenda  = min(1.0,  $fatorVenda  + 0.10); $fatorCompra = max(0.45, $fatorCompra - 0.05); }
    else                                   { $fatorCompra = min(1.0,  $fatorCompra + 0.10); $fatorVenda  = max(0.45, $fatorVenda  - 0.05); }

    // Bollinger boost
    if ($bollD['pct_b'] <= 0.20)     { $fatorCompra = min(1.0, $fatorCompra + 0.10); }
    elseif ($bollD['pct_b'] >= 0.80) { $fatorVenda  = min(1.0, $fatorVenda  + 0.10); }

    return ['compra'=>$fatorCompra,'venda'=>$fatorVenda,'ma21'=>$ma21,'ema9'=>$ema9,
            'rsi'=>$rsi,'atr'=>$atr,'salto'=>$salto,
            'macd'=>$macdD['macd'],'macd_sig'=>$macdD['signal'],'macd_hist'=>$macdD['histogram'],
            'boll_pct_b'=>$bollD['pct_b'],'boll_width'=>$bollD['width']];
}

// ── Simuladores ──────────────────────────────────────────────────────

const TAXA = 0.00075; // 0,075% por ordem (Binance com BNB)

function simularAB(array $closes, string $nome, bool $modoB): array
{
    $brl    = 1000.0;
    $btc    = 0.0;
    $dir    = null;
    $c      = 0;
    $trades = 0;
    $log    = [];

    for ($i = 1; $i < count($closes); $i++) {
        $price  = (float) $closes[$i];
        $event  = $price > $closes[$i - 1] ? 'UP' : 'DOWN';
        $newDir = $event === 'UP' ? 'up' : 'down';

        if ($newDir !== $dir) { $dir = $newDir; $c = 1; } else { $c++; }

        $p         = getPct($event, $c, $modoB);
        $descTrade = '';

        if ($event === 'DOWN') {
            if ($p > 0 && $brl * $p >= 10) {
                $gasto     = $brl * $p;
                $btc      += ($gasto / $price) * (1 - TAXA); // taxa deduzida do BTC recebido
                $brl      -= $gasto;
                $descTrade = sprintf('COMPRA %3.0f%% → -R$%s  +%.5f BTC', $p * 100, number_format($gasto, 2), ($gasto / $price) * (1 - TAXA));
                $trades++;
            } else {
                $descTrade = '·· pausa compra';
            }
        } else {
            if ($p > 0 && $btc * $p > 0) {
                $vendeu    = $btc * $p;
                $ganhou    = $vendeu * $price * (1 - TAXA); // taxa deduzida do BRL recebido
                $btc      -= $vendeu;
                $brl      += $ganhou;
                $descTrade = sprintf('VENDA  %3.0f%% → -%.5f BTC  +R$%s', $p * 100, $vendeu, number_format($ganhou, 2));
                $trades++;
            } else {
                $descTrade = '·· pausa venda';
            }
        }

        $total = $brl + $btc * $price;
        $log[] = sprintf(
            'Dia%2d %s R$%s c=%-2d| BRL=%-10s BTC=%-9s Total=%-10s| %s',
            $i, $event, number_format($price, 0, '.', '.'), $c,
            number_format($brl, 2), number_format($btc, 5),
            number_format($total, 2), $descTrade
        );
    }

    return [
        'nome'   => $nome,
        'brl'    => $brl,
        'btc'    => $btc,
        'total'  => $brl + $btc * end($closes),
        'lucro'  => ($brl + $btc * end($closes)) - 1000,
        'pct'    => (($brl + $btc * end($closes) - 1000) / 1000) * 100,
        'trades' => $trades,
        'log'    => $log,
    ];
}

function simularC(array $closes, string $nome, array $highs = [], array $lows = []): array
{
    $brl             = 1000.0;
    $btc             = 0.0;
    $dir             = null;
    $c               = 0;
    $nivelMaximo     = 0; // espelha contador_anterior do BotExecutor
    $trades          = 0;
    $log             = [];
    $ultimaOrdem     = (float) $closes[0]; // referência de preço para medir o salto

    for ($i = 1; $i < count($closes); $i++) {
        $price  = (float) $closes[$i];
        $prev   = (float) $closes[$i - 1];
        $event  = $price > $prev ? 'UP' : 'DOWN';
        $newDir = $event === 'UP' ? 'up' : 'down';

        $fatores = calcularFatores($closes, $i, $price, $highs, $lows);
        $fC      = $fatores['compra'];
        $fV      = $fatores['venda'];
        $ma21    = $fatores['ma21'];
        $ema9    = $fatores['ema9'];
        $rsi     = $fatores['rsi'];
        $salto   = $fatores['salto'];

        $movimento = abs($price - $ultimaOrdem);

        // salto não atingido: registra no log e segue sem operar
        if ($movimento < $salto) {
            $ma21Str = $ma21 !== null ? number_format($ma21, 0, '.', '.') : '---';
            $total   = $brl + $btc * $price;
            $log[]   = sprintf(
                'Dia%2d %s R$%s c=%-2d nMax=%-2d MA21=%-8s RSI=%-5s salto=%-6s MACD=%-16s Boll=%-18s| BRL=%-10s BTC=%-9s Total=%-10s| ·· mov=%s < salto=%s',
                $i, $event, number_format($price, 0, '.', '.'), $c, $nivelMaximo,
                $ma21Str, sprintf('%.1f', $rsi), number_format($salto, 0, '.', '.'),
                sprintf('%+.0f/sig%+.0f', $fatores['macd'] ?? 0, $fatores['macd_sig'] ?? 0),
                sprintf('%%B=%.2f W=%.3f', $fatores['boll_pct_b'] ?? 0.5, $fatores['boll_width'] ?? 0),
                number_format($brl, 2), number_format($btc, 5), number_format($total, 2),
                number_format($movimento, 0, '.', '.'), number_format($salto, 0, '.', '.')
            );
            continue;
        }

        // salto atingido: atualiza direção/contador e opera
        $ultimaOrdem = $price;

        if ($newDir !== $dir) {
            $nivelMaximo = $c; // salva pico da direção anterior
            $dir = $newDir;
            $c   = 1;
        } else {
            $c++;
        }

        $allin     = $c >= ALLIN_THRESHOLD;
        $descTrade = '';

        if ($event === 'DOWN') {
            // ── COMPRA ──────────────────────────────────────────────
            if ($allin) {
                $p = ALLIN_CAP;
            } else {
                $p = limiteModoC($c) * $fC;
            }

            if ($p > 0 && $brl * $p >= 10) {
                $gasto       = $brl * $p;
                $btcRecebido = ($gasto / $price) * (1 - TAXA);
                $btc        += $btcRecebido;
                $brl        -= $gasto;
                $tag         = $allin ? 'ALL-IN' : sprintf('lim=%.0f%% fC=%.2f', limiteModoC($c) * 100, $fC);
                $descTrade   = sprintf(
                    'COMPRA %s → %.1f%% → -R$%s  +%.5f BTC',
                    $tag, $p * 100, number_format($gasto, 2), $btcRecebido
                );
                $trades++;
            } else {
                $descTrade = sprintf('·· pausa compra (fC=%.2f)', $fC);
            }

            // ── VENDA em queda (direção down) ────────────────────────
            $limiteV = $allin ? ALLIN_CAP : limiteModoC(max(1, $c)) * $fV;
            if ($limiteV > 0 && $btc * $limiteV > 0) {
                $vendeu  = $btc * $limiteV;
                $ganhou  = $vendeu * $price * (1 - TAXA);
                $btc    -= $vendeu;
                $brl    += $ganhou;
                $descTrade .= sprintf(
                    ' | VENDA lim=%.0f%% fV=%.2f → %.1f%% -%.5f BTC +R$%s',
                    limiteModoC(max(1, $c)) * 100, $fV, $limiteV * 100,
                    $vendeu, number_format($ganhou, 2)
                );
                $trades++;
            }
        } else {
            // ── COMPRA em subida (direção up) — sempre nivel1 × fC ───
            $pCompra = NIVEIS_C[1] * $fC;
            if ($pCompra > 0 && $brl * $pCompra >= 10) {
                $gasto       = $brl * $pCompra;
                $btcRecebido = ($gasto / $price) * (1 - TAXA);
                $btc        += $btcRecebido;
                $brl        -= $gasto;
                $descTrade   = sprintf(
                    'COMPRA lim=%.0f%% fC=%.2f → %.1f%% → -R$%s  +%.5f BTC',
                    NIVEIS_C[1] * 100, $fC, $pCompra * 100,
                    number_format($gasto, 2), $btcRecebido
                );
                $trades++;
            }

            // ── VENDA em subida — com offset se queda anterior >= 3 ──
            if ($allin) {
                $pVenda = ALLIN_CAP;
                $tag    = 'ALL-IN';
            } else {
                $offset = $nivelMaximo >= 3 ? 1 : 0;
                $pVenda = limiteModoC($c + $offset) * $fV;
                $tag    = sprintf('lim=%.0f%%(off=%d) fV=%.2f', limiteModoC($c + $offset) * 100, $offset, $fV);
            }

            if ($pVenda > 0 && $btc * $pVenda > 0) {
                $vendeu    = $btc * $pVenda;
                $ganhou    = $vendeu * $price * (1 - TAXA);
                $btc      -= $vendeu;
                $brl      += $ganhou;
                $descTrade .= ($descTrade ? ' | ' : '') . sprintf(
                    'VENDA %s → %.1f%% → -%.5f BTC  +R$%s',
                    $tag, $pVenda * 100, $vendeu, number_format($ganhou, 2)
                );
                $trades++;
            } else {
                $descTrade .= ($descTrade ? ' | ' : '') . sprintf('·· pausa venda (fV=%.2f)', $fV);
            }
        }

        $ma21Str  = $ma21 !== null ? number_format($ma21, 0, '.', '.') : '---';
        $rsiStr   = sprintf('%.1f', $rsi);
        $saltoStr = number_format($salto, 0, '.', '.');
        $macdStr  = sprintf('%+.0f/sig%+.0f', $fatores['macd'] ?? 0, $fatores['macd_sig'] ?? 0);
        $bollStr  = sprintf('%%B=%.2f W=%.3f', $fatores['boll_pct_b'] ?? 0.5, $fatores['boll_width'] ?? 0);
        $total    = $brl + $btc * $price;

        $log[] = sprintf(
            'Dia%2d %s R$%s c=%-2d nMax=%-2d MA21=%-8s RSI=%-5s salto=%-6s MACD=%-16s Boll=%-18s| BRL=%-10s BTC=%-9s Total=%-10s| %s',
            $i, $event, number_format($price, 0, '.', '.'), $c, $nivelMaximo,
            $ma21Str, $rsiStr, $saltoStr, $macdStr, $bollStr,
            number_format($brl, 2), number_format($btc, 5),
            number_format($total, 2), $descTrade
        );
    }

    return [
        'nome'   => $nome,
        'brl'    => $brl,
        'btc'    => $btc,
        'total'  => $brl + $btc * end($closes),
        'lucro'  => ($brl + $btc * end($closes)) - 1000,
        'pct'    => (($brl + $btc * end($closes) - 1000) / 1000) * 100,
        'trades' => $trades,
        'log'    => $log,
    ];
}

// ── Execução ──────────────────────────────────────────────────────────

$sep = str_repeat('─', 120);

$resultados = [
    simularAB($closes, 'MODO A — Atual   (p1=50% p2=25% / queda p1→p2→pausa  / subida p1→p2→pausa )', false),
    simularAB($closes, 'MODO B — Espelho (p1=50% p2=25% / queda p1→p2→p3     / subida p3→p2→p1   )', true),
    simularC ($closes, 'MODO C — Tendência MA/EMA (limites p1=85% p2=60% p3=30% p4=10%, % real = limite × fator)', $highs, $lows),
];

foreach ($resultados as $r) {
    echo "\n{$sep}\n {$r['nome']}\n{$sep}\n";
    foreach ($r['log'] as $linha) {
        echo " {$linha}\n";
    }
    echo "{$sep}\n";
    printf(
        " FINAL → BRL: R$%s | BTC: %.5f | Total: R$%s | Lucro: R$%s (%+.2f%%) | Trades: %d\n",
        number_format($r['brl'], 2), $r['btc'],
        number_format($r['total'], 2),
        number_format($r['lucro'], 2), $r['pct'],
        $r['trades']
    );
}

// ── Comparativo ───────────────────────────────────────────────────────

[$a, $b, $c_res] = $resultados;

echo "\n{$sep}\n COMPARATIVO — Capital inicial: R\$1.000,00 · Período: {$limit} dias · ATR dinâmico (OHLC real)\n{$sep}\n";
printf(" Modo A: R\$%s  (lucro R\$%s  /  %+.2f%%)\n",
    number_format($a['total'], 2), number_format($a['lucro'], 2), $a['pct']);
printf(" Modo B: R\$%s  (lucro R\$%s  /  %+.2f%%)\n",
    number_format($b['total'], 2), number_format($b['lucro'], 2), $b['pct']);
printf(" Modo C: R\$%s  (lucro R\$%s  /  %+.2f%%)\n",
    number_format($c_res['total'], 2), number_format($c_res['lucro'], 2), $c_res['pct']);
echo "{$sep}\n";

usort($resultados, fn($a, $b) => $b['total'] <=> $a['total']);
printf(" Melhor neste período: %s\n", $resultados[0]['nome']);
echo "{$sep}\n\n";

// ── Análise ───────────────────────────────────────────────────────────

echo " ANÁLISE DOS MODOS:\n\n";

echo " [Modo A — Subida agressiva imediata]\n";
echo "  Vende 50% na 1ª reversão → captura lucro rápido.\n";
echo "  Ideal para recuperações bruscas de 1-2 dias.\n\n";

echo " [Modo B — Espelho: subida escalonada]\n";
echo "  1ª alta: vende 10% → mantém BTC; 2ª: 25%; 3ª: 50% no topo.\n";
echo "  Ideal para altas em 3+ passos; ruim se revirar cedo.\n\n";

echo " [Modo C — Tendência MA21 + EMA9 — BotExecutor atual]\n";
echo "  7 níveis: 85% 60% 35% 18% 10% 6% 3% (fallback 1%). All-in 95% a partir do nível 15.\n";
echo "  % real = limite × fator (piso 0.45, teto 1.0).\n";
echo "  fator_compra sobe quando preço está abaixo da MA21 (sobrevendido).\n";
echo "  fator_venda  sobe quando preço está acima  da MA21 (sobrecomprado).\n";
echo "  EMA9 > MA21 → boost venda +0.10 / redução compra -0.10.\n";
echo "  EMA9 < MA21 → boost compra +0.10 / redução venda -0.10.\n";
echo "  Queda anterior ≥ 3 moves → offset +1 no nível de venda durante subida.\n";
echo "  UP: compra sempre no nível 1; DOWN: compra no nível do contador.\n";
echo "  → Compra mais no fundo verdadeiro; vende mais no topo verdadeiro.\n\n";

echo "{$sep}\n";
