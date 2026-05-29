<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->decimal('nivel1', 4, 2)->default(0.85)->after('salto');
            $table->decimal('nivel2', 4, 2)->default(0.60)->after('nivel1');
            $table->decimal('nivel3', 4, 2)->default(0.35)->after('nivel2');
            $table->decimal('nivel4', 4, 2)->default(0.18)->after('nivel3');
            $table->decimal('nivel5', 4, 2)->default(0.10)->after('nivel4');
            $table->decimal('nivel6', 4, 2)->default(0.06)->after('nivel5');
            $table->decimal('nivel7', 4, 2)->default(0.03)->after('nivel6');
            $table->integer('allin_threshold')->default(15)->after('nivel7');
        });

        DB::table('bot_config')->update([
            'nivel1' => 0.85,
            'nivel2' => 0.60,
            'nivel3' => 0.35,
            'nivel4' => 0.18,
            'nivel5' => 0.10,
            'nivel6' => 0.06,
            'nivel7' => 0.03,
            'allin_threshold' => 15,
        ]);

        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn(['p1', 'p2', 'p3', 'p4']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->decimal('p1', 4, 2)->default(0.70)->after('salto');
            $table->decimal('p2', 4, 2)->default(0.30)->after('p1');
            $table->decimal('p3', 4, 2)->default(0.10)->after('p2');
            $table->decimal('p4', 4, 2)->default(0.03)->after('p3');
            $table->dropColumn(['nivel1','nivel2','nivel3','nivel4','nivel5','nivel6','nivel7','allin_threshold']);
        });
    }
};
