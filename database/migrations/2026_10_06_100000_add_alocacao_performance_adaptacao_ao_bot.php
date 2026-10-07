<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otimização do bot (M1-M9):
 *  · bot_config  — alocação BTC/BRL (targets, alerta, bloqueio, pisos por
 *    tendência), modo subida automático, spread mínimo, camadas de ATR,
 *    extremos de RSI, parâmetros da adaptação do grid e a base persistida
 *    de performance (patrimônio/BTC/BRL iniciais).
 *  · bot_state   — flag do modo subida automático e idade do par atual
 *    (cooldown da adaptação do grid ao regime).
 *
 * Tudo aditivo, com defaults = comportamento novo recomendado. O código
 * antigo ignora as colunas novas (deploy pode ir em qualquer ordem).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            // M1 — controle patrimonial BTC/BRL
            $table->float('target_btc_pct', 5, 2)->default(50.0)->after('min_notional');
            $table->float('target_brl_pct', 5, 2)->default(50.0)->after('target_btc_pct');
            $table->float('limite_alerta_pct', 5, 2)->default(70.0)->after('target_brl_pct');
            $table->float('limite_bloqueio_pct', 5, 2)->default(80.0)->after('limite_alerta_pct');

            // M2/M3 — pisos por tendência forte (alta forte segura BTC, baixa forte segura BRL)
            $table->float('btc_minimo_tendencia_alta', 5, 2)->default(40.0)->after('limite_bloqueio_pct');
            $table->float('brl_minimo_tendencia_baixa', 5, 2)->default(40.0)->after('btc_minimo_tendencia_alta');

            // M4 — modo "preparar subida" automático
            $table->boolean('modo_subida_auto_habilitado')->default(true)->after('brl_minimo_tendencia_baixa');

            // M5 — spread mínimo do grid (taxa + lucro líquido mínimo)
            $table->float('spread_minimo_pct', 5, 3)->default(0.90)->after('modo_subida_auto_habilitado');
            $table->float('taxa_total_pct', 5, 3)->default(0.15)->after('spread_minimo_pct');

            // M6 — camadas de salto por ATR
            $table->boolean('camadas_atr_habilitado')->default(true)->after('taxa_total_pct');

            // M7 — extremos de RSI 1h (bloqueio de compra/venda)
            $table->float('rsi_maximo_compra', 5, 1)->default(80.0)->after('camadas_atr_habilitado');
            $table->float('rsi_minimo_venda', 5, 1)->default(20.0)->after('rsi_maximo_compra');

            // M9 — adaptação do grid ao regime (reposiciona pernas pelo ATR)
            $table->float('adapt_histerese_pct', 5, 1)->default(30.0)->after('rsi_minimo_venda');
            $table->unsignedInteger('adapt_cooldown_min')->default(240)->after('adapt_histerese_pct');
            $table->unsignedInteger('adapt_persistencia_min')->default(30)->after('adapt_cooldown_min');

            // M8 — base de performance persistida (fotografada na 1ª execução)
            $table->double('patrimonio_inicial', 16, 2)->nullable()->after('adapt_persistencia_min');
            $table->double('btc_inicial', 16, 8)->nullable()->after('patrimonio_inicial');
            $table->double('brl_inicial', 16, 2)->nullable()->after('btc_inicial');
            $table->timestamp('base_iniciada_em')->nullable()->after('brl_inicial');
        });

        Schema::table('bot_state', function (Blueprint $table) {
            // M4 — modo subida automático (o manual, modo_subida, segue igual)
            $table->boolean('modo_subida_auto')->default(false)->after('modo_subida');
            // M9 — quando o par atual foi criado (cooldown da adaptação do grid)
            $table->timestamp('par_criado_em')->nullable()->after('modo_subida_auto');
        });
    }

    public function down(): void
    {
        Schema::table('bot_config', function (Blueprint $table) {
            $table->dropColumn([
                'target_btc_pct', 'target_brl_pct', 'limite_alerta_pct', 'limite_bloqueio_pct',
                'btc_minimo_tendencia_alta', 'brl_minimo_tendencia_baixa',
                'modo_subida_auto_habilitado', 'spread_minimo_pct', 'taxa_total_pct',
                'camadas_atr_habilitado', 'rsi_maximo_compra', 'rsi_minimo_venda',
                'adapt_histerese_pct', 'adapt_cooldown_min', 'adapt_persistencia_min',
                'patrimonio_inicial', 'btc_inicial', 'brl_inicial', 'base_iniciada_em',
            ]);
        });

        Schema::table('bot_state', function (Blueprint $table) {
            $table->dropColumn(['modo_subida_auto', 'par_criado_em']);
        });
    }
};
