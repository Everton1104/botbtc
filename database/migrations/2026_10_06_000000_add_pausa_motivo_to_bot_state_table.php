<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo da pausa do bot ('saque' | 'deposito') — o app mostra countdown
 * de 3 min quando a pausa veio de um saque confirmado, e "libere quando a
 * transferência cair" quando veio de um depósito manual (pausa longa até o
 * admin soltar). Nullable: pausas antigas/normais caem no default 'saque'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->string('pausa_motivo', 20)->nullable()->after('pausado_ate');
        });
    }

    public function down(): void
    {
        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn('pausa_motivo');
        });
    }
};
