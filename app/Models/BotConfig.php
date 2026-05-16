<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotConfig extends Model
{
    protected $table = 'bot_config';

    protected $fillable = ['p1', 'p2', 'p3', 'p4', 'salto'];

    /**
     * Retorna a configuração ativa (sempre primeira linha).
     */
    public static function atual(): self
    {
        return self::firstOrCreate([], [
            'p1'    => 0.25,
            'p2'    => 0.15,
            'p3'    => 0.10,
            'p4'    => 0.05,
            'salto' => 3000,
        ]);
    }
}
