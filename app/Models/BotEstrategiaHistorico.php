<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Era de uma estratégia do grid ('atr' | 'fixo'). Uma linha vigente
 * (encerrado_em null) por vez — a troca encerra a atual e abre a nova.
 * Base da comparação de performance entre os modos (janela das eras ×
 * bot_trades × FIFO do PnlService).
 */
class BotEstrategiaHistorico extends Model
{
    protected $table = 'bot_estrategia_historico';

    protected $fillable = [
        'estrategia',
        'gatilho_pct',
        'iniciado_em',
        'encerrado_em',
        'preco_btc',
        'patrimonio',
    ];

    protected $casts = [
        'iniciado_em'   => 'datetime',
        'encerrado_em'  => 'datetime',
        'gatilho_pct'   => 'float',
        'preco_btc'     => 'float',
        'patrimonio'    => 'float',
    ];

    /** Era vigente (ou null se nenhuma troca desde o início do bot). */
    public static function vigente(): ?self
    {
        return self::whereNull('encerrado_em')->orderByDesc('id')->first();
    }
}
