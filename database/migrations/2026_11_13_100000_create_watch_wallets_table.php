<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Carteiras watch-only (zpub) vinculadas ao usuário — só a chave PÚBLICA:
// o servidor pode ver saldo/histórico, mas nunca mover as moedas.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('watch_wallets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('rotulo', 60);
            $t->string('zpub', 111);
            // cache das derivações: {receber: [{i, endereco}], troco: [...]}
            $t->json('enderecos');
            $t->timestamps();
            $t->unique(['user_id', 'zpub']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_wallets');
    }
};
