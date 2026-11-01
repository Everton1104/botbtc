<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BinanceController;
use App\Http\Controllers\Controller;
use App\Models\BotConfig;
use App\Models\BotTransfer;
use App\Services\BacktestService;
use App\Services\BotAdminService;
use App\Services\BotExecutor;
use App\Services\PnlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Admin do bot no app mobile — os MESMOS controles da aba "Mais Informações"
 * do site (config, pausa manual, modo subida, performance, lixeira de cotas),
 * com token Sanctum no header em vez de sessão com cookie.
 *
 * A regra vive no BotAdminService (e nos services de PnL/backtest) — aqui é
 * só casca HTTP, igual às rotas do web.php que servem o site.
 */
class AdminApiController extends Controller
{
    public function __construct(
        private BotAdminService $admin,
        private PnlService $pnl,
    ) {}

    /**
     * GET /api/admin/controles — o form "Configuração do Bot" + os switches
     * de estado (pausa manual, modo subida), tudo que abre instantâneo (só
     * banco, zero chamada à Binance — o app mostra o form já preenchido).
     */
    public function controles(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        return response()->json([
            'config' => BotConfig::atual(),
            'estado' => $this->admin->estado((int) $request->user()->id),
        ]);
    }

    /** POST /api/admin/config — salva os campos com os clamps do site. */
    public function salvarConfig(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->admin->salvarConfig($request->all());

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : 422);
    }

    /**
     * POST /api/admin/pausar — body { "ativo": true }. Pausar cancela TODAS
     * as ordens na hora e congela o bot; despausar deixa o próximo ciclo
     * recriar o par no preço atual.
     */
    public function pausar(Request $request, BinanceController $binance): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $request->validate(['ativo' => ['required', 'boolean']]);

        $r = $this->admin->pausarManual((int) $request->user()->id, (bool) $request->input('ativo'), $binance);

        if (!$r['ok']) {
            return response()->json(['erro' => $r['erro']], $r['code'] ?? 500);
        }

        return response()->json([
            'pausado_manual' => $r['pausado_manual'],
            'canceladas'     => $r['canceladas'],
        ]);
    }

    /** POST /api/admin/modo-subida — body { "ativo": true }: inibe vendas (só compra). */
    public function modoSubida(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $request->validate(['ativo' => ['required', 'boolean']]);

        $r = $this->admin->alternarModoSubida((int) $request->user()->id, (bool) $request->input('ativo'));

        if (!$r['ok']) {
            return response()->json(['erro' => $r['erro']], $r['code'] ?? 500);
        }

        return response()->json(['modo_subida' => $r['modo_subida']]);
    }

    /** POST /api/admin/performance/reset-base — zera a base do painel M8. */
    public function resetarPerformance(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->admin->resetarBasePerformance();

        return response()->json(['mensagem' => $r['mensagem']]);
    }

    /**
     * DELETE /api/admin/investimento/{userId} — a lixeira do site: cotas do
     * investidor transferidas 1:1 pro admin e registro removido.
     */
    public function esvaziarCotas(Request $request, int $userId): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->admin->esvaziarCotas($userId, (int) $request->user()->id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }

    /**
     * GET /api/admin/numeros — a aba "Mais Informações" do site em uma
     * chamada: lucro (decomposição grid×oscilação) de 1/7/30 dias, painel de
     * performance M8, transferências diretas da Binance e a tendência que o
     * bot usa pra operar.
     *
     * Degradável como o /api/painel: Binance fora → performance/tendência
     * null e o resto de pé (FIFO e transferências são só banco).
     */
    public function numeros(Request $request, BinanceController $binance): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        // ── Lucro do bot: cards hoje/7d/30d (mesmos endpoints do site) ──
        $pnl = [];
        foreach ([1, 7, 30] as $dias) {
            try {
                $pnl[$dias] = $this->pnl->decomposicao($dias);
            } catch (\Throwable) {
                $pnl[$dias] = ['vazio' => true];
            }
        }

        // ── Performance M8: precisa de saldos live (uma viagem à Binance) ──
        $performance = null;
        try {
            $saldos = $binance->getSaldos();
            $preco  = (float) $binance->getPrecoBTC();

            if (isset($saldos['balances']) && $preco > 0) {
                $b   = collect($saldos['balances']);
                $brl = (float) ($b->firstWhere('asset', 'BRL')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BRL')['locked'] ?? 0);
                $btc = (float) ($b->firstWhere('asset', 'BTC')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BTC')['locked'] ?? 0);

                $performance = $this->pnl->performance($brl, $btc, $preco);
            }
        } catch (\Throwable) {
            // mantém null — o app mostra o aviso
        }

        // ── Tendência: a MESMA análise do bot, cacheada 60s (3 klines por poll) ──
        $tendencia = null;
        try {
            $tendencia = Cache::remember('admin.tendencia', 60, function () use ($binance) {
                $preco = (float) $binance->getPrecoBTC();
                if ($preco <= 0) return null;

                $t = app(BotExecutor::class)->analisarTendencia($preco, false);

                return [
                    'tendencia'      => $t['tendencia'],
                    'ma21'           => round($t['ma21'], 2),
                    'ema9'           => round($t['ema9'], 2),
                    'distancia_pct'  => $t['distancia_pct'],
                    'fator_compra'   => round($t['fator_compra'], 2),
                    'fator_venda'    => round($t['fator_venda'], 2),
                    'rsi'            => $t['rsi'],
                    'atr'            => $t['atr'],
                    'salto_dinamico' => $t['salto_dinamico'],
                    'macd'           => $t['macd'],
                    'macd_signal'    => $t['macd_signal'],
                    'macd_hist'      => $t['macd_hist'],
                    'boll_upper'     => $t['boll_upper'],
                    'boll_lower'     => $t['boll_lower'],
                    'boll_pct_b'     => $t['boll_pct_b'],
                    'boll_width'     => $t['boll_width'],
                    'trend_4h'       => $t['trend_4h'],
                    'ma21_4h'        => round($t['ma21_4h'], 2),
                    'rsi_4h'         => $t['rsi_4h'],
                    'preco'          => $preco,
                    'fear_greed'     => $t['fear_greed'] ?? 50,
                ];
            });
        } catch (\Throwable) {
            // bônus: sem ela os números seguem de pé
        }

        return response()->json([
            'pnl'           => ['hoje' => $pnl[1], 'd7' => $pnl[7], 'd30' => $pnl[30]],
            'performance'   => $performance,
            'transferencias' => BotTransfer::orderByDesc('applied_at')->limit(100)->get(),
            'tendencia'     => $tendencia,
        ]);
    }

    /**
     * GET /api/admin/simulacao?dias=30&patrimonio=1000 — o backtest fiel ao
     * BotExecutor (engine PHP, candles 1h+4h + F&G), o mesmo do /simulacao/run.
     * Pode levar alguns segundos (klines públicas em série).
     */
    public function simulacao(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $dias       = max(15, min(120, (int) $request->input('dias', 30)));
        $patrimonio = max(100, (float) $request->input('patrimonio', 1000));

        return response()->json(app(BacktestService::class)->rodar($dias, $patrimonio));
    }

    /**
     * GET /api/admin/relatorio?ano=2026&mes=10 — P&L realizado mensal (FIFO
     * sobre bot_trades), o mesmo do /bot/relatorio/mensal do site. Sem
     * ano/mês = mês corrente. Cacheado no PnlService (5 min mês atual).
     */
    public function relatorio(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $ano = $request->filled('ano') ? (int) $request->input('ano') : null;
        $mes = $request->filled('mes') ? (int) $request->input('mes') : null;

        return response()->json($this->pnl->mensal($ano, $mes));
    }
}
