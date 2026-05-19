<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            // Cotas exatas creditadas ao admin como taxa — usadas para reverter no cancelamento
            $table->decimal('cotas_taxa', 18, 8)->default(0)->after('cotas');
        });
    }

    public function down(): void
    {
        Schema::table('bot_withdrawal_requests', function (Blueprint $table) {
            $table->dropColumn('cotas_taxa');
        });
    }
};
