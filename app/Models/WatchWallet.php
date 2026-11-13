<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchWallet extends Model
{
    protected $fillable = ['user_id', 'rotulo', 'zpub', 'enderecos'];

    protected $casts = [
        'enderecos' => 'array',
        'estado'    => 'array',
    ];
}
