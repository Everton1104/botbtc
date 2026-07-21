<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            // Identificadores do link de pagamento InfinitePay. Vêm apenas no
            // webhook e são lidos pelo payment_check (consultarStatus).
            $table->string('infinitepay_slug', 100)->nullable()->after('txid');
            $table->string('infinitepay_transaction', 100)->nullable()->after('infinitepay_slug');
        });
    }

    public function down(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->dropColumn(['infinitepay_slug', 'infinitepay_transaction']);
        });
    }
};
