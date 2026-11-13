<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Depósito condicional: PIX pago + gatilho_preco = o valor fica retido (sem
 * cotas) até o BTC CAIR até o gatilho; aí o ciclo credita as cotas e marca
 * convertido_em. Null em ambos = fluxo normal de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->decimal('gatilho_preco', 14, 2)->nullable()->after('btc_price');
            $table->timestamp('convertido_em')->nullable()->after('gatilho_preco');
        });
    }

    public function down(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->dropColumn(['gatilho_preco', 'convertido_em']);
        });
    }
};
