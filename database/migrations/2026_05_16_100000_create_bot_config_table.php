<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_config', function (Blueprint $table) {
            $table->id();
            $table->float('p1')->default(0.25); // 1º salto
            $table->float('p2')->default(0.15); // 2º salto
            $table->float('p3')->default(0.10); // 3º salto
            $table->float('p4')->default(0.05); // 4º salto
            $table->integer('salto')->default(3000); // salto em BRL
            $table->timestamps();
        });

        // Inserir configuração padrão
        DB::table('bot_config')->insert([
            'p1'    => 0.25,
            'p2'    => 0.15,
            'p3'    => 0.10,
            'p4'    => 0.05,
            'salto' => 3000,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_config');
    }
};
