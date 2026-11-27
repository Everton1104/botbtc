<?php

namespace App\Console\Commands;

use App\Services\PnlService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Diário do bot — janela de avaliação dez/2026.
 *
 * Registra em storage/app/diario-bot.md, para cada dia FECHADO, o resumo
 * de operação: PnL do dia, regime de mercado (tese: o Spread Fixo brilha
 * em lateral e perde em tendência), posição de fechamento e eventos
 * (saques, aportes, transferências, mudanças de estratégia).
 *
 * Idempotente: dias já gravados (seção "## YYYY-MM-DD") são pulados, e o
 * backfill é automático — se ficar dias sem rodar, ao voltar preenche as
 * lacunas. Só o comando: `php artisan bot:diario`.
 */
class BotDiario extends Command
{
    protected $signature = 'bot:diario {--desde=} {--dias=60} {--hoje : imprime a leitura parcial do dia corrente (não grava no diário)}';

    protected $description = 'Grava no diário (storage/app/diario-bot.md) o resumo de cada dia fechado do bot: PnL, regime de mercado, posição e eventos. Idempotente.';

    private const SEMANA = [
        'Sunday' => 'dom', 'Monday' => 'seg', 'Tuesday' => 'ter', 'Wednesday' => 'qua',
        'Thursday' => 'qui', 'Friday' => 'sex', 'Saturday' => 'sáb',
    ];

    public function handle(): int
    {
        // Leitura parcial do dia corrente (para os check-ins das 12h30 e 22h)
        if ($this->option('hoje')) {
            $this->line($this->leituraHoje());
            return self::SUCCESS;
        }

        $arquivo = storage_path('app/diario-bot.md');

        // Dias já gravados (idempotência)
        $gravados = [];
        if (file_exists($arquivo)) {
            preg_match_all('/^## (\d{4}-\d{2}-\d{2})/m', file_get_contents($arquivo), $m);
            $gravados = array_flip($m[1] ?? []);
        }

        // Série diária de PnL (FIFO do PnlService, mesma fonte do painel)
        $serie = collect(app(PnlService::class)->rolling((int) $this->option('dias'))['serie'] ?? [])
            ->keyBy('key');

        // Janela: desde a virada pro Spread Fixo (19/11/2026) até ontem
        $inicio = $this->option('desde')
            ? Carbon::parse($this->option('desde'))
            : Carbon::parse('2026-11-19');
        $fim = Carbon::yesterday();

        $novas = 0;
        $blocos = [];
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $key = $d->format('Y-m-d');
            if (isset($gravados[$key])) {
                continue;
            }
            $blocos[] = $this->entradaDoDia($key, $serie->get($key));
            $novas++;
        }

        if ($novas === 0) {
            $this->info('Diário já em dia (última janela: '.$inicio->format('d/m/Y').' a '.$fim->format('d/m/Y').').');
            return self::SUCCESS;
        }

        // Cabeçalho na primeira gravação
        if (! file_exists($arquivo)) {
            file_put_contents($arquivo,
                "# Diário do bot — janela de avaliação até 31/12/2026\n\n".
                "> Tese: o Spread Fixo (R\$ 2.000 ±0,5%) **lucra em mercado lateral** e **perde em tendência**\n".
                "> (queda escada derruba o estoque; rebase realiza perda). Aportes de BRL esperados até o fim\n".
                "> de dezembro. Registro diário para validar — gerado por `php artisan bot:diario`.\n\n");
        }

