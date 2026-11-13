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
        'btc_price',
        'registrado',
        'payload_webhook',
        'infinitepay_slug',
        'infinitepay_transaction',
        'capture_method',
        'installments',
        'valor_liquido',
        'gatilho_preco',
        'convertido_em',
        'transferido_em',
        'aviso_gatilho_em',
    ];

    protected $casts = [
        'expiracao'       => 'datetime',
        'pago_em'         => 'datetime',
        'registrado'      => 'boolean',
        'payload_webhook' => 'array',
        'valor'           => 'decimal:2',
        'btc_price'       => 'decimal:2',
        'installments'    => 'integer',
        'valor_liquido'   => 'decimal:2',
        'gatilho_preco'   => 'decimal:2',
        'convertido_em'   => 'datetime',
        'transferido_em'  => 'datetime',
        'aviso_gatilho_em' => 'datetime',
    ];

    // withTrashed: conta removida (soft delete) continua nomeada no
    // histórico de depósitos — "histórico preservado" tem que mostrar de quem.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isPago(): bool
    {
        return $this->status === 'pago';
    }

    public function isExpirado(): bool
    {
        return $this->expiracao && now()->isAfter($this->expiracao);
    }

    /**
     * Depósito condicional retido: pago, sem cotas ainda, esperando o BTC
     * cair até gatilho_preco (só o admin cria; o ciclo converte).
     */
    public function isAguardandoGatilho(): bool
    {
        return $this->status === 'pago'
            && !$this->registrado
            && $this->gatilho_preco !== null
            && $this->convertido_em === null;
    }

    /**
     * Query base dos retidos que JÁ estão na Binance esperando o gatilho —
     * esse dinheiro existe no saldo real, mas NÃO é capital do fundo: preço
     * da cota e dimensionamento do bot o descontam até virar cotas.
     */
    public static function queryRetidosNaBinance()
    {
        return static::where('status', 'pago')
            ->where('registrado', false)
            ->whereNull('convertido_em')
            ->whereNotNull('gatilho_preco')
            ->whereNotNull('transferido_em');
    }
}
