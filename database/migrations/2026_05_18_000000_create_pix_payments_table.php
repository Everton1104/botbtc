<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pix_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('txid')->unique();
            $table->decimal('valor', 10, 2);
            $table->string('descricao')->nullable();
            $table->enum('status', ['pendente', 'pago', 'expirado', 'cancelado'])->default('pendente');
            $table->text('qr_code')->nullable();           // imagem PNG em base64
            $table->text('copia_e_cola')->nullable();      // string PIX copia e cola
            $table->timestamp('expiracao')->nullable();
            $table->timestamp('pago_em')->nullable();
            $table->json('payload_webhook')->nullable();   // payload raw para auditoria
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pix_payments');
    }
};
