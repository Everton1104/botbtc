<?php

namespace App\Services;

use App\Http\Controllers\BinanceController;
use App\Models\BotInvestment;
use App\Models\BotState;
use App\Models\BotWithdrawalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regra de negócio de SAQUES — a fonte única do site (web.php) e do app
 * (Api/SaqueApiController). A lógica vivia solta nas closures das rotas;
 * ao extrair pra cá, os dois lados chamam os MESMOS métodos e o
 * comportamento fica idêntico por construção (sem versão "de celular"
 * divergindo da versão "do site" em regra de dinheiro).
 *
 * Fluxo completo:
 *   1. Investidor solicita → cotas queimam na hora, a taxa de 1% vira
 *      cotas do admin, e o admin recebe push.
 *   2. Admin confirma → se faltar BRL livre, vende BTC a mercado (margem
 *      de 0,5%), cancela as ordens abertas e pausa o bot por 3 min
 *      (tempo de fazer o PIX na Binance).
 *   3. Investidor pode cancelar um pendente → cotas (e taxa) voltam.
 *
 * Convenção de retorno: sempre array com 'ok' (bool) e 'mensagem'
 * (string pronta pra exibir). Em falhas, 'code' carrega o HTTP sugerido
 * (404 não achou, 500 erro de conversão) — quem chama decide o resto.
 */
class SaqueService
{
    public function __construct(private BinanceController $binance) {}

    // ── Investidor ────────────────────────────────────────────────────────

    /**
     * Solicita um saque. $valorSolicitado <= 0 significa "sacar tudo".
     * O patrimônio é lido ANTES da transaction porque é chamada externa
     * (Binance) — dentro da transaction só operações locais.
     */
    public function solicitar(int $userId, float $valorSolicitado): array
    {
        $saldos = $this->binance->getSaldos();
        $preco  = $this->binance->getPrecoBTC();

        $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
        $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

        $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                         + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

        $valorBruto = 0.0;

        try {
            DB::transaction(function () use ($userId, $patrimonioAtual, $valorSolicitado, &$valorBruto) {

                // Lock: impede dois saques simultâneos do mesmo usuário
                $invest = BotInvestment::where('user_id', $userId)->lockForUpdate()->first();

                if (!$invest || $invest->cotas <= 0) {
                    throw new \Exception('Nenhum investimento encontrado.', 422);
                }

                $totalCotas   = (float) BotInvestment::lockForUpdate()->sum('cotas');
                $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;
                $valorMaximo  = $invest->cotas * $precoPorCota;

                $valorBruto = $valorSolicitado > 0
                    ? min($valorSolicitado, $valorMaximo)
                    : $valorMaximo;

                if ($valorBruto <= 0) {
                    throw new \Exception('Valor inválido.', 422);
                }

                // Admin (user_id = 1) saca sem a taxa de 1%
                $isAdmin       = $userId === 1;
                $valorLiquido  = $isAdmin ? $valorBruto : $valorBruto * 0.99;
                $cotasAQueimar = $precoPorCota > 0 ? $valorBruto / $precoPorCota : 0;
                $cotasTaxa     = $isAdmin ? 0 : ($precoPorCota > 0 ? ($valorBruto * 0.01) / $precoPorCota : 0);

                BotWithdrawalRequest::create([
                    'user_id'        => $userId,
                    'valor_bruto'    => $valorBruto,
                    'valor_liquido'  => $valorLiquido,
                    'cotas'          => $cotasAQueimar,
                    'cotas_taxa'     => $cotasTaxa,   // salva para reverter exatamente no cancelamento
                    'preco_por_cota' => $precoPorCota,
                    'patrimonio_bot' => $patrimonioAtual,
                    'status'         => 'pendente',
                ]);

                // Queimar cotas do investidor
                // Zera o registro se queimou tudo OU se o resíduo virou poeira (< R$ 1),
                // evitando cotas-fantasma que continuariam aparecendo no ranking.
                $cotasRestantes = $invest->cotas - $cotasAQueimar;
                $valorRestante  = $cotasRestantes * $precoPorCota;
                if ($cotasAQueimar >= $invest->cotas || $valorRestante < 1) {
                    $invest->delete();
                } else {
                    $invest->cotas                -= $cotasAQueimar;
                    $invest->investimento_inicial  = max(0, $invest->investimento_inicial - $valorBruto);
                    $invest->save();
                }

                // Taxa de 1% vai para o admin
                if ($cotasTaxa > 0) {
                    $adminInvest = BotInvestment::where('user_id', 1)->lockForUpdate()->first();
                    if ($adminInvest) {
                        $adminInvest->cotas += $cotasTaxa;
                        $adminInvest->save();
                    } else {
                        BotInvestment::create([
                            'user_id'              => 1,
                            'investimento_inicial' => 0,
                            'cotas'                => $cotasTaxa,
                        ]);
                    }
                }
            });
        } catch (\Exception $e) {
            return ['ok' => false, 'code' => 422, 'mensagem' => $e->getMessage()];
        }

        // Push pro app (o WhatsApp de btc_saque foi aposentado — ver FcmService).
        FcmService::notificarSaque($valorBruto, User::find($userId)?->name ?? 'Desconhecido');

        return [
            'ok'          => true,
            'mensagem'    => 'Saque solicitado! Aguarde a confirmação do administrador.',
            'valor_bruto' => $valorBruto,
        ];
    }

