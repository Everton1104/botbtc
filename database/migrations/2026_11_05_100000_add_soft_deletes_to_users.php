<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete de contas: "remover conta" desativa (deleted_at) em vez de
 * apagar — usuário e histórico financeiro (pix_payments, saques) ficam no
 * banco pra sempre. Todas as listas do sistema usam Eloquent, então contas
 * removidas somem delas automaticamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
