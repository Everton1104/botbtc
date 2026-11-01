<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BinanceController;
use App\Http\Controllers\Controller;
use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;
use App\Models\PixPayment;
use App\Services\BotExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Painel do app mobile — versão API da aba "Início" do site.
 *
 * O site monta a aba Início com quatro fontes (getSaldos+getPrecos,
 * getOrdens, /admin/usuarios-investimentos e /bot/saques-pendentes).
 * O app pede tudo num endpoint único: menos idas e vindas, um só
 * pull-to-refresh.
 *
 * Latência: cada chamada à Binance pode levar até 10s (timeout do
 * BinanceController), e chamadas em SÉRIE somam — a versão antiga fazia
 * 6 viagens no pior caso (~1min com a Binance pendurada). Agora:
 *   • saldos/preços buscados UMA vez e reaproveitados nos investidores;
 *   • tendência (ATR do salto) cacheada 60s — klines 4h/1d não mudam
 *     num minuto, e o cálculo é idêntico ao do bot (analisarTendencia).
 */
class PainelApiController extends Controller
{
    public function inicio(Request $request, BinanceController $binance): JsonResponse
    {
        $user = $request->user();

        // ── Tiles: saldos + preços ────────────────────────────────────────
        // Qualquer falha na Binance (ex.: IP fora do whitelist) não derruba
        // a resposta inteira: devolvemos binance_ok=false e zeros, e o app
        // mostra o aviso em vez de um erro seco.
        $tiles = [
            'binance_ok' => false,
            'preco_btc'  => 0.0,
            'brl'        => ['total' => 0.0, 'livre' => 0.0, 'bloqueado' => 0.0],
            'btc_brl'    => ['total' => 0.0, 'livre' => 0.0, 'bloqueado' => 0.0],
            'bnb_brl'    => 0.0,
            'total_geral_brl' => 0.0,
            'atr'        => 0.0,
            'salto_atr'  => 0.0,
        ];

        // Saldos/preços buscados UMA vez e reaproveitados em tudo daqui
        // pra frente (tiles, investidores e a âncora de preço da tendência).
        $saldos = null;
        $btcbrl = 0.0;

        try {
            $saldos = $binance->getSaldos() ?? ['balances' => []];
            $precos = $binance->getPrecos();

            $btcbrl = (float) $precos['BTCBRL'];
            $bnbbrl = (float) $precos['BNBBRL'];

            $pego = fn(string $asset) => collect($saldos['balances'])->firstWhere('asset', $asset) ?? ['free' => 0, 'locked' => 0];
            $btc = $pego('BTC');
            $brl = $pego('BRL');
            $bnb = $pego('BNB');

            $btcFree    = (float) $btc['free'];
            $btcLocked  = (float) $btc['locked'];
            $brlFree    = (float) $brl['free'];
            $brlLocked  = (float) $brl['locked'];
            $bnbTotal   = (float) $bnb['free'] + (float) $bnb['locked'];

            $btcTotalBrl = ($btcFree + $btcLocked) * $btcbrl;
            $brlTotal    = $brlFree + $brlLocked;
            $bnbTotalBrl = $bnbTotal * $bnbbrl;

            $tiles = [
                'binance_ok' => true,
                'preco_btc'  => $btcbrl,
                'brl'        => ['total' => $brlTotal, 'livre' => $brlFree, 'bloqueado' => $brlLocked],
                'btc_brl'    => ['total' => $btcTotalBrl, 'livre' => $btcFree * $btcbrl, 'bloqueado' => $btcLocked * $btcbrl],
                'bnb_brl'    => $bnbTotalBrl,
                // Mesma conta do JS do site: BRL + BTC em R$ + BNB em R$.
                'total_geral_brl' => $brlTotal + $btcTotalBrl + $bnbTotalBrl,
                'atr'        => 0.0, // preenchido abaixo (tendência)
                'salto_atr'  => 0.0,
            ];
        } catch (\Throwable) {
            // mantém os zeros do $tiles inicial
        }

        // ── Tendência (ATR do salto) — cacheada 60s ───────────────────────
        // analisarTendencia é a MESMA análise que o bot usa pra operar
        // (fonte única de verdade), mas custa 3 chamadas de klines — sem
        // cache, cada pull do app dispararia tudo de novo. 60s de atraso
        // no ATR é imperceptível no painel.
        try {
            $tendencia = Cache::remember('painel.tendencia', 60, function () use ($btcbrl) {
                return app(BotExecutor::class)->analisarTendencia($btcbrl, false);
            });
            $tiles['atr']       = (float) ($tendencia['atr'] ?? 0);
            $tiles['salto_atr'] = (float) ($tendencia['salto_dinamico'] ?? 0);
        } catch (\Throwable) {
            // tendência é bônus: sem ela o painel segue de pé
        }

        // ── Ordens abertas ────────────────────────────────────────────────
        $ordens = [];
        try {
            $ordens = collect($binance->getOpenOrders() ?? [])->map(fn($o) => [
                'lado'       => $o['side'],               // BUY / SELL
                'preco'      => (float) $o['price'],
                'quantidade' => (float) $o['origQty'],
                'status'     => $o['status'],
            ])->values()->all();
        } catch (\Throwable) {
            // idem: lista vazia em vez de 500
        }

        // ── Seções do admin ───────────────────────────────────────────────
        $investidores = [];
        $saques = [];
        $depositosPendentes = [];
        $meu = null;
        $ehAdmin = $user->id === 1;

        if ($ehAdmin) {
            // Saldos e preço já buscados lá em cima — nada de segunda viagem.
            $investidores = $this->investidores($saldos, $btcbrl);
            $saques = $this->saquesPendentes();
            // Depósitos pagos aguardando REGISTRO no bot — alimentam o bloco
            // "Aguardando você" no topo do app (só o admin dá continuidade).
            // Reuso da lista do admin (adminDepositos) sem nova query de
            // apoio: o que importa aqui é quem não foi registrado ainda.
            $depositosPendentes = collect(app(\App\Services\DepositoService::class)->adminDepositos())
                ->filter(fn($d) => !$d['registrado'] && !$d['estornado'])
                ->values()->all();
        } else {
            // Saldos da Binance, ordens e total do bot são do ADMIN — o
            // cliente não vê nem no app nem no payload da API. Ele recebe
            // a cotação (preco_btc) e o próprio investimento ("meu").
            $tiles['brl']             = ['total' => 0.0, 'livre' => 0.0, 'bloqueado' => 0.0];
            $tiles['btc_brl']         = ['total' => 0.0, 'livre' => 0.0, 'bloqueado' => 0.0];
            $tiles['bnb_brl']         = 0.0;
            $tiles['total_geral_brl'] = 0.0;
            $ordens = [];
            $meu = $this->meuInvestimento($user->id, $saldos, $btcbrl);
        }

        return response()->json([
            'eh_admin'     => $ehAdmin,
            'tiles'        => $tiles,
            'ordens'       => $ordens,
            'investidores' => $investidores,
            'saques'       => $saques,
            'depositos_pendentes' => $depositosPendentes,
            'meu'          => $meu,
            'historico'    => $this->historicoDe($user->id),
        ]);
    }

