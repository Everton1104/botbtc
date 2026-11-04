<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alertas de preço do BTC criados pelo admin no app ("avisa quando o BTC
 * chegar a X"). O ciclo do bot (≤1 min) confere os pendentes contra o
 * ticker público e dispara push único — disparado_em marca o estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btc_price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('alvo', 14, 2);               // preço-alvo em BRL
            $table->string('direcao', 10);                // acima | abaixo
            $table->timestamp('disparado_em')->nullable(); // null = aguardando
            $table->decimal('preco_disparo', 14, 2)->nullable();
            $table->timestamps();

            $table->index('disparado_em'); // varre só os pendentes do ciclo
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btc_price_alerts');
    }
};
