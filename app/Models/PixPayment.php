<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PixPayment extends Model
{
    protected $fillable = [
        'user_id',
        'txid',
        'valor',
        'descricao',
        'status',
        'qr_code',
        'copia_e_cola',
        'expiracao',
        'pago_em',
        'registrado',
        'payload_webhook',
    ];

    protected $casts = [
        'expiracao'       => 'datetime',
        'pago_em'         => 'datetime',
        'registrado'      => 'boolean',
        'payload_webhook' => 'array',
        'valor'           => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPago(): bool
    {
        return $this->status === 'pago';
    }

    public function isExpirado(): bool
    {
        return $this->expiracao && now()->isAfter($this->expiracao);
    }
}
