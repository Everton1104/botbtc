<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Snapshot pré-carregado da carteira (saldo/UTXOs/histórico) — o request
// devolve isto NA HORA; o scheduler atualiza em segundo plano.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('watch_wallets', function (Blueprint $t) {
            $t->json('estado')->nullable();      // último snapshot (situacao())
            $t->timestamp('atualizado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('watch_wallets', function (Blueprint $t) {
            $t->dropColumn(['estado', 'atualizado_em']);
        });
    }
};
