<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_state', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('id_user');

            // Preço usado como referência para o próximo salto
            $table->decimal('preco_referencia', 18, 8)->default(0);

            // "up", "down" ou null
            $table->string('direcao_atual')->nullable();

            // Contadores progressivos
            $table->integer('contador_subidas')->default(0);
            $table->integer('contador_quedas')->default(0);

            // Valor do salto configurável (ex: 1000, 3000, 5000)
            $table->integer('salto')->default(1000);

            $table->string('order_id_compra')->nullable();
            $table->string('order_id_venda')->nullable();

            $table->string('ativo')->nullable();


            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_state');
    }
    
};
