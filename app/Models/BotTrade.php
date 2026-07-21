<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotTrade extends Model
{
    protected $table = 'bot_trades';

    protected $fillable = [
        'binance_trade_id',
        'binance_order_id',
        'symbol',
        'side',
        'price',
        'qty',
        'quote_qty',
        'commission',
        'commission_asset',
        'is_maker',
        'traded_at',
    ];

    protected $casts = [
        'binance_trade_id' => 'integer',
        'binance_order_id' => 'integer',
        'price'            => 'float',
        'qty'              => 'float',
        'quote_qty'        => 'float',
        'commission'       => 'float',
        'is_maker'         => 'boolean',
        'traded_at'        => 'datetime',
    ];
}
