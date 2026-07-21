<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Espelha /api/v3/myTrades da Binance. Permite medir P&L/fees/drawdown sem
        // depender de export manual e reconciliar banco × Binance.
        Schema::create('bot_trades', function (Blueprint $table) {
            $table->bigIncrements('id');
            // id do trade na Binance — único por símbolo, usado no dedup (insertOrIgnore).
            $table->unsignedBigInteger('binance_trade_id');
            $table->unsignedBigInteger('binance_order_id')->nullable();
            $table->string('symbol', 20);
            $table->string('side', 4);                 // BUY | SELL
            $table->decimal('price', 18, 8);
            $table->decimal('qty', 20, 10);
            $table->decimal('quote_qty', 20, 8);       // total na moeda de cotação (BRL)
            $table->decimal('commission', 20, 10);
            $table->string('commission_asset', 10);    // BNB | BRL | BTC | USDT
            $table->boolean('is_maker')->default(true);
            $table->timestamp('traded_at')->index();   // momento real do fill (campo `time` da Binance)
            $table->timestamps();

            $table->unique(['symbol', 'binance_trade_id'], 'uq_bot_trades_symbol_trade');
            $table->index(['symbol', 'traded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_trades');
    }
};
