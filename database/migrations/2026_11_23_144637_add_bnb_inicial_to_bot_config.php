<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pool de BNB entra no patrimônio das cotas ("BRL2"): o estoque de BNB que
 * paga as taxas da Binance passa a ser SOMADO ao patrimônio — assim a
 * reposição automática do pool (BRL→BNB, interno) é neutra ao preço da cota
 * (troca de ativo dentro do fundo) e o consumo por taxa desce devagar, sem
 * buraco. A base de performance (08/10 19:07) foi fotografada SEM o pool;
 * bnb_inicial reconstrói a parte que faltava (snapshot de 09/10) pra o painel
 * de performance não pular de graça com a inclusão.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->decimal('bnb_inicial', 18, 2)->nullable()->after('brl_inicial');
        });

        $snap = DB::table('bot_patrimonio')->where('dia', '2026-10-09')->first();
        if ($snap && $snap->bnb_qty > 0 && $snap->bnb_price > 0) {
            DB::table('bot_config')->update([
                'bnb_inicial' => round((float) $snap->bnb_qty * (float) $snap->bnb_price, 2),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn('bnb_inicial');
        });
    }
};
