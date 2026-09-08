<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            // Pausa manual do admin: quando ativa, o bot cancela as ordens abertas
            // e não recria nada até o admin despausar (pra operar manualmente sem
            // o bot recriando o grid em cima). Diferente do pausado_ate (saque),
            // não tem prazo — só sai da pausa por gatilho humano.
            $table->boolean('pausado_manual')->default(false)->after('modo_subida');
        });
    }

    public function down(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn('pausado_manual');
        });
    }
};
