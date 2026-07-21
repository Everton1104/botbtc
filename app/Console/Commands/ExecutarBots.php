<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\BotState;
use App\Services\BotExecutor;

class ExecutarBots extends Command
{
    protected $signature = 'bots:executar';
    protected $description = 'Executa todos os bots ativos';

    public function handle()
    {
        // Lock atômico: garante uma única execução por vez, independente do gatilho
        // (Schedule::withoutOverlapping cobre o scheduler, mas o cron.php chama o
        // kernel direto — este lock cobre os dois caminhos). TTL de 5 min evita
        // travar pra sempre caso o processo morra no meio.
        $lock = Cache::lock('bots:executar', 300);

        if (!$lock->get()) {
            $this->warn('Execução anterior ainda em andamento. Pulando este ciclo.');
            return Command::SUCCESS;
        }

        try {
            $bots = BotState::where('ativo', 1)->get();

            // Todos os bots operam a MESMA conta Binance (chave de API única) no
            // par BTCBRL. Com mais de um ativo eles brigam pelas mesmas ordens —
            // processa só o primeiro e avisa, pra não corromper o estado.
            if ($bots->count() > 1) {
                Log::warning("ExecutarBots: {$bots->count()} bots ativos sobre uma única conta Binance. Processando apenas o primeiro ({$bots->first()->id_user}).");
                $bots = $bots->take(1);
            }

            $executor = app(BotExecutor::class);

            // Persiste os trades executados (myTrades) da conta Binance única.
            // Idempotente (dedup) — seguro rodar todo ciclo. Conta compartilhada
            // entre todos os bots, então sincroniza uma vez antes do loop.
            try {
                $executor->sincronizarTrades();
            } catch (\Throwable $e) {
                Log::warning("ExecutarBots: falha ao sincronizar trades (não bloqueia execução): " . $e->getMessage());
            }

            foreach ($bots as $bot) {
                try {
                    $executor->executar($bot->id_user);
                    $this->info("Bot executado: {$bot->id_user}");
                } catch (\Throwable $e) {
                    $this->error("Erro no bot {$bot->id_user}: " . $e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
