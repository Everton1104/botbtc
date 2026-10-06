<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chave PIX de RECEBIMENTO do investidor — cadastrada uma vez (site ou app)
// e exigida no saque: o admin paga o PIX manualmente lendo esta chave.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('chave_pix', 140)->nullable()->after('whatsapp_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chave_pix');
        });
    }
};
