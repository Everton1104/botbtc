<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            // Valor mínimo (em BRL) pra criar uma ordem. Evita dust trades
            // (ordens de ~R$16 que só geram fee e poeira de BTC).
            $table->decimal('min_notional', 10, 2)->default(50.00)->after('allin_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn('min_notional');
        });
    }
};
