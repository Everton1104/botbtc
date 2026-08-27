<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Depósitos e saques DIRETOS na Binance (fora do fluxo de investidor —
        // aqueles vivem em bot_withdrawal_requests/pix_payments). Movimentação
        // do admin na conta: withdraw de BTC/BRL/BNB, depósito direto etc.
        // Não gera myTrade, então sem esta tabela o FIFO do PnlService cria
        // estoque fantasma (visto em auditoria 27/08/2026: 2 withdraws de BTC
        // somando 0,0131 BTC inflavam o btc_aberto do relatório).
        // Sincronizada de /sapi/v1/capital/{withdraw/history,deposit/hisrec}
        // (API expõe janela máx. de 90 dias por chamada — histórico mais antigo
        // entra como lançamento manual, source='manual').
        Schema::create('bot_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('transfer_type', 10);          // deposit | withdraw
            $table->string('coin', 10);                   // BTC | BRL | BNB
            $table->decimal('amount', 20, 10);            // quantia movimentada
            $table->decimal('fee', 20, 10)->default(0);   // taxa de rede/sistema
            $table->string('network', 20)->nullable();
            $table->string('address', 120)->nullable();   // destino (withdraw cripto)
            $table->string('txid', 120)->nullable();      // id interno da Binance ou hash na rede
            $table->tinyInteger('status')->nullable();    // status Binance (6 = completed)
            $table->string('source', 10)->default('binance'); // binance | manual
            $table->timestamp('applied_at')->index();     // quando movimentou
            $table->timestamps();

            // Dedup do sync: id interno (withdraw) ou txid na rede (deposit);
            // manual sem txid entra com NULL (múltiplos NULLs passam no unique).
            $table->unique(['transfer_type', 'coin', 'txid'], 'uq_bot_transfers_tipo_moeda_txid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_transfers');
    }
};
