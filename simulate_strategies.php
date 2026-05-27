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
 * │  MODO C (tendência MA/EMA) ← NOVO                                  │
 * │  Limites: p1=85% p2=60% p3=30% p4=10%                             │
 * │  % real = limite × fator_tendência (MA21 + EMA9)                  │
 * │  fator_compra  → maior quando preço está abaixo da MA21            │
 * │  fator_venda   → maior quando preço está acima da MA21            │
 * └────────────────────────────────────────────────────────────────────┘
 *
 * Capital inicial: R$ 1.000 | Sem BTC inicial
 */

// Fechamentos diários BTC/BRL (Binance, 16/05/2026, limit=30)
$closes = [
    385163, 378244, 368451, 376858, 380661, 388776, 393578,
    387561, 388647, 393452, 385503, 380432, 379335, 379121,
    388818, 390613, 389735, 397334, 397761, 401252, 395070,
    393573, 395953, 403742, 400511, 394788, 397853, 405317,
    401084, 396603,
];

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

// ── Helpers MA/EMA (Modo C) ───────────────────────────────────────────

function calcularEMA(array $closes, int $periodo): float
{
    $k   = 2 / ($periodo + 1);
    $ema = $closes[0];
    foreach ($closes as $close) {
        $ema = $close * $k + $ema * (1 - $k);
    }
    return $ema;
}

/**
 * Limites máximos por nível no Modo C.
 * p1=85% (nível 1) … p4=10% (nível 4+)
 */
function limiteModoC(int $n): float
{
    return match (true) {
        $n === 1 => 0.85,
        $n === 2 => 0.60,
        $n === 3 => 0.30,
        default  => 0.10,
    };
}

/**
 * Calcula fator_compra e fator_venda a partir do histórico disponível.
 *
 * Lógica:
 *  - MA21: média simples das últimas 21 velas
 *  - EMA9: média exponencial das últimas 20 velas (warm-up), período 9
 *  - distância = (preço - MA21) / MA21
 *  - fator base centrado em 0.5, amplitude ±15%
 *  - cruzamento EMA9 × MA21 ajusta ±0.10
 */
function calcularFatores(array $allCloses, int $idx, float $precoAtual): array
{
    $historico = array_slice($allCloses, 0, $idx); // velas ANTES da atual

    if (count($historico) < 9) {
        return ['compra' => 0.50, 'venda' => 0.50, 'ma21' => null, 'ema9' => null];
    }

    $n    = count($historico);
    $ma21 = $n >= 21
        ? array_sum(array_slice($historico, -21)) / 21
        : array_sum($historico) / $n;

    $warmup = array_slice($historico, max(0, $n - 20));
    $ema9   = calcularEMA($warmup, 9);

    $distancia   = ($precoAtual - $ma21) / $ma21;
    $fatorCompra = max(0.15, min(1.0, 0.5 - ($distancia / 0.30)));
    $fatorVenda  = max(0.15, min(1.0, 0.5 + ($distancia / 0.30)));

    $boost       = $ema9 > $ma21 ? 0.10 : -0.10;
    $fatorCompra = max(0.15, min(1.0, $fatorCompra - $boost));
    $fatorVenda  = max(0.15, min(1.0, $fatorVenda  + $boost));

    return ['compra' => $fatorCompra, 'venda' => $fatorVenda, 'ma21' => $ma21, 'ema9' => $ema9];
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

function simularC(array $closes, string $nome): array
{
    $brl    = 1000.0;
    $btc    = 0.0;
    $dir    = null;
    $c      = 0;
    $trades = 0;
    $log    = [];

    for ($i = 1; $i < count($closes); $i++) {
        $price  = (float) $closes[$i];
        $prev   = (float) $closes[$i - 1];
        $event  = $price > $prev ? 'UP' : 'DOWN';
        $newDir = $event === 'UP' ? 'up' : 'down';

        if ($newDir !== $dir) { $dir = $newDir; $c = 1; } else { $c++; }

        $fatores   = calcularFatores($closes, $i, $price);
        $fC        = $fatores['compra'];
        $fV        = $fatores['venda'];
        $ma21      = $fatores['ma21'];
        $ema9      = $fatores['ema9'];

        $descTrade = '';

        if ($event === 'DOWN') {
            $limite  = limiteModoC($c);
            $p       = $limite * $fC;

            if ($p > 0 && $brl * $p >= 10) {
                $gasto     = $brl * $p;
                $btcRecebido = ($gasto / $price) * (1 - TAXA);
                $btc      += $btcRecebido;
                $brl      -= $gasto;
                $descTrade = sprintf(
                    'COMPRA lim=%.0f%% fC=%.2f → %.1f%% → -R$%s  +%.5f BTC',
                    $limite * 100, $fC, $p * 100,
                    number_format($gasto, 2), $btcRecebido
                );
                $trades++;
            } else {
                $descTrade = sprintf('·· pausa compra (fC=%.2f)', $fC);
            }
        } else {
            $limite  = limiteModoC($c);
            $p       = $limite * $fV;

            if ($p > 0 && $btc * $p > 0) {
                $vendeu    = $btc * $p;
                $ganhou    = $vendeu * $price * (1 - TAXA);
                $btc      -= $vendeu;
                $brl      += $ganhou;
                $descTrade = sprintf(
                    'VENDA  lim=%.0f%% fV=%.2f → %.1f%% → -%.5f BTC  +R$%s',
                    $limite * 100, $fV, $p * 100,
                    $vendeu, number_format($ganhou, 2)
                );
                $trades++;
            } else {
                $descTrade = sprintf('·· pausa venda (fV=%.2f)', $fV);
            }
        }

        $ma21Str = $ma21 !== null ? number_format($ma21, 0, '.', '.') : '---';
        $ema9Str = $ema9 !== null ? number_format($ema9, 0, '.', '.') : '---';
        $total   = $brl + $btc * $price;

        $log[] = sprintf(
            'Dia%2d %s R$%s c=%-2d MA21=%-8s EMA9=%-8s| BRL=%-10s BTC=%-9s Total=%-10s| %s',
            $i, $event, number_format($price, 0, '.', '.'), $c,
            $ma21Str, $ema9Str,
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
    simularC ($closes, 'MODO C — Tendência MA/EMA (limites p1=85% p2=60% p3=30% p4=10%, % real = limite × fator)'),
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

echo "\n{$sep}\n COMPARATIVO — Capital inicial: R\$1.000,00\n{$sep}\n";
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

echo " [Modo C — Tendência MA21 + EMA9]\n";
echo "  p1-p4 são limites máximos. O % real = limite × fator (0.15-1.0).\n";
echo "  fator_compra sobe quando preço está abaixo da MA21 (sobrevendido).\n";
echo "  fator_venda  sobe quando preço está acima  da MA21 (sobrecomprado).\n";
echo "  EMA9 > MA21 → boost de venda +0.10 / redução de compra -0.10.\n";
echo "  EMA9 < MA21 → boost de compra +0.10 / redução de venda -0.10.\n";
echo "  → Compra mais no fundo verdadeiro; vende mais no topo verdadeiro.\n\n";

echo "{$sep}\n";
