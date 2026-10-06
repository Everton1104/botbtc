<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Snapshot da chave PIX no momento do pedido de saque — se o investidor
// editar a chave depois, os pedidos antigos preservam a chave original.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            $table->string('chave_pix', 140)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('chave_pix');
        });
    }
};
