<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Toggle GERAL de notificações do usuário (aba Alertas): false = o
 * FcmService não envia NADA pra ele — silêncio total na origem, todos os
 * tipos (saque, depósito, liberação, alerta, trade). true = como sempre foi
 * (default: ninguém perde push na migração). O push_trades continua sendo
 * o sub-toggle de compra/venda; push_ativo=false corta tudo inclusive ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('push_ativo')->default(true)->after('push_trades');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_ativo');
        });
    }
};
