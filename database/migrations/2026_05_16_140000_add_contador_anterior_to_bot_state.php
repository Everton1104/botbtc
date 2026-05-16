<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->integer('contador_anterior')->default(0)->after('contador_quedas');
        });
    }

    public function down(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn('contador_anterior');
        });
    }
};
