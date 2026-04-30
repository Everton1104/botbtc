<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BotState;
use App\Services\BotExecutor;

class ExecutarBots extends Command
{
    protected $signature = 'bots:executar';
    protected $description = 'Executa todos os bots ativos';

    public function handle()
    {
        $bots = BotState::where('ativo', 1)->get();
        $executor = app(BotExecutor::class);

        foreach ($bots as $bot) {
            try {
                $executor->executar($bot->id_user);
                $this->info("Bot executado: {$bot->id_user}");
            } catch (\Throwable $e) {
                $this->error("Erro no bot {$bot->id_user}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
