<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            // Gatilho manual do admin: quando ativo, o bot inibe as ordens de VENDA
            // (só mantém/cria compras) para aproveitar uma subida forte sem realizar cedo.
            $table->boolean('modo_subida')->default(false)->after('pausado_ate');
        });
    }

    public function down(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn('modo_subida');
        });
    }
};