        file_put_contents($arquivo, implode("\n", $blocos)."\n", FILE_APPEND);
        $this->info($novas.' dia(s) registrado(s) em '.$arquivo);
        return self::SUCCESS;
    }

    /**
     * Leitura "ao vivo" do dia corrente — não grava no diário (o dia
     * ainda não fechou), serve para os horários em que o usuário
     * confere o bot e decide se toma alguma ação.
     */
    private function leituraHoje(): string
    {
        $key = now()->format('Y-m-d');
        $d = app(PnlService::class)->decomposicao(1);
        $trades = DB::table('bot_trades')->whereDate('traded_at', $key)->orderBy('traded_at')->get();

        $pIni = (float) ($d['p_ini'] ?? 0);
        $pAgora = $trades->last() ? (float) $trades->last()->price : (float) ($d['p_fim'] ?? 0);
        $prices = $trades->pluck('price')->map(fn ($p) => (float) $p);

        $varPct = $pIni > 0 ? ($pAgora - $pIni) / $pIni * 100 : null;
        $ampPct = $prices->count() >= 2 ? ($prices->max() - $prices->min()) / $prices->min() * 100 : null;

        $regime = 'sem operação ainda';
        if ($varPct !== null) {
            if (abs($varPct) < 1.0) {
                $regime = ($ampPct !== null && $ampPct >= 1.5) ? 'lateral largo' : 'lateral';
            } elseif ($varPct > 0) {
                $regime = 'tendência de alta';
            } else {
                $regime = 'tendência de baixa';
            }
        }

        $state = DB::table('bot_state')->first();
        $btcAberto = (float) ($d['btc_aberto'] ?? 0);
        $pm = (float) ($d['pm_aberto'] ?? 0);

        $linhas = [];
        $linhas[] = sprintf('LEITURA %s %s — %s (dia NÃO fechado)',
            date('d/m/Y'), date('H:i'), $regime);
        if ($pIni > 0 && $pAgora > 0) {
            $linhas[] = sprintf('BTC %s -> %s (%s%+.2f%% no dia%s)',
                number_format($pIni, 0, ',', '.'), number_format($pAgora, 0, ',', '.'),
                '', $varPct,
                $ampPct !== null ? sprintf(', amplitude %.2f%%', $ampPct) : '');
        }
        $linhas[] = sprintf('PnL hoje: %s (grid %s / oscilação %s) · %d fills · vol R$ %s · fees R$ %s',
            $this->real((float) ($d['total'] ?? 0)),
            $this->real((float) ($d['grid'] ?? 0)),
            $this->real((float) ($d['oscilacao'] ?? 0)),
            (int) ($d['vendas'] ?? 0) + (int) ($d['compras'] ?? 0),
            number_format((float) ($d['volume'] ?? 0), 2, ',', '.'),
            number_format((float) ($d['fees_brl'] ?? 0), 2, ',', '.')
        );
        $patHoje = DB::table('bot_patrimonio')->where('dia', $key)->first();
        $linhas[] = sprintf('Posição: %s BTC @ PM %s (%s vs. preço) · BRL madrugada: R$ %s · patrimônio madrugada: R$ %s',
            rtrim(rtrim(number_format($btcAberto, 8, ',', '.'), '0'), ','),
            number_format($pm, 0, ',', '.'),
            ($pm > 0 && $pAgora > 0) ? sprintf('%+.2f%%', ($pAgora - $pm) / $pm * 100) : '—',
            $patHoje ? number_format((float) $patHoje->brl_livre + (float) $patHoje->brl_bloqueado, 2, ',', '.') : '—',
            $patHoje ? number_format((float) $patHoje->total, 2, ',', '.') : '—'
        );
        if ($state) {
            $status = ((int) $state->ativo === 1 ? 'ativo' : 'PARADO').
                ((int) ($state->pausado_manual ?? 0) === 1 ? ' (pausa manual)' : '');
            $linhas[] = sprintf('Bot: %s · par recriado %s · modo_subida=%s',
                $status, $state->par_criado_em ?? '—', $state->modo_subida ?? 0);
        }
        foreach ($this->eventosDoDia($key) as $ev) {
            $linhas[] = '⚑ '.$ev;
        }

        return implode("\n", $linhas);
    }

    private function entradaDoDia(string $key, ?array $dado): string
    {
        $d = Carbon::parse($key);
        $dia = $key.' ('.self::SEMANA[$d->format('l')].')';

        $trades = DB::table('bot_trades')->whereDate('traded_at', $key)->get();
        $pat = DB::table('bot_patrimonio')->where('dia', $key)->first();
        $patAnte = DB::table('bot_patrimonio')->where('dia', Carbon::parse($key)->subDay()->format('Y-m-d'))->first();

        // Variação do dia: preço do snapshot vs. snapshot de ontem (fallback: fills)
        $pFim = $pat?->btc_price ? (float) $pat->btc_price : ($trades->last()?->price ? (float) $trades->last()->price : null);
        $pIni = $patAnte?->btc_price ? (float) $patAnte->btc_price : ($trades->first()?->price ? (float) $trades->first()->price : null);

        $varPct = null;
        $ampPct = null;
        if ($pFim && $pIni) {
            $varPct = ($pFim - $pIni) / $pIni * 100;
        }
        $prices = $trades->pluck('price')->map(fn ($p) => (float) $p)->filter();
        if ($prices->count() >= 2) {
            $ampPct = ($prices->max() - $prices->min()) / $prices->min() * 100;
        }

        // Regime — a tese do usuário em classificação objetiva
        $regime = 'sem operação';
        if ($varPct !== null) {
            if (abs($varPct) < 1.0) {
                $regime = ($ampPct !== null && $ampPct >= 1.5) ? 'lateral largo' : 'lateral';
            } elseif ($varPct > 0) {
                $regime = 'tendência de alta';
            } else {
                $regime = 'tendência de baixa';
            }
        }

        $linhas = [];
        $linhas[] = '## '.$dia.' — '.$regime;

        if ($pFim && $pIni) {
            $linhas[] = sprintf('- BTC %s → %s (%s%+.2f%% no dia%s)',
                number_format($pIni, 0, ',', '.'),
                number_format($pFim, 0, ',', '.'),
                '',
                $varPct,
                $ampPct !== null ? sprintf(', amplitude intradia %.2f%%', $ampPct) : ''
            );
        } else {
            $linhas[] = '- BTC: sem cotação de fechamento';
        }

        if ($dado) {
            $linhas[] = sprintf('- PnL **%s** · %d fills · vol R$ %s · fees R$ %s',
                $this->real((float) $dado['pnl']),
                $dado['trades'],
                number_format((float) $dado['volume'], 2, ',', '.'),
                number_format((float) $dado['fees'], 2, ',', '.')
            );
        } else {
            $linhas[] = '- PnL: 0,00 (sem fills)';
        }

        if ($pat) {
            $linhas[] = sprintf('- Fecho: %s BTC · BRL R$ %s · BNB %s · **patrimônio R$ %s**',
                rtrim(rtrim(number_format((float) $pat->btc_qty, 8, ',', '.'), '0'), ','),
                number_format((float) $pat->brl_livre + (float) $pat->brl_bloqueado, 2, ',', '.'),
                rtrim(rtrim(number_format((float) $pat->bnb_qty, 4, ',', '.'), '0'), ','),
                number_format((float) $pat->total, 2, ',', '.')
            );
        }

        foreach ($this->eventosDoDia($key) as $ev) {
            $linhas[] = '- ⚑ '.$ev;
        }

        return implode("\n", $linhas)."\n";
    }

    /** @return string[] */
    private function eventosDoDia(string $key): array
    {
        $ev = [];

        foreach (DB::table('bot_estrategia_historico')->whereDate('iniciado_em', $key)->get() as $h) {
            $ev[] = sprintf('estratégia → %s (gatilho %s%%, BTC %s)', $h->estrategia, $h->gatilho_pct, number_format((float) $h->preco_btc, 0, ',', '.'));
        }

        foreach (DB::table('bot_withdrawal_requests')->whereDate('created_at', $key)->get() as $w) {
            $ev[] = sprintf('saque R$ %s (user %s, %s)', number_format((float) $w->valor_liquido, 2, ',', '.'), $w->user_id, $w->status);
        }

        foreach (DB::table('bot_investments')->whereDate('created_at', $key)->get() as $i) {
            $ev[] = sprintf('aporte R$ %s (user %s → %s cotas)', number_format((float) $i->investimento_inicial, 2, ',', '.'), $i->user_id, rtrim(rtrim(number_format((float) $i->cotas, 2, ',', '.'), '0'), ','));
        }

        foreach (DB::table('bot_transfers')->whereDate('applied_at', $key)->get() as $t) {
            $ev[] = sprintf('transfer %s de %s %s (%s)', $t->transfer_type, $t->amount, $t->coin, $t->source);
        }

        return $ev;
    }

    private function real(float $v): string
    {
        return ($v >= 0 ? '+' : '').number_format($v, 2, ',', '.');
    }
}
