<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Depósito condicional v2 — "aguardando na Binance":
 * • transferido_em: admin JÁ transferiu o valor pra Binance — a partir daí o
 *   valor é excluído do capital do fundo (preço da cota e saldo operacional
 *   do bot) até virar cotas no gatilho, SEM pausar nada.
 * • aviso_gatilho_em: push único pro admin quando o gatilho atinge mas o
 *   valor ainda não foi transferido (não converte sozinho nesse estado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->timestamp('transferido_em')->nullable()->after('convertido_em');
            $table->timestamp('aviso_gatilho_em')->nullable()->after('transferido_em');
        });
    }

    public function down(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->dropColumn(['transferido_em', 'aviso_gatilho_em']);
        });
    }
};
