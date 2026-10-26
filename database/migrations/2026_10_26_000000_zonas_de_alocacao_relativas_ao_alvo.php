<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alocação editável de verdade: as zonas de correção (alerta/bloqueio) e os
 * pisos de tendência forte passam a ser RELATIVOS ao alvo (target_btc_pct),
 * em vez dos limites absolutos fixos (70/80 e 40/40) que só faziam sentido
 * para um alvo 50/50 — com alvo 70/30 o próprio ponto de equilíbrio ficava
 * colado na zona de alerta e o bot lutava contra o alvo do admin.
 *
 * Aditivo e converte os valores legados na geometria equivalente (alvo 50):
 *   limite_alerta 70  → delta_alerta   20  (alerta dispara a ±20 do alvo)
 *   limite_bloqueio 80→ delta_bloqueio 30
 *   piso alta 40      → piso_alta_delta 10 (piso = alvo BTC − 10)
 *   piso baixa 40     → piso_baixa_delta 10 (piso = alvo BRL − 10)
 * Com alvo 50 os limites efetivos ficam exatamente 70/80 e pisos 40/40 —
 * nenhum comportamento muda até o admin mexer no alvo. As colunas absolutas
 * legadas ficam inertes no banco (o código não as lê mais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->float('delta_alerta_pct', 5, 2)->default(20.0)->after('limite_bloqueio_pct');
            $table->float('delta_bloqueio_pct', 5, 2)->default(30.0)->after('delta_alerta_pct');
            $table->float('piso_alta_delta_pct', 5, 2)->default(10.0)->after('delta_bloqueio_pct');
            $table->float('piso_baixa_delta_pct', 5, 2)->default(10.0)->after('piso_alta_delta_pct');
        });

        // Converte os valores absolutos atuais (se o admin os editou, a
        // intenção relativa ao alvo vigente é preservada; defaults 70/80/40/40
        // viram exatamente 20/30/10/10).
        foreach (DB::table('bot_config')->get() as $cfg) {
            $tgtBtc = (float) ($cfg->target_btc_pct ?: 50);
            $tgtBrl = 100.0 - $tgtBtc;

            DB::table('bot_config')->where('id', $cfg->id)->update([
                'delta_alerta_pct' => max(10, min(45, abs((float) $cfg->limite_alerta_pct - 50))),
                'delta_bloqueio_pct' => max(15, min(50, abs((float) $cfg->limite_bloqueio_pct - 50))),
                'piso_alta_delta_pct' => max(5, min(30, $tgtBtc - (float) $cfg->btc_minimo_tendencia_alta)),
                'piso_baixa_delta_pct' => max(5, min(30, $tgtBrl - (float) $cfg->brl_minimo_tendencia_baixa)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn(['delta_alerta_pct', 'delta_bloqueio_pct', 'piso_alta_delta_pct', 'piso_baixa_delta_pct']);
        });
    }
};
