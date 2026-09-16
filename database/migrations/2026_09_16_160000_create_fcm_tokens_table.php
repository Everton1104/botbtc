<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens FCM — o "endereço" de cada celular dentro do Firebase.
 *
 * O app, ao ligar, pede um token ao Firebase e entrega pra cá via
 * POST /api/dispositivo-token. Quando o bot opera (ou chega saque),
 * o Laravel envia push para todos os tokens desta tabela.
 *
 * O token MUDA quando: o app é reinstalado, os dados são limpos ou o
 * Firebase decide rotacionar. Por isso o upsert por token (nada de
 * duplicar) e a limpeza de tokens mortos na hora do envio (Google
 * responde 404/410 para quem não existe mais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 500)->unique(); // token FCM é longo (~160 chars)
            $table->string('dispositivo', 100)->nullable(); // apelido, ex.: "android"
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
