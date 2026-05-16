<?php

/**
 * Simulação comparativa de estratégias
 * BTC/BRL — últimos 30 dias (16/05/2026)
 *
 * Uso: php simulate_strategies.php
 *
 * ┌───────────────────────────────────────────────────────┐
 * │  MODO A (atual)                                       │
 * │  Queda:  p1(50%) → p2(25%) → pausa                   │
 * │  Subida: p1(50%) → p2(25%) → pausa                   │
 * │                                                       │
 * │  MODO B (espelho proposto)                            │
 * │  Queda:  p1(50%) → p2(25%) → p3(10%) → pausa         │
 * │  Subida: p3(10%) → p2(25%) → p1(50%) → pausa         │
 * └───────────────────────────────────────────────────────┘
 *
 * Capital inicial: R$ 10.000 | Sem BTC inicial
 */

// Fechamentos diários BTC/BRL (Binance, 16/05/2026, limit=30)
$closes = [
    385163, 378244, 368451, 376858, 380661, 388776, 393578,
    387561, 388647, 393452, 385503, 380432, 379335, 379121,
    388818, 390613, 389735, 397334, 397761, 401252, 395070,
    393573, 395953, 403742, 400511, 394788, 397853, 405317,
    401084, 396603,
];

// p1=50% p2=25% p3=10% p4=5%
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

// Retorna o percentual conforme modo e contador
// Modo A: DOWN/UP → p1, p2, 0(pausa)
// Modo B: DOWN    → p1, p2, p3, 0
//         UP      → p3, p2, p1, 0 (espelho)
function getPct(string $event, int $c, bool $modoB): float
{
    if (!$modoB) {
        // Modo A — igual em ambas as direções
        return match (true) {
            $c === 1 => pct(1),  // 50%
            $c === 2 => pct(2),  // 25%
            default  => 0.0,     // pausa
        };
    }

    // Modo B
    if ($event === 'DOWN') {
        return match (true) {
            $c === 1 => pct(1),  // 50%
            $c === 2 => pct(2),  // 25%
            $c === 3 => pct(3),  // 10%
            default  => 0.0,     // pausa
        };
    } else { // UP — espelho
        return match (true) {
            $c === 1 => pct(3),  // 10%
            $c === 2 => pct(2),  // 25%
            $c === 3 => pct(1),  // 50%
            default  => 0.0,     // pausa
        };
    }
}

function simular(array $closes, string $nome, bool $modoB): array
{
    $brl    = 10000.0;
    $btc    = 0.0;
    $dir    = null;
    $c      = 0;
    $trades = 0;
    $log    = [];

    for ($i = 1; $i < count($closes); $i++) {
        $price  = (float) $closes[$i];
        $event  = $price > $closes[$i - 1] ? 'UP' : 'DOWN';
        $newDir = $event === 'UP' ? 'up' : 'down';

        if ($newDir !== $dir) {
            $dir = $newDir;
            $c   = 1;
        } else {
            $c++;
        }

        $p          = getPct($event, $c, $modoB);
        $descTrade  = '';

        if ($event === 'DOWN') {
            if ($p > 0 && $brl * $p >= 10) {
                $gasto  = $brl * $p;
                $ganhou = $gasto / $price;
                $brl   -= $gasto;
                $btc   += $ganhou;
                $descTrade = sprintf('COMPRA %3.0f%% → -R$%s  +%.5f BTC', $p * 100, number_format($gasto, 2), $ganhou);
                $trades++;
            } else {
                $descTrade = '·· pausa compra';
            }
        } else {
            if ($p > 0 && $btc * $p > 0) {
                $vendeu = $btc * $p;
                $ganhou = $vendeu * $price;
                $btc   -= $vendeu;
                $brl   += $ganhou;
                $descTrade = sprintf('VENDA  %3.0f%% → -%.5f BTC  +R$%s', $p * 100, $vendeu, number_format($ganhou, 2));
                $trades++;
            } else {
                $descTrade = '·· pausa venda';
            }
        }

        $total = $brl + $btc * $price;
        $log[] = sprintf(
            'Dia%2d %s R$%s c=%-2d| BRL=%-10s BTC=%-9s Total=%-10s| %s',
            $i,
            $event,
            number_format($price, 0, '.', '.'),
            $c,
            number_format($brl, 2),
            number_format($btc, 5),
            number_format($total, 2),
            $descTrade
        );
    }

    $totalFinal = $brl + $btc * end($closes);

    return [
        'nome'   => $nome,
        'brl'    => $brl,
        'btc'    => $btc,
        'total'  => $totalFinal,
        'lucro'  => $totalFinal - 10000,
        'pct'    => (($totalFinal - 10000) / 10000) * 100,
        'trades' => $trades,
        'log'    => $log,
    ];
}

