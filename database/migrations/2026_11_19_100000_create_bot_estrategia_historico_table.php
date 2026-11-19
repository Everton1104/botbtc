<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LINHA DO TEMPO DAS ESTRATÉGIAS DO GRID (ATR × Spread fixo).
 *
 * Uma linha por "era": a troca de estratégia (botão do painel / config)
 * encerra a era ativa e abre a nova, fotografando preço do BTC e patrimônio
 * nas duas bordas. Com isso + bot_trades (fills com timestamp) + o FIFO do
 * PnlService, a comparação de performance entre os modos é só fatiar por
 * janela [iniciado_em, encerrado_em] — sem depender de log (que rota).
 *
 * Eras anteriores à primeira alternância não têm linha (a estratégia era
 * 'atr' desde o início do bot; o marco inicial de comparação é a 1ª troca).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_estrategia_historico', function (Blueprint $table) {
            $table->increments('id');
            $table->string('estrategia', 10);              // 'atr' | 'fixo'
            $table->timestamp('iniciado_em');              // borda inicial da era
            $table->timestamp('encerrado_em')->nullable(); // null = era vigente
            $table->double('preco_btc', 16, 2)->nullable();      // foto na borda inicial
            $table->double('patrimonio', 16, 2)->nullable();     // BRL + BTC×preço (critério do snapshot diário)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_estrategia_historico');
    }
};
