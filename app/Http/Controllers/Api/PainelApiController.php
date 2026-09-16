<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BinanceController;
use App\Http\Controllers\Controller;
use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel do app mobile — versão API da aba "Início" do site.
 *
 * O site monta a aba Início com quatro fontes (getSaldos+getPrecos,
 * getOrdens, /admin/usuarios-investimentos e /bot/saques-pendentes).
 * O app pede tudo num endpoint único: menos idas e vindas, um só
 * pull-to-refresh.
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
        ];

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
            ];
        } catch (\Throwable) {
            // mantém os zeros do $tiles inicial
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

        if ($user->id === 1) {
            $investidores = $this->investidores($binance);
            $saques = $this->saquesPendentes();
        }

        return response()->json([
            'tiles'        => $tiles,
            'ordens'       => $ordens,
            'investidores' => $investidores,
            'saques'       => $saques,
        ]);
    }

    /**
     * Réplica do /admin/usuarios-investimentos (web.php) — cotas valorizadas
     * pelo patrimônio atual lido da Binance.
     */
    private function investidores(BinanceController $binance): array
    {
        $saldos = $binance->getSaldos() ?? ['balances' => []];
        $preco  = $binance->getPrecoBTC();

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
     * Réplica do /bot/saques-pendentes (web.php).
     */
    private function saquesPendentes(): array
    {
        return BotWithdrawalRequest::where('status', 'pendente')
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get()
            ->map(fn($s) => [
                'id'            => $s->id,
                'name'          => $s->user?->name ?? 'Desconhecido',
                'email'         => $s->user?->email ?? '—',
                'valor_bruto'   => $s->valor_bruto,
                'valor_liquido' => $s->valor_liquido,
                'cotas'         => $s->cotas,
                'criado_em'     => $s->created_at->format('d/m/Y H:i'),
            ])->all();
    }
}
