<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot diário do patrimônio do bot (saldos Binance marcados a preço
        // do momento). Um registro por dia, gravado pelo primeiro ciclo do cron
        // após a meia-noite — alimenta a base de retorno do simulador do painel
        // e, no futuro, gráfico de evolução do fundo e preco_por_cota histórico.
        // `total` segue o mesmo critério do patrimonio_bot de
        // bot_withdrawal_requests: BRL + BTC×preço (BNB fica em colunas próprias,
        // informativo — não entra no total pra não quebrar a consistência com a
        // série antiga nem com o preco_por_cota do sistema).
        Schema::create('bot_patrimonio', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('dia')->unique();          // um snapshot por dia (idempotência)
            $table->decimal('brl_livre', 18, 2)->default(0);
            $table->decimal('brl_bloqueado', 18, 2)->default(0);
            $table->decimal('btc_qty', 20, 10)->default(0);   // free + locked
            $table->decimal('btc_price', 18, 2)->default(0);  // preço no snapshot
            $table->decimal('bnb_qty', 20, 10)->default(0);   // informativo
            $table->decimal('bnb_price', 18, 2)->default(0);  // informativo
            $table->decimal('total', 18, 2)->default(0);      // brl + btc×preço
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_patrimonio');
    }
};
