<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta de preço do BTC do admin: "me avisa quando o BTC chegar a X".
 *
 * Criado pelo app (endpoint admin), conferido a cada ciclo do bot contra o
 * ticker público. Quando o preço cruza o alvo na direção pedida, marca
 * disparado_em/preco_disparo e manda UM push — depois disso o alerta fica
 * no histórico até ser apagado (não repete).
 */
class BtcPriceAlert extends Model
{
    public const DIRECAO_ACIMA  = 'acima';
    public const DIRECAO_ABAIXO = 'abaixo';

    protected $fillable = ['user_id', 'alvo', 'direcao', 'disparado_em', 'preco_disparo'];

    protected $casts = [
        'alvo'          => 'float',
        'disparado_em'  => 'datetime',
        'preco_disparo' => 'float',
    ];

    /** Só os que ainda não dispararam — é a fila que o ciclo varre. */
    public function scopePendentes($query)
    {
        return $query->whereNull('disparado_em');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** O preço atual já satisfez a condição deste alerta? */
    public function bateu(float $preco): bool
    {
        return $this->direcao === self::DIRECAO_ACIMA
            ? $preco >= $this->alvo
            : $preco <= $this->alvo;
    }
}
