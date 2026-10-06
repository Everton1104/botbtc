<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Acrescenta 'estornado' ao enum de status dos depósitos PIX.
     *
     * O fluxo de estorno (DepositoService::estornar, espelhado do site)
     * grava status='estornado' — valor que o enum original não previa, o
     * que falharia no MySQL em modo estrito. Aditivo: os 4 valores antigos
     * continuam idênticos, nenhum dado existente muda.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE pix_payments ".
            "MODIFY status ENUM('pendente','pago','expirado','cancelado','estornado') ".
            "NOT NULL DEFAULT 'pendente'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE pix_payments ".
            "MODIFY status ENUM('pendente','pago','expirado','cancelado') ".
            "NOT NULL DEFAULT 'pendente'"
        );
    }
};
