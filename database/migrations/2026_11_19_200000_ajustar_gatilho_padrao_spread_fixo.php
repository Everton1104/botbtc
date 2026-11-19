<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GATILHO DO SPREAD FIXO: 0,15% → 0,30% por perna.
 *
 * Correção de matemática: um ciclo do grid captura o GATILHO (não o dobro)
 * — par centrado em C, SELL executa a C+g, recria centrado no fill, BUY
 * executa a C: ganho bruto = g. Com g = 0,15% o ganho bruto EMPATA a taxa
 * total (0,15%): ciclo zero-sum e todo rebase vira prejuízo (simulação no
 * mês real de out/nov: −R$ 2.800 vs −R$ 533 do hold). Com g = 0,30%:
 * bruto 0,30% − taxa 0,15% = líquido 0,15% = "lucro igual à taxa", a tese
 * original. Quem já está em 0,15 sobe junto (a simulação mostrou que é o
 * pior ponto da curva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->float('fixo_gatilho_pct', 6, 3)->default(0.30)->change();
        });

        // 0,15 (ponto de break-even) sobe pra 0,30 (tese). whereBetween
        // contra imprecisão de float.
        DB::table('bot_config')
            ->whereBetween('fixo_gatilho_pct', [0.149, 0.151])
            ->update(['fixo_gatilho_pct' => 0.30]);
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->float('fixo_gatilho_pct', 6, 3)->default(0.15)->change();
        });
        DB::table('bot_config')
            ->whereBetween('fixo_gatilho_pct', [0.299, 0.301])
            ->update(['fixo_gatilho_pct' => 0.15]);
    }
};
