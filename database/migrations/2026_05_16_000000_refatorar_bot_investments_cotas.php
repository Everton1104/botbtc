<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_investments', function (Blueprint $table) {
            foreach (['patrimonio_inicial', 'proporcao', 'lucro_atual'] as $col) {
                if (Schema::hasColumn('bot_investments', $col)) {
                    $table->dropColumn($col);
                }
            }

            if (!Schema::hasColumn('bot_investments', 'cotas')) {
                $table->decimal('cotas', 20, 8)->default(0)->after('investimento_inicial');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bot_investments', function (Blueprint $table) {
            if (Schema::hasColumn('bot_investments', 'cotas')) {
                $table->dropColumn('cotas');
            }
            $table->decimal('patrimonio_inicial', 20, 8)->nullable();
            $table->decimal('proporcao', 18, 10)->nullable();
            $table->decimal('lucro_atual', 20, 8)->default(0);
        });
    }
};
