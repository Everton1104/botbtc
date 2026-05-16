<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Converte a coluna enum para string para suportar o status 'cancelado'
        DB::statement("ALTER TABLE bot_withdrawal_requests MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pendente'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE bot_withdrawal_requests MODIFY COLUMN status ENUM('pendente','confirmado') NOT NULL DEFAULT 'pendente'");
    }
};
