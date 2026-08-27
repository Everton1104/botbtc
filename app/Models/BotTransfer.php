<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotTransfer extends Model
{
    public const TIPO_DEPOSIT  = 'deposit';
    public const TIPO_WITHDRAW = 'withdraw';

    protected $table = 'bot_transfers';

    protected $fillable = [
        'transfer_type',
        'coin',
        'amount',
        'fee',
        'network',
        'address',
        'txid',
        'status',
        'source',
        'applied_at',
    ];

    protected $casts = [
        'amount'      => 'float',
        'fee'         => 'float',
        'status'      => 'integer',
        'applied_at'  => 'datetime',
    ];
}