    /** Cancela um saque pendente do próprio usuário (devolve cotas e taxa). */
    public function cancelar(int $userId, int $saqueId): array
    {
        $saque = BotWithdrawalRequest::where('id', $saqueId)
            ->where('user_id', $userId)
            ->where('status', 'pendente')
            ->first();

        if (!$saque) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Saque não encontrado ou já processado.'];
        }

        DB::transaction(function () use ($saque) {
            // Devolver as cotas ao investidor
            $invest = BotInvestment::where('user_id', $saque->user_id)->lockForUpdate()->first();
            if ($invest) {
                $invest->cotas                += $saque->cotas;
                $invest->investimento_inicial += $saque->valor_bruto;
                $invest->save();
            } else {
                BotInvestment::create([
                    'user_id'              => $saque->user_id,
                    'investimento_inicial' => $saque->valor_bruto,
                    'cotas'                => $saque->cotas,
                ]);
            }

            // Reverter taxa do admin usando o valor exato registrado no saque
            $cotasTaxa = (float) ($saque->cotas_taxa ?? 0);
            if ($cotasTaxa > 0) {
                $adminInvest = BotInvestment::where('user_id', 1)->lockForUpdate()->first();
                if ($adminInvest) {
                    $adminInvest->cotas = max(0, $adminInvest->cotas - $cotasTaxa);
                    $adminInvest->cotas > 0 ? $adminInvest->save() : $adminInvest->delete();
                }
            }

            $saque->status = 'cancelado';
            $saque->save();
        });

