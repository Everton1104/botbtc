<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_log', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->longText('msg')->nullable();
            $table->string('dep_id')->nullable();
            $table->string('business_phone_number_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_log');
    }
};