    /**
     * Histórico financeiro do PRÓPRIO usuário — app (via /api/painel) e
     * site do cliente (via GET /bot/historico, que chama este método):
     * depósitos confirmados + saques com estado real, mesclados do mais
     * recente pro mais antigo, no máximo 30 lançamentos. Depósito mostra o
     * LÍQUIDO (o que entrou de fato no bot); saque mostra o valor bruto do
     * pedido com o status andando (pendente → em andamento → pago).
     */
    public function historicoDe(int $userId): array
    {
        $dep = PixPayment::where('user_id', $userId)
            ->where('status', 'pago')
            ->orderByDesc('pago_em')
            ->limit(30)
            ->get(['valor', 'valor_liquido', 'pago_em']);

        $saq = BotWithdrawalRequest::where('user_id', $userId)
            ->whereIn('status', ['pendente', 'em_andamento', 'pago'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['valor_bruto', 'status', 'created_at']);

        $itens = $dep->map(fn($d) => [
            'tipo'   => 'deposito',
            'valor'  => (float) ($d->valor_liquido ?? $d->valor),
            'data'   => $d->pago_em?->format('d/m/Y H:i') ?? '—',
            '_ordem' => $d->pago_em?->getTimestamp() ?? 0,
            'status' => 'pago',
        ])->merge(
            $saq->map(fn($s) => [
                'tipo'   => 'saque',
                'valor'  => (float) $s->valor_bruto,
                'data'   => $s->created_at?->format('d/m/Y H:i') ?? '—',
                '_ordem' => $s->created_at?->getTimestamp() ?? 0,
                'status' => (string) $s->status,
            ])
        );

        return $itens->sortByDesc('_ordem')->take(30)->values()
            ->map(fn($i) => [
                'tipo'   => $i['tipo'],
                'valor'  => round($i['valor'], 2),
                'data'   => $i['data'],
                'status' => $i['status'],
            ])
            ->all();
    }

    /**
     * O investimento do PRÓPRIO usuário que pediu o painel (não-admin):
     * a mesma conta de investidores() — cotas × preço da cota do patrimônio
     * atual (BRL + BTC) — mas só a linha dele.
     */
    private function meuInvestimento(int $userId, ?array $saldos, float $preco): array
    {
        $saldos = $saldos ?? [];

        // ?? []: a Binance pode devolver corpo sem "balances" (erro/limite),
        // e o painel do cliente não pode quebrar por causa disso.
        $brl = collect($saldos['balances'] ?? [])->firstWhere('asset', 'BRL') ?? ['free' => 0, 'locked' => 0];
        $btc = collect($saldos['balances'] ?? [])->firstWhere('asset', 'BTC') ?? ['free' => 0, 'locked' => 0];

        $patrimonioAtual = ((float) $brl['free'] + (float) $brl['locked'])
                         + (((float) $btc['free'] + (float) $btc['locked']) * $preco);

        $totalCotas   = (float) BotInvestment::sum('cotas');
        $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;

        $inv = BotInvestment::where('user_id', $userId)->first();
        if (!$inv || $inv->cotas <= 0) {
            return ['investimento_inicial' => 0, 'cotas' => 0, 'valor_atual' => 0, 'lucro' => 0];
        }

        $valorAtual = $inv->cotas * $precoPorCota;

        return [
            'investimento_inicial' => $inv->investimento_inicial,
            'cotas'                => round((float) $inv->cotas, 4),
            'valor_atual'          => $valorAtual,
            'lucro'                => $valorAtual - $inv->investimento_inicial,
        ];
    }

    /**
     * Réplica do /admin/usuarios-investimentos (web.php) — cotas valorizadas
     * pelo patrimônio atual. Diferença: recebe saldos e preço JÁ BUSCADOS
     * (a versão do site buscava tudo de novo).
     */
    private function investidores(?array $saldos, float $preco): array
    {
        $saldos = $saldos ?? ['balances' => []];

        $brl = collect($saldos['balances'])->firstWhere('asset', 'BRL') ?? ['free' => 0, 'locked' => 0];
        $btc = collect($saldos['balances'])->firstWhere('asset', 'BTC') ?? ['free' => 0, 'locked' => 0];

        $patrimonioAtual = ((float) $brl['free'] + (float) $brl['locked'])
                         + (((float) $btc['free'] + (float) $btc['locked']) * $preco);

        $totalCotas   = (float) BotInvestment::sum('cotas');
        $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;

        return \App\Models\User::select('id', 'name', 'email')->get()->map(function ($u) use ($precoPorCota, $totalCotas) {
            $inv = BotInvestment::where('user_id', $u->id)->first();

            if (! $inv || $inv->cotas <= 0) {
                return [
                    'id'                   => $u->id,
                    'name'                 => $u->name,
                    'email'                => $u->email,
                    'investimento_inicial' => 0,
                    'cotas'                => 0,
                    'percentual'           => 0,
                    'valor_atual'          => 0,
                    'lucro'                => 0,
                ];
            }

            $valorAtual = $inv->cotas * $precoPorCota;
            $percentual = $totalCotas > 0 ? ($inv->cotas / $totalCotas) * 100 : 0;

            return [
                'id'                   => $u->id,
                'name'                 => $u->name,
                'email'                => $u->email,
                'investimento_inicial' => $inv->investimento_inicial,
                'cotas'                => round($inv->cotas, 4),
                'percentual'           => round($percentual, 2),
                'valor_atual'          => $valorAtual,
                'lucro'                => $valorAtual - $inv->investimento_inicial,
            ];
        })->all();
    }

    /**
     * Saques aguardando ação do admin — delegado ao SaqueService (a MESMA
     * lista do /bot/saques-pendentes): pendentes + em andamento, com o flag
     * em_andamento que o card da Home usa pro selo "EM ANDAMENTO".
     */
    private function saquesPendentes(): array
    {
        return app(\App\Services\SaqueService::class)->pendentes();
    }
}
