<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotConfig extends Model
{
    protected $table = 'bot_config';

    protected $fillable = [
        'salto',
        'nivel1', 'nivel2', 'nivel3', 'nivel4', 'nivel5', 'nivel6', 'nivel7',
        'allin_threshold',
    ];

    protected $casts = [
        'nivel1' => 'float', 'nivel2' => 'float', 'nivel3' => 'float',
        'nivel4' => 'float', 'nivel5' => 'float', 'nivel6' => 'float',
        'nivel7' => 'float', 'allin_threshold' => 'integer',
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
                'allin_threshold' => 15,
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
