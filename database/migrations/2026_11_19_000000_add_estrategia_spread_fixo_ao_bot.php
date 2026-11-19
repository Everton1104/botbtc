<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESTRATÉGIA "SPREAD FIXO" — grid de valor fixo com gatilho por valorização
 * (substitui o ATR quando ativada). Feature reversível: default 'atr' mantém
 * o comportamento atual; o código do grid ATR ignora as colunas novas.
 *
 *  · bot_config
 *    - estrategia_grid    'atr' (atual) | 'fixo' (nova) — o dispatcher do
 *                         BotExecutor escolhe o fluxo por aqui.
 *    - fixo_valor_ordem   valor fixo (BRL) de cada ordem (default R$ 1000).
 *    - fixo_gatilho_pct   distância % de cada perna ao centro (default 0,15):
 *                         subir 0,15% executa a SELL, cair 0,15% executa a
 *                         BUY. Ciclo completo = 2× gatilho de ganho bruto,
 *                         menos a taxa total (0,15%) = lucro líquido igual à
 *                         taxa — a regra de ouro da tese.
 *    - fixo_espera_min    minutos de espera quando falta saldo pra uma perna
 *                         antes de recriar a base no preço atual (rebase).
 *  · bot_state
 *    - fixo_espera_desde  início da janela de espera da estratégia fixa
 *                         (null = grid completo, sem espera).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->string('estrategia_grid', 10)->default('atr')->after('boost_pct');
            $table->float('fixo_valor_ordem', 12, 2)->default(1000.0)->after('estrategia_grid');
            $table->float('fixo_gatilho_pct', 6, 3)->default(0.15)->after('fixo_valor_ordem');
            $table->unsignedInteger('fixo_espera_min')->default(30)->after('fixo_gatilho_pct');
        });

        Schema::table('bot_state', function (Blueprint $table) {
            $table->timestamp('fixo_espera_desde')->nullable()->after('par_criado_em');
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn(['estrategia_grid', 'fixo_valor_ordem', 'fixo_gatilho_pct', 'fixo_espera_min']);
        });

        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn('fixo_espera_desde');
        });
    }
};
