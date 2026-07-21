<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Curva de níveis balanceada (grade "eficiente"). A versão agressiva
        // (0.85/0.60/0.35/...) despejava 85% do BTC no 1º movimento de alta,
        // esgotando a posição e deixando o bot 99% em BRL. Nova curva: modesta
        // no 1º movimento (20%) e decaimento suave — mantém a posição
        // equilibrada pra capturar spread nos dois sentidos, sem commit
        // direcional agressivo. (allin_threshold continua 12 com gate RSI 4h.)
        DB::table('bot_config')->where('id', 1)->update([
            'nivel1' => 0.20,
            'nivel2' => 0.18,
            'nivel3' => 0.16,
            'nivel4' => 0.14,
            'nivel5' => 0.12,
            'nivel6' => 0.10,
            'nivel7' => 0.08,
        ]);
    }

    public function down(): void
    {
        // Volta pra curva agressiva (estado pré-balanceamento).
        DB::table('bot_config')->where('id', 1)->update([
            'nivel1' => 0.85,
            'nivel2' => 0.60,
            'nivel3' => 0.35,
            'nivel4' => 0.18,
            'nivel5' => 0.10,
            'nivel6' => 0.06,
            'nivel7' => 0.03,
        ]);
    }
};