$sep = str_repeat('─', 110);

$resultados = [
    simular($closes, 'MODO A — Atual   (queda p1→p2→pausa / subida p1→p2→pausa)', false),
    simular($closes, 'MODO B — Espelho (queda p1→p2→p3    / subida p3→p2→p1   )', true),
];

foreach ($resultados as $r) {
    echo "\n{$sep}\n {$r['nome']}\n{$sep}\n";
    foreach ($r['log'] as $linha) {
        echo " {$linha}\n";
    }
    echo "{$sep}\n";
    printf(
        " FINAL → BRL: R$%s | BTC: %.5f | Total: R$%s | Lucro: R$%s (%+.2f%%) | Trades: %d\n",
        number_format($r['brl'], 2),
        $r['btc'],
        number_format($r['total'], 2),
        number_format($r['lucro'], 2),
        $r['pct'],
        $r['trades']
    );
}

// ── Comparativo ──────────────────────────────────────────────────────────────
[$a, $b] = $resultados;
$diff    = $b['total'] - $a['total'];
$diffPct = $b['pct']   - $a['pct'];

echo "\n{$sep}\n COMPARATIVO — Capital inicial: R\$10.000,00\n{$sep}\n";
printf(" Modo A: R\$%s  (lucro R\$%s  /  %+.2f%%)\n",
    number_format($a['total'], 2), number_format($a['lucro'], 2), $a['pct']);
printf(" Modo B: R\$%s  (lucro R\$%s  /  %+.2f%%)\n",
    number_format($b['total'], 2), number_format($b['lucro'], 2), $b['pct']);
echo "{$sep}\n";
printf(" Diferença: R\$%s  (%+.2f%%)  →  Melhor neste período: %s\n",
    number_format(abs($diff), 2), $diffPct, $diff >= 0 ? 'MODO B' : 'MODO A');
echo "{$sep}\n\n";

echo " ANÁLISE DOS PONTOS CHAVE:\n\n";

echo " [Modo B — Queda]\n";
echo "  Compra na 3ª queda consecutiva (p3=10%) em vez de pausar.\n";
echo "  → Mais BTC acumulado em quedas longas, mas gasta mais BRL.\n\n";

echo " [Modo B — Subida]\n";
echo "  1ª reversão: vende apenas 10% (p3) → mantém BTC na alta\n";
echo "  2ª alta:     vende 25% (p2)\n";
echo "  3ª alta:     vende 50% (p1) → descarga máxima no topo\n";
echo "  → Ideal para altas em 3+ passos consecutivos.\n";
echo "  → Ruim se o mercado revirar após só 1 ou 2 subidas\n";
echo "    (vendeu pouco e o preço caiu com BTC na mão).\n\n";

echo " [Modo A — Subida]\n";
echo "  1ª reversão: vende 50% imediatamente → captura lucro rápido\n";
echo "  2ª alta:     vende 25% adicional\n";
echo "  → Ideal para recuperações bruscas de 1-2 dias.\n";
echo "  → Perde upside se a alta continuar além de 2 dias.\n\n";

echo "{$sep}\n";
