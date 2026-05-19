<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotWithdrawalRequest extends Model
{
    protected $table = 'bot_withdrawal_requests';

    protected $fillable = [
        'user_id',
        'valor_bruto',
        'valor_liquido',
        'cotas',
        'cotas_taxa',
        'preco_por_cota',
        'patrimonio_bot',
        'status',
        'confirmado_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
