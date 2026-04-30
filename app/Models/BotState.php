<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotState extends Model
{
    protected $table = 'bot_state';

    protected $fillable = [
        'id_user',
        'preco_referencia',
        'direcao_atual',
        'contador_subidas',
        'contador_quedas',
        'salto',
        'order_id_compra',
        'order_id_venda',
        'ativo'
    ];
}
