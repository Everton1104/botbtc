<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            // Método + parcelas (vêm do payment_check da InfinitePay) e o valor
            // LÍQUIDO a creditar (já descontada a taxa por método: PIX = 0%,
            // cartão = tabela por parcelas). Centraliza a fee no pagamento.
            $table->string('capture_method', 20)->nullable()->after('infinitepay_transaction');
            $table->unsignedSmallInteger('installments')->default(1)->after('capture_method');
            $table->decimal('valor_liquido', 12, 2)->nullable()->after('installments');
        });
    }

    public function down(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->dropColumn(['capture_method', 'installments', 'valor_liquido']);
        });
    }
};
