<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // all-in após 8 saltos é agressivo demais — em queda forte "pega faca".
        // Sobe pra 12 (o default original do código). O all-in ainda exige
        // confirmação de exaustão via RSI 4h, adicionada no BotExecutor.
        DB::table('bot_config')->where('id', 1)->update(['allin_threshold' => 12]);
    }

    public function down(): void
    {
        DB::table('bot_config')->where('id', 1)->update(['allin_threshold' => 8]);
    }
};
