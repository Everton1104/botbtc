<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\BotPatrimonio;
use App\Models\BotState;
use App\Models\BotTransfer;
use App\Services\BotExecutor;
use App\Http\Controllers\BinanceController;

class ExecutarBots extends Command
{
    protected $signature = 'bots:executar';
    protected $description = 'Executa todos os bots ativos';

    public function handle()
    {
        // Lock atômico: garante uma única execução por vez, independente do gatilho
        // (Schedule::withoutOverlapping cobre o scheduler, mas o cron.php chama o
        // kernel direto — este lock cobre os dois caminhos). TTL de 5 min evita
        // travar pra sempre caso o processo morra no meio.
        $lock = Cache::lock('bots:executar', 300);

        if (!$lock->get()) {
            $this->warn('Execução anterior ainda em andamento. Pulando este ciclo.');
            return Command::SUCCESS;
        }

        try {
            $bots = BotState::where('ativo', 1)->get();

            // Todos os bots operam a MESMA conta Binance (chave de API única) no
            // par BTCBRL. Com mais de um ativo eles brigam pelas mesmas ordens —
            // processa só o primeiro e avisa, pra não corromper o estado.
            if ($bots->count() > 1) {
                Log::warning("ExecutarBots: {$bots->count()} bots ativos sobre uma única conta Binance. Processando apenas o primeiro ({$bots->first()->id_user}).");
                $bots = $bots->take(1);
            }

            $executor = app(BotExecutor::class);

            // Persiste os trades executados (myTrades) da conta Binance única.
            // Idempotente (dedup) — seguro rodar todo ciclo. Conta compartilhada
            // entre todos os bots, então sincroniza uma vez antes do loop.
            try {
                $executor->sincronizarTrades();
            } catch (\Throwable $e) {
                Log::warning("ExecutarBots: falha ao sincronizar trades (não bloqueia execução): " . $e->getMessage());
            }

            // Snapshot diário do patrimônio (base do simulador do painel e da
            // série histórica do fundo). 1 registro/dia; flag em cache faz os
            // demais ciclos do dia pularem sem tocar a Binance.
            try {
                $this->persistirPatrimonioDiario();
            } catch (\Throwable $e) {
                Log::warning("ExecutarBots: falha ao persistir patrimônio diário (não bloqueia execução): " . $e->getMessage());
            }

            // Depósitos/saques diretos na Binance (fora do fluxo de investidor).
            // Sem isso o FIFO do PnlService cria estoque fantasma. Idempotente
            // (dedup por txid); 1x/dia, mesma flag do snapshot.
            try {
                $this->sincronizarTransfers();
            } catch (\Throwable $e) {
                Log::warning("ExecutarBots: falha ao sincronizar transfers (não bloqueia execução): " . $e->getMessage());
            }

            foreach ($bots as $bot) {
                try {
                    $executor->executar($bot->id_user);
                    $this->info("Bot executado: {$bot->id_user}");
                } catch (\Throwable $e) {
                    $this->error("Erro no bot {$bot->id_user}: " . $e->getMessage());
                }
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    /**
     * Grava o snapshot de hoje em bot_patrimonio. Idempotente pelo dia (unique
     * + updateOrCreate) e protegido por flag de cache: só o primeiro ciclo do
     * dia paga as requests à Binance; se algo falhar, a flag não é setada e o
     * ciclo seguinte tenta de novo. `total` segue o critério do patrimonio_bot
     * de bot_withdrawal_requests (BRL + BTC×preço) — BNB fica informativo.
     */
    private function persistirPatrimonioDiario(): void
    {
        $hoje = now()->format('Y-m-d');
        $flag = 'bot:patrimonio:' . $hoje;
        if (Cache::has($flag)) {
            return;
        }

        $binance = app(BinanceController::class);
        $saldos  = $binance->getSaldos();
        if (empty($saldos['balances'])) {
            throw new \RuntimeException('resposta de saldos vazia/inválida');
        }

        $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
        $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');
        $bnb = collect($saldos['balances'])->firstWhere('asset', 'BNB');

        $precos = $binance->getPrecos(); // BTCBRL + BNBBRL numa chamada só
        if (($precos['BTCBRL'] ?? 0) <= 0) {
            throw new \RuntimeException('preço BTCBRL indisponível');
        }

        $brlLivre = (float) ($brl['free'] ?? 0);
        $brlBloq  = (float) ($brl['locked'] ?? 0);
        $btcQty   = (float) ($btc['free'] ?? 0) + (float) ($btc['locked'] ?? 0);
        $bnbQty   = (float) ($bnb['free'] ?? 0) + (float) ($bnb['locked'] ?? 0);

        BotPatrimonio::updateOrCreate(
            ['dia' => $hoje],
            [
                'brl_livre'     => $brlLivre,
                'brl_bloqueado' => $brlBloq,
                'btc_qty'       => $btcQty,
                'btc_price'     => (float) $precos['BTCBRL'],
                'bnb_qty'       => $bnbQty,
                'bnb_price'     => (float) ($precos['BNBBRL'] ?? 0),
                'total'         => ($brlLivre + $brlBloq) + ($btcQty * (float) $precos['BTCBRL']),
            ]
        );

        // Expira na virada do dia (timezone da app = America/Sao_Paulo).
        Cache::put($flag, true, now()->endOfDay()->addMinute());
    }

    /**
     * Sincroniza depósitos/saques diretos da Binance pra bot_transfers.
     * Chamada 1x/dia (junto do snapshot). A API só expõe janela de 90 dias —
     * refaz os últimos 89 dias sempre (status pode mudar de pending→completed)
     * e, na primeira vez, backfilla em blocos de 89d desde o início do bot.
     * Histórico anterior ao backfill entra como lançamento manual no painel.
     */
    private function sincronizarTransfers(): void
    {
        $flag = 'bot:transfers:' . now()->format('Y-m-d');
        if (Cache::has($flag)) {
            return;
        }

        $binance = app(BinanceController::class);
        $inicio  = now()->create(2026, 3, 1, 0, 0, 0); // antes do primeiro trade (29/04)
        $total   = 0;

        // Blocos de 89 dias caminhando de $inicio até hoje.
        $fimBloco = $inicio->copy()->addDays(89);
        do {
            $ate = min($fimBloco, now())->getTimestampMs();
            foreach (['BTC', 'BRL', 'BNB'] as $coin) {
                foreach ([BotTransfer::TIPO_DEPOSIT, BotTransfer::TIPO_WITHDRAW] as $tipo) {
                    usleep(200000); // respeita rate limit da SAPI
                    $resp = $binance->getTransferencias($tipo, $coin, $inicio->getTimestampMs(), $ate);
                    if (!is_array($resp) || isset($resp['code']) || empty($resp)) {
                        continue; // janela vazia ou erro transitório — próximo bloco tenta
                    }
                    foreach ($resp as $t) {
                        $total += $this->upsertTransfer($tipo, $coin, $t);
                    }
                }
            }
            $inicio   = $fimBloco->copy();
            $fimBloco = $fimBloco->copy()->addDays(89);
        } while ($inicio->isPast());

        Cache::put($flag, true, now()->endOfDay()->addMinute());
        if ($total > 0) {
            Log::info("ExecutarBots: {$total} transferências novas em bot_transfers.");
        }
    }

    /**
     * Persiste um item do histórico (dedup por txid via insertOrIgnore).
     * Deposit traz txId/networkAmount/addressTimeStamp; withdraw traz
     * id/amount/transactionFee/applyTime. Campos ausentes viram null.
     */
    private function upsertTransfer(string $tipo, string $coin, array $t): int
    {
        // applyTime (withdraw) vem em UTC como 'Y-m-d H:i:s'; time (deposit)
        // é epoch ms UTC. Parse com tz UTC e converte pro BRT da app, mesma
        // convenção do traded_at dos trades (senão grava 3h deslocado e o
        // FIFO ordena o evento no momento errado).
        $aplicadoEm = null;
        if (isset($t['applyTime'])) {
            $aplicadoEm = Carbon::parse($t['applyTime'], 'UTC')->timezone(config('app.timezone'));
        } elseif (isset($t['time'])) {
            $aplicadoEm = Carbon::createFromTimestampMsUTC((int) $t['time'])->timezone(config('app.timezone'));
        }

        if ($aplicadoEm === null) {
            return 0;
        }

        $txid = $t['txId'] ?? $t['id'] ?? null;
        if ($txid === null) {
            return 0; // sem identificador confiável p/ dedup — ignora
        }

        return BotTransfer::insertOrIgnore([
            'transfer_type' => $tipo,
            'coin'          => $coin,
            'amount'        => (float) ($t['amount'] ?? $t['networkAmount'] ?? 0),
            'fee'           => (float) ($t['transactionFee'] ?? 0),
            'network'       => $t['network'] ?? null,
            'address'       => $t['address'] ?? null,
            'txid'          => (string) $txid,
            'status'        => isset($t['status']) ? (int) $t['status'] : null,
            'source'        => 'binance',
            'applied_at'    => $aplicadoEm,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
