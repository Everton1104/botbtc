<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida do saque ganha o estado final explícito:
 *   pendente → confirmado (PIX iniciado pelo admin, "em andamento")
 *           → pago     (PIX saiu de verdade — novo)
 *           → cancelado (cotas devolvidas)
 *
 * Motivação: o limite noturno de saque da Binance pode barra o PIX horas
 * depois do admin clicar "Iniciar Saque". Sem o estado 'pago', o saque
 * sumia da lista no clique e não havia botão de cancelar — o admin ficava
 * sem referência do valor e sem como devolver as cotas.
 *
 * A conversão abaixo liquida o histórico: todos os 'confirmado' existentes
 * foram pagos na prática (o único não-pago, o #34, já foi cancelado) — e o
 * guard novo do BotExecutor (segurar o bot enquanto houver saque em
 * andamento) não pode travar o bot com saques já liquidados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            $table->timestamp('pago_at')->nullable()->after('confirmado_at');
        });

        DB::table('bot_withdrawal_requests')
            ->where('status', 'confirmado')
            ->update(['status' => 'pago', 'pago_at' => DB::raw('confirmado_at')]);
    }

    public function down(): void
    {
        DB::table('bot_withdrawal_requests')
            ->where('status', 'pago')
            ->update(['status' => 'confirmado']);

        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('pago_at');
        });
    }
};
