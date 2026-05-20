<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_code', 6)->nullable()->after('whatsapp');
            $table->timestamp('whatsapp_code_expires_at')->nullable()->after('whatsapp_code');
            $table->timestamp('whatsapp_verified_at')->nullable()->after('whatsapp_code_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_code', 'whatsapp_code_expires_at', 'whatsapp_verified_at']);
        });
    }
};
