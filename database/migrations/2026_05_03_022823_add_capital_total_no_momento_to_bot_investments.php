<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bot_investments', function (Blueprint $table) {
            $table->decimal('capital_total_no_momento', 20, 8)->nullable();
        });
    }

    public function down()
    {
        Schema::table('bot_investments', function (Blueprint $table) {
            $table->dropColumn('capital_total_no_momento');
        });
    }

};
