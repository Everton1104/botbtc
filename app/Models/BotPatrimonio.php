<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotPatrimonio extends Model
{
    protected $table = 'bot_patrimonio';

    protected $fillable = [
        'dia',
        'brl_livre',
        'brl_bloqueado',
        'btc_qty',
        'btc_price',
        'bnb_qty',
        'bnb_price',
        'total',
    ];

    protected $casts = [
        'dia'            => 'date',
        'brl_livre'      => 'float',
        'brl_bloqueado'  => 'float',
        'btc_qty'        => 'float',
        'btc_price'      => 'float',
        'bnb_qty'        => 'float',
        'bnb_price'      => 'float',
        'total'          => 'float',
    ];
}
