<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('valor_bruto',    12, 2); // valor_atual no momento do pedido
            $table->decimal('valor_liquido',  12, 2); // valor_bruto - taxa PIX
            $table->decimal('cotas',          18, 8); // cotas a queimar
            $table->decimal('preco_por_cota', 18, 8); // preço/cota no momento do pedido
            $table->decimal('patrimonio_bot', 12, 2); // patrimônio total do bot no momento
            $table->string('status')->default('pendente'); // pendente | confirmado | cancelado
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_withdrawal_requests');
    }
};
