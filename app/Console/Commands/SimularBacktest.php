<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BacktestService;

class SimularBacktest extends Command
{
    protected $signature = 'bots:simular {dias=30 : Período em dias (15-120)} {--patrimonio=1000 : Patrimônio inicial em BRL}';

    protected $description = 'Backtest fiel ao BotExecutor (candles 1h+4h + Fear & Greed). Read-only.';

    public function handle()
    {
        $dias       = max(15, min(120, (int) $this->argument('dias')));
        $patrimonio = max(100, (float) $this->option('patrimonio'));

        $this->info("Rodando backtest fiel: {$dias} dias · R\$ " . number_format($patrimonio, 2, ',', '.') . " iniciais ...");
        $res = app(BacktestService::class)->rodar($dias, $patrimonio);

        $sep    = str_repeat('─', 110);
        $totals = $res['totals'];
        $cfg    = $res['config'];

        echo "\n{$sep}\n CONFIG USADA (igual ao bot ao vivo)\n{$sep}\n";
        echo " Níveis 1-7: " . implode(' · ', array_map(fn($i, $v) => "n{$i}=" . round($v * 100) . '%', array_keys($cfg['niveis']), $cfg['niveis'])) . "\n";
        echo " All-in threshold: {$cfg['allin_threshold']} (gate RSI4h: queda≤40 / subida≥60)\n";
        echo " Min notional: R\$ " . number_format($cfg['min_notional'], 2, ',', '.') . "\n";
        echo " Salto dinâmico: clamp [{$cfg['salto_min']}, {$cfg['salto_max']}] · ATR×{$cfg['atr_mult']} · modulador Bollinger\n";

        echo "\n{$sep}\n RESULTADO\n{$sep}\n";
        printf(" Patrimônio final: R\$ %s   (BRL %s + %.8f BTC)\n",
            number_format($totals['patrimonio'], 2, ',', '.'),
            number_format($totals['brl'], 2, ',', '.'), $totals['btc']);
        printf(" ROI: %+.2f%%   (lucro R\$ %s)\n",
            $totals['roi_pct'], number_format($totals['patrimonio'] - $patrimonio, 2, ',', '.'));
        printf(" Trades: %d (compras %d · vendas %d · all-ins %d)\n",
            $totals['n_trades'], $totals['n_compras'], $totals['n_vendas'], $totals['n_allins']);
        printf(" Drawdown máx: %.2f%%   ·   Salto médio: R\$ %s   ·   F&G médio: %d\n",
            $totals['drawdown_max_pct'], number_format($totals['salto_medio'], 0, ',', '.'), $totals['fng_medio']);

        echo "\n{$sep}\n SÉRIE DIÁRIA (últimos " . count($res['serie']) . " dias)\n{$sep}\n";
        echo sprintf(" %-6s %-12s %-10s %-5s %-7s %-14s %s\n", 'Data', 'BTC close', 'Salto', 'F&G', 'Trades', 'Patrimônio', 'Δ dia');
        $ant = $patrimonio;
        foreach ($res['serie'] as $d) {
            $delta = $d['patrimonio'] - $ant;
            $cor   = $delta >= 0 ? '+' : '';
            echo sprintf(" %-6s R\$ %-9s R\$ %-6s %-5d %-7d R\$ %-11s %sR\$ %s\n",
                $d['date'],
                number_format($d['close'], 0, ',', '.'),
                number_format($d['salto'], 0, ',', '.'),
                $d['fng'],
                $d['trades_dia'],
                number_format($d['patrimonio'], 2, ',', '.'),
                $cor, number_format(abs($delta), 2, ',', '.')
            );
            $ant = $d['patrimonio'];
        }
        echo "{$sep}\n\n";

        return Command::SUCCESS;
    }
}
