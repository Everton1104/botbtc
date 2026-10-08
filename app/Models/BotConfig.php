<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotConfig extends Model
{
    protected $table = 'bot_config';

    protected $fillable = [
        'salto',
        'nivel1', 'nivel2', 'nivel3', 'nivel4', 'nivel5', 'nivel6', 'nivel7',
        'nivel_final',
        'allin_threshold',
        'min_notional', 'piso_ordem_pct',
        // Otimização M1-M9 (defaults nas migrations)
        'target_btc_pct', 'target_brl_pct', 'limite_alerta_pct', 'limite_bloqueio_pct',
        'btc_minimo_tendencia_alta', 'brl_minimo_tendencia_baixa', 'guard_meta_pct',
        'modo_subida_auto_habilitado', 'spread_minimo_pct', 'taxa_total_pct',
        'camadas_atr_habilitado', 'rsi_maximo_compra', 'rsi_minimo_venda',
        'adapt_histerese_pct', 'adapt_cooldown_min', 'adapt_persistencia_min',
        'patrimonio_inicial', 'btc_inicial', 'brl_inicial', 'base_iniciada_em',
    ];

    protected $casts = [
        'nivel1' => 'float', 'nivel2' => 'float', 'nivel3' => 'float',
        'nivel4' => 'float', 'nivel5' => 'float', 'nivel6' => 'float',
        'nivel7' => 'float', 'nivel_final' => 'float', 'allin_threshold' => 'integer',
        'min_notional' => 'float', 'piso_ordem_pct' => 'float',
        'target_btc_pct' => 'float', 'target_brl_pct' => 'float',
        'limite_alerta_pct' => 'float', 'limite_bloqueio_pct' => 'float',
        'btc_minimo_tendencia_alta' => 'float', 'brl_minimo_tendencia_baixa' => 'float',
        'guard_meta_pct' => 'float',
        'modo_subida_auto_habilitado' => 'boolean',
        'spread_minimo_pct' => 'float', 'taxa_total_pct' => 'float',
        'camadas_atr_habilitado' => 'boolean',
        'rsi_maximo_compra' => 'float', 'rsi_minimo_venda' => 'float',
        'adapt_histerese_pct' => 'float',
        'adapt_cooldown_min' => 'integer', 'adapt_persistencia_min' => 'integer',
        'patrimonio_inicial' => 'float', 'btc_inicial' => 'float', 'brl_inicial' => 'float',
        'base_iniciada_em' => 'datetime',
    ];

    private static ?self $cache = null;

    public static function atual(): self
    {
        if (static::$cache === null) {
            static::$cache = self::firstOrCreate([], [
                'salto'           => 0,
                'nivel1'          => 0.85,
                'nivel2'          => 0.60,
                'nivel3'          => 0.35,
                'nivel4'          => 0.18,
                'nivel5'          => 0.10,
                'nivel6'          => 0.06,
                'nivel7'          => 0.03,
                'nivel_final'     => 0.08,
                'allin_threshold' => 15,
                'min_notional'    => 50.0,
                'piso_ordem_pct'  => 3.0,
                // M1-M9 — mesmos defaults da migration
                'target_btc_pct'  => 50.0,
                'target_brl_pct'  => 50.0,
                'limite_alerta_pct'    => 70.0,
                'limite_bloqueio_pct'  => 80.0,
                'btc_minimo_tendencia_alta'  => 40.0,
                'brl_minimo_tendencia_baixa' => 40.0,
                'guard_meta_pct'             => 80.0,
                'modo_subida_auto_habilitado' => true,
                'spread_minimo_pct' => 0.90,
                'taxa_total_pct'    => 0.15,
                'camadas_atr_habilitado' => true,
                'rsi_maximo_compra' => 80.0,
                'rsi_minimo_venda'  => 20.0,
                'adapt_histerese_pct'    => 30.0,
                'adapt_cooldown_min'     => 240,
                'adapt_persistencia_min' => 30,
            ]);
        }

        return static::$cache;
    }

    /** Retorna os 7 níveis como array indexado [1..7]. */
    public function niveis(): array
    {
        return [
            1 => $this->nivel1,
            2 => $this->nivel2,
            3 => $this->nivel3,
            4 => $this->nivel4,
            5 => $this->nivel5,
            6 => $this->nivel6,
            7 => $this->nivel7,
        ];
    }

    /** Limpa o cache ao salvar para que a próxima leitura pegue o valor atualizado. */
    protected static function boot(): void
    {
        parent::boot();
        static::saved(fn() => static::$cache = null);
    }
}
