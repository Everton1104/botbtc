<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gatilho vigente na borda inicial da era — só faz sentido na estratégia
 * 'fixo' (null nas eras ATR). Permite segmentar a comparação de performance
 * por gatilho quando o admin anda ajustando o valor ao longo dos dias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_estrategia_historico', function (Blueprint $table) {
            $table->float('gatilho_pct', 6, 3)->nullable()->after('estrategia');
        });
    }

    public function down(): void
    {
        Schema::table('bot_estrategia_historico', function (Blueprint $table) {
            $table->dropColumn('gatilho_pct');
        });
    }
};
