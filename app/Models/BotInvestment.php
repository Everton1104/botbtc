<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotInvestment extends Model
{
    protected $fillable = [
        'user_id',
        'investimento_inicial',
        'cotas',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
