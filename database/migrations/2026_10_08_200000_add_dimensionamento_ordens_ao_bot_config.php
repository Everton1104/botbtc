<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensionamento de ordens — fim do "grid morto" em sequências longas:
 *  · nivel_final    — percentual usado a partir do 8º salto na mesma direção
 *    (antes: 1% fixo, o que com fatores ~0.5 gerava ordens de ~R$39 num saldo
 *    de R$7.8k — abaixo do min_notional, virava dust de R$50 e o grid parava
 *    de operar de verdade);
 *  · piso_ordem_pct — piso de tamanho por ordem (% do saldo do lado): a ordem
 *    que vai entrar nunca é menor que isso, por mais que nível × fatores
 *    encolham;
 *  · guard_meta_pct — guard de meta do rebalanceamento: com o lado de risco
 *    abaixo de X% do alvo (default 80% da meta), o freio da tendência forte
 *    (×0.5) é suavizado para ×0.8 — recomprar barato em baixa forte é o que
 *    o rebalanceamento pede, não o contrário.
 *
 * Aditivo, defaults = comportamento novo recomendado. Código novo tolera
 * coluna ausente (fallback embutido), deploy pode ir em qualquer ordem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->float('nivel_final', 5, 2)->default(0.08)->after('nivel7');
            $table->float('piso_ordem_pct', 5, 2)->default(3.0)->after('min_notional');
            $table->float('guard_meta_pct', 5, 2)->default(80.0)->after('brl_minimo_tendencia_baixa');
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn(['nivel_final', 'piso_ordem_pct', 'guard_meta_pct']);
        });
    }
};
