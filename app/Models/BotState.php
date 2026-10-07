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
        'contador_anterior',
        'salto',
        'order_id_compra',
        'order_id_venda',
        'ativo',
        'pausado_ate',
        'pausa_motivo',
        'modo_subida',
        'pausado_manual',
        'modo_subida_auto',
        'par_criado_em',
    ];

    protected $casts = [
        'contador_subidas'  => 'integer',
        'contador_quedas'   => 'integer',
        'contador_anterior' => 'integer',
        'salto'             => 'integer',
        'preco_referencia'  => 'float',
        'ativo'             => 'boolean',
        'pausado_ate'       => 'datetime',
        'modo_subida'       => 'boolean',
        'pausado_manual'    => 'boolean',
        'modo_subida_auto'  => 'boolean',
        'par_criado_em'     => 'datetime',
    ];
}