        return ['ok' => true, 'mensagem' => 'Saque cancelado e valor devolvido ao seu saldo.'];
    }

    /**
     * Pendentes + histórico do usuário, e o valor disponível pra sacar hoje
     * (cotas × preço da cota). 'disponivel' é aditivo: o site ignora a chave.
     */
    public function meus(int $userId): array
    {
        $pendentes = BotWithdrawalRequest::where('user_id', $userId)
            ->where('status', 'pendente')
            ->orderBy('created_at')
            ->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'valor_bruto'   => $s->valor_bruto,
                'valor_liquido' => $s->valor_liquido,
                'criado_em'     => $s->created_at->format('d/m/Y H:i'),
            ]);

        $historico = BotWithdrawalRequest::where('user_id', $userId)
            ->where('status', 'confirmado')
            ->orderByDesc('confirmado_at')
            ->get()
            ->map(fn($s) => [
                'valor_liquido' => $s->valor_liquido,
                'confirmado_em' => $s->confirmado_at
                    ? \Carbon\Carbon::parse($s->confirmado_at)->format('d/m/Y H:i')
                    : '—',
            ]);

        return [
            'disponivel' => $this->valorDisponivel($userId),
            'pendentes'  => $pendentes,
            'historico'  => $historico,
        ];
    }

    /** Valor atual do investimento do usuário — o teto que ele pode sacar. */
    private function valorDisponivel(int $userId): float
    {
        try {
            $invest = BotInvestment::where('user_id', $userId)->first();
            if (!$invest || $invest->cotas <= 0) {
                return 0.0;
            }

            $saldos = $this->binance->getSaldos();
            $preco  = $this->binance->getPrecoBTC();

            $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL');
            $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC');

            $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                             + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $preco);

            $totalCotas = (float) BotInvestment::sum('cotas');
            return $totalCotas > 0 ? $invest->cotas * ($patrimonioAtual / $totalCotas) : 0.0;
        } catch (\Throwable) {
            // Binance fora do ar (ex.: IP fora do whitelist): 0 em vez de
            // quebrar a tela inteira — o usuário tenta de novo em instantes.
            return 0.0;
        }
    }

    // ── Admin ─────────────────────────────────────────────────────────────

    /** Saques pendentes de TODOS os investidores, aguardando aprovação. */
    public function pendentes(): array
    {
        return BotWithdrawalRequest::where('status', 'pendente')
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'user_id'       => $s->user_id,
                'name'          => $s->user?->name ?? 'Desconhecido',
                'email'         => $s->user?->email ?? '—',
                'valor_bruto'   => $s->valor_bruto,
                'valor_liquido' => $s->valor_liquido,
                'cotas'         => $s->cotas,
                'criado_em'     => $s->created_at->format('d/m/Y H:i'),
            ])->all();
    }

    /**
     * CONFIRMAÇÃO do admin — o momento em que o dinheiro sai. Se o BRL
     * livre não cobrir o líquido, vende BTC a mercado (margem de 0,5% pra
     * taxa da Binance) e cancela as ordens abertas. Depois pausa o bot por
     * 3 minutos: o tempo do admin fazer o PIX na Binance com o mercado
     * parado (sem o bot recriando ordens no meio da transferência).
     */
    public function confirmar(int $saqueId): array
    {
        $saque = BotWithdrawalRequest::where('id', $saqueId)->where('status', 'pendente')->first();

        if (!$saque) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Saque não encontrado ou já confirmado.'];
        }

        $valorLiquido = (float) $saque->valor_liquido;

        // Verificar saldo BRL livre
        $saldos        = $this->binance->getSaldos();
        $brl           = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
        $saldoBRLLivre = (float) ($brl['free'] ?? 0);

        if ($saldoBRLLivre < $valorLiquido) {
            $falta      = $valorLiquido - $saldoBRLLivre;
            $precoAtual = $this->binance->getPrecoBTC();

            // Cancelar todas as ordens abertas do bot
            $ordensAbertas = $this->binance->getOpenOrders('BTCBRL');
            foreach ($ordensAbertas as $ordem) {
                $this->binance->cancelarOrdem('BTCBRL', $ordem['orderId']);
            }

            // Vender BTC suficiente (margem de 0.5% para cobrir taxa da Binance)
            $btcNecessario = ($falta / $precoAtual) * 1.005;
            $resultado     = $this->binance->sellMarketBTC($btcNecessario);

            if (isset($resultado['code'])) {
                Log::error("Saque atípico [{$saqueId}]: falha ao vender BTC — " . json_encode($resultado));
                return [
                    'ok'      => false,
                    'code'    => 500,
                    'mensagem' => 'Erro ao converter BTC para BRL. Verifique o saldo e tente novamente.',
                ];
            }

            Log::info("Saque atípico [{$saqueId}]: vendidos {$btcNecessario} BTC a mercado para cobrir R$ {$falta}. BRL disponível era R$ {$saldoBRLLivre}.");
        }

        // Pausar todos os estados do bot por 3 minutos para o admin fazer a transferência
        BotState::query()->update(['pausado_ate' => now()->addMinutes(3)]);

        $saque->status        = 'confirmado';
        $saque->confirmado_at = now();
        $saque->save();

        return ['ok' => true, 'mensagem' => 'PIX confirmado com sucesso!'];
    }

    /** Situação da pausa pós-confirmação (admin). */
    public function statusPausa(): array
    {
        $state = BotState::whereNotNull('pausado_ate')->where('pausado_ate', '>', now())->first();

        if (!$state) {
            return ['pausado' => false, 'segundos' => 0];
        }

        return [
            'pausado'  => true,
            'segundos' => (int) now()->diffInSeconds($state->pausado_ate),
        ];
    }

    /** Libera o bot da pausa (admin terminou a transferência antes do tempo). */
    public function retomarBot(): array
    {
        BotState::query()->update(['pausado_ate' => null]);

        return ['ok' => true, 'mensagem' => 'Bot liberado com sucesso.'];
    }
}
