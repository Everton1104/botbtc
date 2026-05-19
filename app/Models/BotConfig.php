<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotConfig extends Model
{
    protected $table = 'bot_config';

    protected $fillable = ['p1', 'p2', 'p3', 'p4', 'salto'];

    /** Cache por processo — evita hits repetidos no scheduler (múltiplos bots por minuto). */
    private static ?self $cache = null;

    /**
     * Retorna a configuração ativa (sempre primeira linha).
     * Cache é invalidado automaticamente ao salvar.
     */
    public static function atual(): self
    {
        if (static::$cache === null) {
            static::$cache = self::firstOrCreate([], [
                'p1'    => 0.25,
                'p2'    => 0.15,
                'p3'    => 0.10,
                'p4'    => 0.05,
                'salto' => 3000,
            ]);
        }

        return static::$cache;
    }

    /** Limpa o cache ao salvar para que a próxima leitura pegue o valor atualizado. */
    protected static function boot(): void
    {
        parent::boot();
        static::saved(fn() => static::$cache = null);
    }
}
