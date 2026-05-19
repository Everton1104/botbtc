<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->boolean('registrado')->default(false)->after('pago_em');
        });
    }

    public function down(): void
    {
        Schema::table('pix_payments', function (Blueprint $table) {
            $table->dropColumn('registrado');
        });
    }
};
