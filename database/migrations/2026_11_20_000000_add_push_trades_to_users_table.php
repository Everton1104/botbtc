<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de push "avisos de compra e venda" (por usuário): o toggle
 * do app (aba Alertas) desliga SOMENTE o push de ordem executada — saques,
 * depósitos e alertas de preço continuam chegando. true = como sempre foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_trades')->default(true)->after('whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_trades');
        });
    }
};
