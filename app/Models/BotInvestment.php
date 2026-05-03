<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotInvestment extends Model
{
    protected $fillable = [
        'user_id',
        'investimento_inicial',
        'patrimonio_inicial',
        'proporcao',
        'lucro_atual'
    ];
}
