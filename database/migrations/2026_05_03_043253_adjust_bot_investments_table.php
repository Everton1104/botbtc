<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bot_investments', function (Blueprint $table) {

            // remover campos que não devem existir
            if (Schema::hasColumn('bot_investments', 'capital_total_no_momento')) {
                $table->dropColumn('capital_total_no_momento');
            }

            // adicionar proporção (campo essencial)
            if (!Schema::hasColumn('bot_investments', 'proporcao')) {
                $table->decimal('proporcao', 18, 10)->after('patrimonio_inicial');
            }

            // garantir que patrimonio_inicial existe
            if (!Schema::hasColumn('bot_investments', 'patrimonio_inicial')) {
                $table->decimal('patrimonio_inicial', 18, 2)->after('investimento_inicial');
            }

            // garantir que investimento_inicial existe
            if (!Schema::hasColumn('bot_investments', 'investimento_inicial')) {
                $table->decimal('investimento_inicial', 18, 2)->after('user_id');
            }
        });
    }

    public function down()
    {
        Schema::table('bot_investments', function (Blueprint $table) {
            if (Schema::hasColumn('bot_investments', 'proporcao')) {
                $table->dropColumn('proporcao');
            }
        });
    }

};
