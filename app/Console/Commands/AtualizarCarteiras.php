<?php

namespace App\Console\Commands;

use App\Http\Controllers\CarteirasController;
use App\Models\WatchWallet;
use Illuminate\Console\Command;

/**
 * Atualiza o snapshot de TODAS as carteiras watch-only em segundo plano.
 *
 * O painel/site/app leem o cache (watch_wallets.estado) na hora — a
 * consulta ao mempool.space (lenta e sujeita a 429) fica toda aqui, no
 * scheduler, num ritmo controlado.
 */
class AtualizarCarteiras extends Command
{
    protected $signature = 'carteiras:atualizar';
    protected $description = 'Atualiza o snapshot em cache das carteiras watch-only (mempool.space)';

    public function handle(): int
    {
        $ctrl = app(CarteirasController::class);

        foreach (WatchWallet::all() as $w) {
            try {
                $ctrl->atualizarUma($w);
                $this->info("✓ {$w->rotulo}");
            } catch (\Throwable $e) {
                $this->warn("✗ {$w->rotulo}: {$e->getMessage()}");
            }
        }
        return self::SUCCESS;
    }
}
