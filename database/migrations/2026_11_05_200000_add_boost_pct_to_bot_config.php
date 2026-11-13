<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boost de ordens (admin): multiplica o valor FINAL de TODAS as ordens
 * criadas pelo bot (1 + boost/100), após todos os ajustes (nível, fatores,
 * bump e piso), até ser retirado. 0 = desligado. Só os tetos absolutos
 * (99,5% do saldo / 95% do estoque) mandam por cima. Trocar o valor
 * cancela as ordens abertas — o ciclo seguinte recria o par no novo
 * tamanho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->float('boost_pct', 5, 2)->default(0)->after('piso_ordem_pct');
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn('boost_pct');
        });
    }
};
