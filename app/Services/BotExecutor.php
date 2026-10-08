<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotConfig;
use App\Models\BotTrade;
use App\Http\Controllers\BinanceController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BotExecutor
{
    protected BinanceController $binance;

    private const SYMBOL  = 'BTCBRL';
    private const ALLIN_CAP = 0.95;

    // Ordens com fill recente esperando o push único agregado. A lista vive
    // no cache (chave = "symbol:orderId" → dedup de graça) e é consumida pelo
    // fecharOrdensPendentes() no fim de cada ciclo.
    private const CACHE_PUSH_PENDENTES = 'bot.push_pendentes';

    // Salto dinâmico (tamanho do grid): piso/teto em BRL e multiplicador do ATR.
    // Piso subido p/ 3k (era 2k) — com BTC a ~340k, 3k ≈ 0,9% de spread, deixando
    // ~0,7% de margem líquida por ciclo após fees (antes era ~0,4% e o salto
    // ficava travado no piso quase sempre). ATR_MULT maior deixa o salto escalar
    // de fato quando a volatilidade sobe.
    private const SALTO_MIN = 3000;
    private const SALTO_MAX = 10000;
    private const ATR_MULT  = 0.8;

    // Janelas de volatilidade (escala DIÁRIA) para o salto — mistura estável + reativa.
    // Antes: ATR 4h×14 (~2 dias) chicoteava com a calmaria recente e sub-dimensionava o
    // grid (movimento de 4h ≠ movimento que o salto precisa espaçar). Agora âncora de
    // regime (30d, estável) + reatividade (14d), com freio de ruptura: se a curta
    // ultrapassa RUPTURA× a longa (regime esquentou), segue a curta.
    private const ATR_DIAS_LONG  = 30;
    private const ATR_DIAS_CURTA = 14;
    private const ATR_PESO_LONG  = 0.7;
    private const ATR_RUPTURA    = 1.5;

    // Floor absoluto (BRL) para criação de ordem, independente do valor no config.
    // Garante que nunca saia dust trade (< R$50) mesmo se min_notional estiver
    // 0/NULL transitório no bot_config (em ago/2026 ainda saíam ordens de R$3-R$40
    // apesar dos guards — este floor trava a saída; o log em criarOrdensNovas
    // diagnostica a causa raiz se houver recaída).
    private const MIN_NOTIONAL_FLOOR = 50.0;

    // ── M5: spread mínimo do grid ──────────────────────────────────────────
    // Largura total do grid = 2× salto. Piso para cobrir a taxa total
    // (2× 0,075% = 0,15%) + lucro líquido mínimo por ciclo (0,75%) → 0,90%.
    // Configurável em bot_config (spread_minimo_pct); constantes = fallback.
    private const TAXA_TOTAL_PCT        = 0.15;
    private const LUCRO_LIQUIDO_MIN_PCT = 0.75;

    // ── M6: camadas de salto por ATR (vol diária 30d/14d) ──────────────────
    // O regime define a FAIXA do grid; o modulador de Bollinger width continua
    // ajustando fino DENTRO da faixa. Com ATR ~7000 o salto antigo escalava
    // até 10000 (grid largo demais → poucas operações); as camadas mantêm o
    // grid proporcional ao regime: baixa volatilidade → grid apertado (mais
    // ciclos), alta volatilidade → grid largo (ciclos com folga).
    // [atr_maximo_da_camada, salto_min_da_faixa, salto_max_da_faixa]
    private const CAMADAS_ATR = [
        [4000.0, 3000, 4000],
        [8000.0, 4000, 6000],
        [PHP_FLOAT_MAX, 6000, 8000],
    ];

    // ── M7: extremos de Bollinger/F&G (redução) — RSI é configurável ───────
    private const BOLL_PCT_B_MAX_COMPRA = 0.95; // banda superior colada → reduzir compras
    private const BOLL_PCT_B_MIN_VENDA  = 0.05; // banda inferior colada → reduzir vendas
    private const FNG_EUFORIA           = 90;   // >90 → favorecer realização
    private const FNG_PANICO            = 10;   // <10 → favorecer acumulação

    // ── M4: modo subida automático — histerese de ativação/desativação ─────
    private const SUBIDA_AUTO_RSI_ENTRA = 65.0;
    private const SUBIDA_AUTO_RSI_SAI   = 55.0;
    private const SUBIDA_AUTO_FNG       = 60;

    // ── M9: adaptação do grid ao regime (reposiciona pernas mantendo centro) ──
    private const ADAPT_HISTERESE_PCT    = 30.0; // divergência mínima p/ considerar
    private const ADAPT_COOLDOWN_MIN     = 240;  // espera mínima desde a criação do par
    private const ADAPT_PERSISTENCIA_MIN = 30;   // divergência precisa durar (min)
    private const CACHE_ADAPT_DESDE      = 'bot.adapt.divergente_desde';

    public function __construct(BinanceController $binance)
    {
        $this->binance = $binance;
    }

    /** min_notional efetivo: nunca abaixo do floor absoluto de R$50. */
    private function minNotionalEfetivo(BotConfig $config): float
    {
        $cfg = (float) $config->min_notional;
        return $cfg > 0 ? $cfg : self::MIN_NOTIONAL_FLOOR;
    }

    /**
     * Modo "preparar subida" (gatilho manual do admin): inibe as ordens de VENDA,
     * mantém/cria só COMPRA (captura pullbacks numa subida forte sem realizar cedo).
     * Fluxo isolado do state machine — não toca em direção/contadores, então não
     * dispara o guard de "par incompleto" nem contadores fantasmas.
     */
    private function executarModoSubida(BotState $state, array $open, float $precoAtual, ?array $tendencia = null): string
    {
        // 1. Cancela qualquer ordem de VENDA aberta (no modo só compramos).
        $cancelou = false;
        foreach ($open as $ordem) {
            if (($ordem['side'] ?? '') === 'SELL') {
                $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                $cancelou = true;
            }
        }

        // 2. Re-lista ordens após cancelamentos pra contar compras.
        if ($cancelou) {
            $open = $this->binance->getOpenOrders(self::SYMBOL);
            if (!is_array($open)) $open = [];
            Log::info("BotExecutor: modo subida — ordem(ns) de venda cancelada(s).");
        }
        $comprasAbertas = 0;
        foreach ($open as $ordem) {
            if (($ordem['side'] ?? '') === 'BUY') $comprasAbertas++;
        }

        // 3. Se não há compra aberta, cria uma (captura pullback). soCompra=true inibe a venda.
        if ($comprasAbertas === 0) {
            $state->order_id_compra = null;
            $state->order_id_venda  = null;
            $ok = $this->criarOrdensNovas($state, $precoAtual, true, $tendencia);
            if ($ok) {
                Log::info("BotExecutor: modo subida — compra criada em {$precoAtual} (venda inibida).");
                return "Modo subida ativo: compra criada, venda inibida.";
            }
            Log::warning("BotExecutor: modo subida — não foi possível criar compra (saldo/API).");
            return "Modo subida ativo: sem compra (saldo/API).";
        }

        return "Modo subida ativo: {$comprasAbertas} compra aberta, venda inibida.";
    }

    public function executar(string $userId): string
    {
        $state = BotState::where('id_user', $userId)->first();

        if (!$state) {
            return $this->inicializarBotSemDivisao($userId);
        }

        // ============================================================
        // PAUSA MANUAL DO ADMIN — cancela ordens que houver e congela:
        // não cria nada até o admin despausar (pra operar manualmente
        // sem o bot recriando o grid em cima). O cancelamento também
        // acontece na rota /bot/pausar; repetir aqui é defesa caso a
        // rota falhe por rate limit no momento do gatilho.
        // ============================================================
        if ($state->pausado_manual) {
            $open = $this->binance->getOpenOrders(self::SYMBOL);
            if (is_array($open) && !empty($open)) {
                foreach ($open as $ordem) {
                    $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                }
                Log::info("BotExecutor [{$userId}]: pausa manual — " . count($open) . " ordem(ns) cancelada(s) pelo ciclo.");
            }
            return "Bot pausado manualmente. Aguardando despausar.";
        }

        // Verificar pausa (saque em andamento)
        if ($state->pausado_ate && now()->lessThan($state->pausado_ate)) {
            $restam = now()->diffInSeconds($state->pausado_ate);
            Log::info("BotExecutor [{$userId}]: pausado por saque. Retoma em {$restam}s.");
            return "Bot pausado. Retoma em {$restam}s.";
        }

        // Buscar ordens abertas
        $open = $this->binance->getOpenOrders(self::SYMBOL);

        if (!is_array($open)) {
            Log::warning("BotExecutor [{$userId}]: falha ao buscar ordens abertas.");
            return "Erro ao buscar ordens abertas.";
        }

        $precoAtual = $this->binance->getPrecoBTC();

        if ($precoAtual <= 0) {
            Log::warning("BotExecutor [{$userId}]: preço inválido ({$precoAtual}). Abortando.");
            return "Preço inválido. Abortando execução.";
        }

        // ============================================================
        // M4/M8/M9 — ANÁLISE ÚNICA DO CICLO: calculada uma vez e reusada
        // pelo modo subida automático, pelo log detalhado, pela adaptação
        // do grid e pelas criações de ordem deste ciclo (sem re-buscar
        // klines no mesmo minuto). Sem $registrarLog aqui — a linha
        // unificada do ciclo sai no logCicloDetalhado().
        // ============================================================
        $config    = BotConfig::atual();
        $tendencia = $this->analisarTendencia($precoAtual, false);
        $this->avaliarModoSubidaAutomatico($state, $tendencia, $config);

        // M8 — base de performance: fotografa na primeira execução pós-deploy
        // (state novo fotografa na inicialização; state que já existia, aqui).
        $this->fotografarBasePerformance($config, $precoAtual);

        // M9 — par criado antes desta feature: fotografia a idade agora para
        // o cooldown da adaptação começar a valer daqui (não reposiciona um
        // par legítimo de horas atrás logo no primeiro ciclo pós-deploy).
        if ($state->par_criado_em === null) {
            $state->par_criado_em = now();
            $state->save();
        }

        $this->logCicloDetalhado($userId, $state, $precoAtual, $tendencia, $config);

        // ============================================================
        // MODO "PREPARAR SUBIDA" (gatilho manual do admin) — fluxo próprio:
        // cancela ordens de venda, mantém/cria só compra, sem tocar no state
        // machine (contadores congelam). Volta ao normal quando desligado.
        // ============================================================
        if ($state->modo_subida) {
            return $this->executarModoSubida($state, $open, $precoAtual, $tendencia);
        }

        // ============================================================
        // PROTEÇÃO: cancelar ordens fora do preço atual com margem
        // ============================================================
        // Margem de proteção: 3× salto para não cancelar a ordem restante válida
        // (após uma execução, a ordem restante fica ~2× salto do preço atual)
        $margem       = $state->salto > 0 ? $state->salto * 3.0 : $precoAtual * 0.03;
        $cancelledAny = false;

        foreach ($open as $ordem) {
            $side  = $ordem['side'];
            $price = (float) $ordem['price'];

            // SELL zombie: preço subiu muito acima da ordem sem ela ter executado
            if ($side === 'SELL' && ($precoAtual - $price) > $margem) {
                $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                $cancelledAny = true;
                Log::warning("BotExecutor [{$userId}]: SELL zombie cancelada — ordem={$price} atual={$precoAtual} margem={$margem}.");
            }

            // BUY zombie: preço caiu muito abaixo da ordem sem ela ter executado
            if ($side === 'BUY' && ($price - $precoAtual) > $margem) {
                $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                $cancelledAny = true;
                Log::warning("BotExecutor [{$userId}]: BUY zombie cancelada — ordem={$price} atual={$precoAtual} margem={$margem}.");
            }
        }

        // Atualizar lista após cancelamentos
        $open = $this->binance->getOpenOrders(self::SYMBOL);

        if (!is_array($open)) {
            Log::warning("BotExecutor [{$userId}]: falha ao re-listar ordens após cancelamentos.");
            return "Erro ao re-listar ordens após cancelamentos.";
        }

        $qtd = count($open);

        // ============================================================
        // 0 ORDENS → recriar par
        // ============================================================
        if ($qtd === 0) {
            // Se não cancelamos nada mas o state tinha ordens dos dois lados, é
            // provável que ambas executaram entre ciclos (whipsaw). A execução
            // real não será contabilizada pela lógica de contagem — logar para
            // auditoria. (Solução definitiva exigiria consultar /api/v3/myTrades.)
            if (!$cancelledAny && !empty($state->order_id_compra) && !empty($state->order_id_venda)) {
                Log::warning("BotExecutor [{$userId}]: 0 ordens sem cancelamento prévio — possível whipsaw (ambas as pernas executaram entre ciclos). Direção não registrada.");
            }
            if (!$this->criarOrdensNovas($state, $precoAtual, false, $tendencia)) {
                return "Erro ao criar par (saldo ou API). Verifique os logs.";
            }
            Log::info("BotExecutor [{$userId}]: 0 ordens abertas. Par recriado em {$precoAtual}.");
            return "Nenhuma ordem aberta. Par recriado.";
        }

        // ============================================================
        // 2 OU MAIS ORDENS → M9: adaptação do grid ao regime
        // ============================================================
        // Antes: "nada a fazer" até uma perna executar. Agora: com o par
        // completo, avalia reposicionar as pernas para o salto que o ATR
        // atual pede (mantendo o centro), com todas as travas anti-churn.
        // Sem divergência suficiente, o comportamento é o mesmo de antes.
        if ($qtd >= 2) {
            return $this->adaptarGridARegime($userId, $state, $open, $precoAtual, $tendencia, $config);
        }

        // ============================================================
        // EXATAMENTE 1 ORDEM → interpretar movimento
        // ============================================================
        $ordem = $open[0];
        $side  = $ordem['side'];

        // Ordem saiu do range sem ser executada — recriar par sem registrar direção
        if ($cancelledAny) {
            if (!$this->limparTodasOrdensEAguardar(self::SYMBOL)) {
                Log::warning("BotExecutor [{$userId}]: timeout ao cancelar ordens (range). Abortando.");
                return "Timeout ao cancelar ordens fora do range.";
            }
            if (!$this->criarOrdensNovas($state, $precoAtual, false, $tendencia)) {
                return "Erro ao recriar par após cancelamento de range.";
            }
            Log::info("BotExecutor [{$userId}]: ordem fora do range removida. Par recriado em {$precoAtual}.");
            return "Ordem fora do range cancelada (não executada). Par recriado no preço atual.";
        }

        // ── Par incompleto ──────────────────────────────────────────
        // Se a perna OPOSTA a esta ordem nunca foi criada (saldo só de um lado),
        // então "1 ordem aberta" NÃO significa execução — é um par que nasceu
        // torto. Recriar sem registrar subida/queda evita contadores fantasmas.
        $idOpostoEsperado = $side === 'SELL' ? $state->order_id_compra : $state->order_id_venda;
        if (empty($idOpostoEsperado)) {
            // ── Posição unilateral legítima (não churnar) ───────────────
            // Se a perna oposta não existe porque NÃO HÁ estoque pra criá-la
            // (BTC insuficiente pra vender, ou BRL insuficiente pra comprar,
            //  ambos abaixo do min_notional), isso não é "par incompleto" — é
            // uma posição de um lado só esperando o preço reequilibrar. Recriar
            // em loop só cancela/recria a mesma perna a cada ciclo. Deixar como
            // está. (Sem isso, com min_notional alto, o bot entra em loop quando
            // esgota um dos lados — regressão observada em 2026-07-21.)
            $configChk = BotConfig::atual();
            try {
                $saldosChk = $this->binance->getSaldos();
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // Timeout transitório da Binance — sem saber o saldo, não dá pra
                // decidir se é posição unilateral. Segue pro caminho de recriar
                // (comportamento original). Apenas loga.
                Log::warning("BotExecutor [{$userId}]: timeout ao checar posição unilateral — segue para recriar. " . $e->getMessage());
                $saldosChk = null;
            }
            if (isset($saldosChk['balances'])) {
                $balancesChk = collect($saldosChk['balances']);
                $saldoBTCChk = (float) ($balancesChk->firstWhere('asset', 'BTC')['free'] ?? 0);
                $saldoBRLChk = (float) ($balancesChk->firstWhere('asset', 'BRL')['free'] ?? 0);
                $saltoRef     = $state->salto > 0 ? (float) $state->salto : $precoAtual * 0.01;
                $valorVendaOk = $saldoBTCChk * ($precoAtual + $saltoRef) >= $this->minNotionalEfetivo($configChk);
                $valorCompraOk = $saldoBRLChk >= $this->minNotionalEfetivo($configChk);

                if ($side === 'BUY' && !$valorVendaOk) {
                    Log::info("BotExecutor [{$userId}]: só perna BUY, mas BTC insuficiente (< min_notional) pra vender — posição unilateral mantida (sem churn). BTC={$saldoBTCChk}.");
                    return "Posição unilateral (só BUY): sem BTC pra vender. Perna mantida.";
                }
                if ($side === 'SELL' && !$valorCompraOk) {
                    Log::info("BotExecutor [{$userId}]: só perna SELL, mas BRL insuficiente (< min_notional) pra comprar — posição unilateral mantida (sem churn). BRL={$saldoBRLChk}.");
                    return "Posição unilateral (só SELL): sem BRL pra comprar. Perna mantida.";
                }
            }

            if (!$this->limparTodasOrdensEAguardar(self::SYMBOL)) {
                Log::warning("BotExecutor [{$userId}]: timeout ao cancelar par incompleto. Abortando.");
                return "Timeout ao cancelar par incompleto.";
            }
            if (!$this->criarOrdensNovas($state, $precoAtual, false, $tendencia)) {
                return "Erro ao recriar par incompleto.";
            }
            Log::info("BotExecutor [{$userId}]: par incompleto (só perna {$side}) detectado. Par recriado em {$precoAtual} sem registrar direção.");
            return "Par incompleto detectado. Recriado sem registrar direção.";
        }

        // Calcular preço de execução da ordem que foi preenchida.
        // A ordem restante + o salto anterior revelam onde o par estava centrado,
        // garantindo que o novo par seja centrado no fill price e não no preço atual.
        $precoOrdemRestante = (float) $ordem['price'];
        // salto > 0 sempre após criarOrdensNovas; fallback usa 1% do preço atual
        $saltoAnterior      = $state->salto > 0 ? (float) $state->salto : $precoAtual * 0.01;

        if ($side === 'SELL') {
            // BUY foi executada → fill price = SELL_restante − 2 × salto
            $precoExecucao = max(1.0, $precoOrdemRestante - 2 * $saltoAnterior);
        } else {
            // SELL foi executada → fill price = BUY_restante + 2 × salto
            $precoExecucao = $precoOrdemRestante + 2 * $saltoAnterior;
        }

        // Registrar direção e persistir estado ANTES de operações que podem falhar
        if ($side === 'SELL') {
            // BUY foi executada → BTC caiu
            $this->processarQueda($state, $precoExecucao);
            $state->save();
            Log::info("BotExecutor [{$userId}]: QUEDA registrada. Contador quedas: {$state->contador_quedas}. Fill: {$precoExecucao} (mercado atual: {$precoAtual}).");
        } else {
            // SELL foi executada → BTC subiu
            $this->processarSubida($state, $precoExecucao);
            $state->save();
            Log::info("BotExecutor [{$userId}]: SUBIDA registrada. Contador subidas: {$state->contador_subidas}. Fill: {$precoExecucao} (mercado atual: {$precoAtual}).");
        }

        // Cancelar ordem restante e criar novo par centrado no fill price
        if (!$this->limparTodasOrdensEAguardar(self::SYMBOL)) {
            Log::warning("BotExecutor [{$userId}]: timeout ao cancelar ordem restante. Abortando criação de par.");
            return "Timeout ao cancelar ordem restante. Direção já registrada.";
        }

        if (!$this->criarOrdensNovas($state, $precoExecucao, false, $tendencia)) {
            return "Direção registrada mas erro ao criar novo par. Verifique os logs.";
        }

        return "Uma ordem restante detectada. Direção registrada e novo par criado.";
    }

    // ============================================================
    // SINCRONIZAR TRADES (myTrades → bot_trades)
    // ============================================================
    // Persiste os fills executados na Binance para permitir P&L/fees/drawdown
    // sem depender de export manual e reconciliar banco × Binance.
    // Roda a cada ciclo do ExecutarBots. Idempotente: dedup por
    // (symbol, binance_trade_id) via insertOrIgnore + unique.
    public function sincronizarTrades(): int
    {
        $total = 0;

        foreach (['BTCBRL', 'BNBBRL'] as $symbol) {
            $ultimo = BotTrade::where('symbol', $symbol)->max('binance_trade_id');

            if ($ultimo) {
                // Incremental: só o que veio depois do último salvo.
                $total += $this->puxarPaginado($symbol, ['fromId' => $ultimo], 0);
            } else {
                // Backfill inicial: puxa desde 1 ano atrás (limite ~365 dias da Binance).
                $startMs = Carbon::now()->subYear()->getTimestampMs();
                $total += $this->puxarPaginado($symbol, ['startTime' => $startMs], 0);
            }
        }

        if ($total > 0) {
            Log::info("BotExecutor: sincronizados {$total} trades novos para bot_trades.");
        }

        // Push único por ordem — roda SEMPRE, mesmo sem trades novos neste
        // ciclo: uma ordem que ficou pendente (getOrder falhou / ainda havia
        // fração a executar) precisa ser rechecada até fechar.
        $this->fecharOrdensPendentes();

        return $total;
    }

    /**
     * Push ÚNICO por ordem, não por fill. Ordem limite no grid executa em
     * frações — cada fração que entra em bot_trades só registra a ordem no
     * cache (puxarPaginado). Aqui, no fim do ciclo, pergunta à Binance se a
     * ordem JÁ TERMINOU:
     *
     *   FILLED                    → executou inteira;
     *   CANCELED/EXPIRED/REJECTED → acabou antes, com o que deu (ex.: ordem
     *                             cancelada ao confirmar um saque).
     *   NEW/PARTIALLY_FILLED      → ainda pode vir mais fração: espera o
     *                             próximo ciclo (TTL de 6h corta órfãos).
     *
     * Quando fecha: soma qty/valor/nº de execuções dos fills em bot_trades e
     * manda UM push agregado (preço = média ponderada). A ordem sai da lista
     * ANTES do push, então falha no FCM nunca causa reenvio. Push é apelido:
     * nada aqui pode afetar a execução do bot.
     */
    private function fecharOrdensPendentes(): void
    {
        $pendentes = Cache::get(self::CACHE_PUSH_PENDENTES, []);
        if (!$pendentes) {
            return;
        }

        foreach ($pendentes as $chave => $p) {
            $ordem = $this->binance->getOrder($p['symbol'], (string) $p['order_id']);

            if (!is_array($ordem)) {
                continue; // Binance não respondeu — tenta de novo no próximo ciclo
            }

            $status = $ordem['status'] ?? '';
            if (!in_array($status, ['FILLED', 'CANCELED', 'EXPIRED', 'REJECTED'], true)) {
                continue; // NEW / PARTIALLY_FILLED: ordem ainda viva, não empurra ainda
            }

            // Fechou (cheia ou cancelada): sai da lista antes de qualquer push.
            unset($pendentes[$chave]);

            // Agregação dos fills desta ordem já persistidos em bot_trades.
            // MAX(side): todos os fills de uma mesma ordem têm o mesmo lado.
            $agg = BotTrade::where('symbol', $p['symbol'])
                ->where('binance_order_id', $p['order_id'])
                ->selectRaw('COUNT(*) AS execucoes, SUM(qty) AS qty, SUM(quote_qty) AS quote, MAX(side) AS lado')
                ->first();

            $execucoes = (int) ($agg->execucoes ?? 0);
            $qty       = (float) ($agg->qty ?? 0);

            // Cancelada sem executar nada → não existe o que notificar.
            if ($execucoes === 0 || $qty <= 0) {
                continue;
            }

            FcmService::notificarOrdemExecutada(
                (string) $agg->lado,
                $qty,
                (float) ($agg->quote ?? 0),
                $p['symbol'],
                $execucoes,
                $status !== 'FILLED',
            );

            Log::info(sprintf(
                'BotExecutor: push de ordem %s #%d (%s) — %d fill(s), %.8f.',
                $p['symbol'],
                $p['order_id'],
                $status,
                $execucoes,
                $qty,
            ));
        }

        Cache::put(self::CACHE_PUSH_PENDENTES, $pendentes, now()->addHours(6));
    }

    /**
     * Pagina getMyTrades (1000 por chamada) inserindo com insertOrIgnore (dedup).
     * $params começa com fromId ou startTime; avança via fromId = último id + 1.
     */
    private function puxarPaginado(string $symbol, array $params, int $total): int
    {
        $cursor = $params;

        do {
            $trades = $this->binance->getMyTrades(
                $symbol,
                $cursor['fromId'] ?? null,
                $cursor['startTime'] ?? null,
                1000
            );

            if (!is_array($trades) || empty($trades)) {
                break;
            }

            $rows    = [];
            $ultimoId = 0;

            foreach ($trades as $t) {
                $id = (int) $t['id'];
                $rows[] = [
                    'binance_trade_id'  => $id,
                    'binance_order_id'  => isset($t['orderId']) ? (int) $t['orderId'] : null,
                    'symbol'            => $t['symbol'] ?? $symbol,
                    'side'              => ($t['isBuyer'] ?? false) ? 'BUY' : 'SELL',
                    'price'             => (float) ($t['price'] ?? 0),
                    'qty'               => (float) ($t['qty'] ?? 0),
                    'quote_qty'         => (float) ($t['quoteQty'] ?? 0),
                    'commission'        => (float) ($t['commission'] ?? 0),
                    'commission_asset'  => $t['commissionAsset'] ?? '',
                    'is_maker'          => (bool) ($t['isMaker'] ?? true),
                    'traded_at'         => Carbon::createFromTimestampMs((int) $t['time']),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
                $ultimoId = max($ultimoId, $id);
            }

            // Quais fills desta página são NOVOS de verdade? O insertOrIgnore
            // deduplica o INSERT, mas $rows traz TUDO que a Binance devolveu —
            // notificar $rows reenviaria o mesmo fill a cada ciclo do bot
            // (era o bug do push "contínuo": 1 push por minuto durante 1h).
            $idsPagina    = array_column($rows, 'binance_trade_id');
            $jaConhecidos = BotTrade::where('symbol', $symbol)
                ->whereIn('binance_trade_id', $idsPagina)
                ->pluck('binance_trade_id')
                ->flip(); // [id => true] → lookup O(1) no filtro abaixo
            $novos = array_filter(
                $rows,
                fn($r) => !$jaConhecidos->has($r['binance_trade_id'])
            );

            // insertOrIgnore respeita o unique (symbol, binance_trade_id) → dedup seguro.
            $total += BotTrade::insertOrIgnore($rows);

            // Fills inéditos e FRESCOS (janela de 1h — sem ela, o backfill da
            // primeira execução puxa 1 ano de histórico e registra ordens
            // velhas). Aqui NÃO vai push: um fill é só uma FRAÇÃO da ordem.
            // Registra as ordens envolvidas no cache e deixa o
            // fecharOrdensPendentes() mandar UM push por ordem quando ela
            // terminar de executar (a "cotação atingir o objetivo" de verdade).
            $frescos = array_filter($novos, fn($r) => $r['traded_at']->gt(now()->subHour()));
            if ($frescos) {
                $pendentes = Cache::get(self::CACHE_PUSH_PENDENTES, []);
                foreach ($frescos as $r) {
                    if (!empty($r['binance_order_id'])) {
                        $chave = $r['symbol'] . ':' . $r['binance_order_id'];
                        $pendentes[$chave] = [
                            'symbol'   => $r['symbol'],
                            'order_id' => $r['binance_order_id'],
                        ];
                    }
                }
                Cache::put(self::CACHE_PUSH_PENDENTES, $pendentes, now()->addHours(6));
            }

            // Próxima página a partir do último id visto.
            $cursor = ['fromId' => $ultimoId];

            // Se veio menos que o limite, chegamos ao fim do histórico disponível.
            if (count($trades) < 1000) {
                break;
            }
        } while (true);

        return $total;
    }

    // ============================================================
    // LIMPAR TODAS AS ORDENS E AGUARDAR
    // ============================================================

    private function limparTodasOrdensEAguardar(string $symbol): bool
    {
        $open = $this->binance->getOpenOrders($symbol);

        if (!is_array($open)) {
            Log::warning("BotExecutor: falha ao listar ordens antes de limpar ({$symbol}).");
            return false;
        }

        foreach ($open as $ordem) {
            $this->binance->cancelarOrdem($symbol, $ordem['orderId']);
        }

        // Aguarda até a Binance confirmar remoção (até 3 segundos)
        for ($i = 0; $i < 30; $i++) {
            usleep(100000); // 100ms

            $restantes = $this->binance->getOpenOrders($symbol);

            if (!is_array($restantes)) {
                Log::warning("BotExecutor: falha ao confirmar remoção de ordens (tentativa {$i}).");
                continue;
            }

            if (empty($restantes)) {
                return true;
            }
        }

        Log::warning("BotExecutor: timeout ao aguardar remoção de ordens em {$symbol}.");
        return false;
    }

    // ============================================================
    // INICIALIZAÇÃO SEM DIVISÃO DE CAPITAL
    // ============================================================

    private function inicializarBotSemDivisao(string $userId): string
    {
        $saldos = $this->binance->getSaldos();

        if (!isset($saldos['balances'])) {
            Log::warning("BotExecutor [{$userId}]: falha ao buscar saldos na inicialização.");
            return "Erro ao buscar saldos. Inicialização abortada.";
        }

        $balances = collect($saldos['balances']);
        $saldoBRL = (float) ($balances->firstWhere('asset', 'BRL')['free'] ?? 0);
        $saldoBTC = (float) ($balances->firstWhere('asset', 'BTC')['free'] ?? 0);

        if ($saldoBRL < 10 && $saldoBTC <= 0) {
            return "Saldo insuficiente para iniciar o bot.";
        }

        $precoAtual = $this->binance->getPrecoBTC();

        if ($precoAtual <= 0) {
            Log::warning("BotExecutor [{$userId}]: preço inválido na inicialização ({$precoAtual}).");
            return "Preço inválido. Inicialização abortada.";
        }

        // Salto inicial sempre baseado nas métricas (ATR).
        $tendenciaInit = $this->analisarTendencia($precoAtual);
        $saltoInit     = $tendenciaInit['salto_dinamico'];

        // M8 — base de performance (helper único, critério free+locked igual
        // ao snapshot diário do cron; executar() também chama p/ o state que
        // já existia quando a coluna chegou — em produção é este o caminho).
        $this->fotografarBasePerformance(BotConfig::atual(), $precoAtual);

        $state = new BotState();
        $state->id_user           = $userId;
        $state->preco_referencia  = $precoAtual;
        $state->salto             = $saltoInit;
        $state->direcao_atual     = null;
        $state->contador_subidas  = 0;
        $state->contador_quedas   = 0;
        $state->contador_anterior = 0;
        $state->ativo             = 1;
        $state->save();

        $this->criarOrdensIniciaisSemDivisao($state, $precoAtual, $saldoBRL, $saldoBTC);

        Log::info("BotExecutor [{$userId}]: bot inicializado. Preço: {$precoAtual}, salto: {$saltoInit}.");

        return "Bot inicializado para o usuário {$userId}";
    }

    private function criarOrdensIniciaisSemDivisao(BotState $state, float $precoAtual, float $saldoBRL, float $saldoBTC): void
    {
        $salto       = $state->salto;
        $precoCompra = max(1.0, $precoAtual - $salto);
        $precoVenda  = $precoAtual + $salto;

        $config      = BotConfig::atual();
        $valorCompra = $saldoBRL * $config->nivel1;

        // Zera os ids antes de recriar: assim um id velho não fica fingindo que a
        // perna ainda existe (essencial para o guard de "par incompleto").
        $state->order_id_compra = null;
        $state->order_id_venda  = null;
        $state->par_criado_em   = now(); // M9 — idade do par p/ cooldown da adaptação

        if ($valorCompra >= $this->minNotionalEfetivo($config)) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        $quantidadeVenda = $saldoBTC * $config->nivel1;

        if ($quantidadeVenda > 0 && ($quantidadeVenda * $precoVenda) >= $this->minNotionalEfetivo($config)) {
            $orderVenda            = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();
    }

    // ============================================================
    // LÓGICA DE SUBIDA E QUEDA
    // ============================================================

    private function processarSubida(BotState $state, float $precoAtual): void
    {
        if ($state->direcao_atual !== 'up') {
            $state->contador_anterior = $state->contador_quedas;
            $state->contador_subidas  = 0;
            $state->contador_quedas   = 0;
        }

        $state->direcao_atual    = 'up';
        $state->contador_subidas++;
        $state->preco_referencia = $precoAtual;
    }

    private function processarQueda(BotState $state, float $precoAtual): void
    {
        if ($state->direcao_atual !== 'down') {
            $state->contador_anterior = $state->contador_subidas;
            $state->contador_subidas  = 0;
            $state->contador_quedas   = 0;
        }

        $state->direcao_atual    = 'down';
        $state->contador_quedas++;
        $state->preco_referencia = $precoAtual;
    }

    // ============================================================
    // PERCENTUAIS — níveis 1..7 lidos do banco. Após o nível 7
    // (sequência longa na mesma direção) usa nivel_final (default 8%):
    // tese de reversão graduada, apostar cada vez menos — mas com um
    // degrau final ESTÁVEL. O 1% fixo antigo, multiplicado pelos freios
    // de tendência (~0.5), gerava ordens de R$39 num saldo de R$7.8k:
    // abaixo do min_notional, dust de R$50 e grid funcionalmente morto.
    // ============================================================

    private function percentualPorSalto(int $contador, BotConfig $config): float
    {
        $nivelFinal = (float) ($config->nivel_final ?? 0);
        return $config->niveis()[max(1, $contador)]
            ?? ($nivelFinal > 0 ? $nivelFinal : 0.08);
    }

    // ============================================================
    // ANÁLISE DE TENDÊNCIA — MA21, EMA9, RSI14, ATR14, MACD, Bollinger
    // ============================================================

    public function analisarTendencia(float $precoAtual, bool $registrarLog = true): array
    {
        $fallback = ['fator_compra' => 0.5, 'fator_venda' => 0.5, 'rsi' => 50.0, 'atr' => 0.0, 'salto_dinamico' => 2500,
                     'macd' => 0.0, 'macd_signal' => 0.0, 'macd_hist' => 0.0,
                     'boll_upper' => 0.0, 'boll_lower' => 0.0, 'boll_pct_b' => 0.5, 'boll_width' => 0.0,
                     'trend_4h' => 0, 'ma21_4h' => 0.0, 'rsi_4h' => 50.0,
                     'ma21' => 0.0, 'ema9' => 0.0, 'distancia_pct' => 0.0, 'preco' => $precoAtual, 'tendencia' => 'neutra',
                     'fear_greed' => 50];

        $klines = $this->binance->getKlines(self::SYMBOL, '1h', 50);

        if (!is_array($klines) || count($klines) < 26) {
            Log::warning("BotExecutor: klines insuficientes para análise de tendência.");
            return $fallback;
        }

        $closes = array_map(fn($k) => (float) $k[4], $klines);

        $ma21 = array_sum(array_slice($closes, -21)) / 21;

        if ($ma21 <= 0) {
            Log::warning("BotExecutor: MA21 inválida ({$ma21}). Usando fallback.");
            return $fallback;
        }

        $ema9 = $this->calcularEMA($closes, 9);
        $rsi  = $this->calcularRSI($closes, 14);

        // // ATR calculado em candles diários — volatilidade na escala do grid
        // $klinesD  = $this->binance->getKlines(self::SYMBOL, '1d', 30);

        // Klines de 4h buscados UMA vez e reusados para ATR e tendência de médio prazo.
        $klines4h = $this->binance->getKlines(self::SYMBOL, '4h', 50);

        // ATR calculado em candles de 4h — volatilidade na escala do grid
        $atr      = 0.0;
        if (is_array($klines4h) && count($klines4h) >= 15) {
            $highsD  = array_map(fn($k) => (float) $k[2], $klines4h);
            $lowsD   = array_map(fn($k) => (float) $k[3], $klines4h);
            $closesD = array_map(fn($k) => (float) $k[4], $klines4h);
            $atr     = $this->calcularATR($highsD, $lowsD, $closesD, 14);
        }

        // ── ATR em escala DIÁRIA (mistura 30d/14d) → base estável do salto ──────
        // O ATR 4h acima reage a ~2 dias e chicoteia (uma calmaria recente ilude o
        // bot, que sub-dimensiona o grid). O salto precisa espaçar um movimento
        // inter-diário, então a base é diária: âncora 30d (regime) + 14d (reatividade),
        // com freio de ruptura. Fallback = ATR 4h se faltar kline diária.
        $atrSalto    = $atr;
        $atrLongaDia = 0.0;
        $atrCurtaDia = 0.0;
        $klines1d    = $this->binance->getKlines(self::SYMBOL, '1d', self::ATR_DIAS_LONG + 10);
        if (is_array($klines1d) && count($klines1d) >= self::ATR_DIAS_LONG + 1) {
            $h1d = array_map(fn($k) => (float) $k[2], $klines1d);
            $l1d = array_map(fn($k) => (float) $k[3], $klines1d);
            $c1d = array_map(fn($k) => (float) $k[4], $klines1d);
            $atrLongaDia = $this->calcularATR($h1d, $l1d, $c1d, self::ATR_DIAS_LONG);
            $atrCurtaDia = $this->calcularATR($h1d, $l1d, $c1d, self::ATR_DIAS_CURTA);
        }
        if ($atrLongaDia > 0 && $atrCurtaDia > 0) {
            // Freio de ruptura: se a curta disparou (>RUPTURA× a longa), regime
            // esquentou — segue a curta pra não sub-dimensionar o grid numa violência nova.
            $atrSalto = ($atrCurtaDia > self::ATR_RUPTURA * $atrLongaDia)
                ? $atrCurtaDia
                : self::ATR_PESO_LONG * $atrLongaDia + (1 - self::ATR_PESO_LONG) * $atrCurtaDia;
        }
        // // salto antigo: atr * 0.5, teto 15000 (gerava ~6k no ATR de 4h)
        // $saltoDin = $atr > 0
        //     ? max(1500, min(15000, (int) (round($atr * 0.5 / 500) * 500)))
        //     : 2500;

        $macdData  = $this->calcularMACD($closes);
        $bollData  = $this->calcularBollinger($closes, 21);

        // ── Salto dinâmico (tamanho do grid) ─────────────────────────────────
        // Base = volatilidade realizada diária (mistura 30d/14d em $atrSalto; antes
        // era ATR 4h, que chicoteava com a calmaria recente e sub-dimensionava o grid).
        // ATR_MULT escala a base; o modulador de Bollinger width ajusta ao regime atual:
        //   squeeze (bandas apertadas, ~0.02)  → reduz  → captura oscilações miúdas
        //   neutro   (~0.04)                   → ×1.0
        //   expansão (bandas largas, ~0.06+)   → aumenta → espera mais em mercado elétrico
        $base     = $atrSalto * self::ATR_MULT;
        $widthMod = max(0.65, min(1.5, 0.65 + ($bollData['width'] / 0.04) * 0.35));

        // ── M6: camadas por ATR — o regime define a FAIXA, o widthMod ajusta ──
        // fino dentro dela. Sem camadas (desligado no config), comporta como
        // antes: clamp global [SALTO_MIN, SALTO_MAX].
        $cfgCamadas = BotConfig::atual();
        [$camadaMin, $camadaMax] = $this->camadaSaltoPorAtr($atrSalto, $cfgCamadas);
        if ($atrSalto > 0) {
            $bruto = $base * $widthMod;
            $saltoDin = $cfgCamadas->camadas_atr_habilitado
                ? (int) round(max($camadaMin, min($camadaMax, $bruto)) / 500) * 500
                : (int) round(max(self::SALTO_MIN, min(self::SALTO_MAX, $bruto)) / 500) * 500;
        } else {
            $saltoDin = 2500;
        }

        // ── M5: piso de spread — a largura do grid (2× salto) precisa cobrir ──
        // a taxa total + lucro líquido mínimo. Sem isso o bot monta ciclos que
        // executam e ainda assim devolvem o ganho em taxa. Aplicado POR ÚLTIMO:
        // lucro mínimo é inegociável (passa por cima até do teto da camada).
        $saltoMinimoSpread = $this->saltoMinimoPorSpread($precoAtual, $cfgCamadas);
        if ($saltoMinimoSpread > $saltoDin) {
            $saltoDin = (int) ceil($saltoMinimoSpread / 500) * 500;
        }

        // ── Tendência 4h: calcula os valores (os boosts entram no acúmulo abaixo) ──
        $trend4h = 0;
        $ma21_4h = 0.0;
        $rsi4h   = 50.0;
        if (is_array($klines4h) && count($klines4h) >= 22) {
            $closes4h = array_map(fn($k) => (float) $k[4], $klines4h);
            $ma21_4h  = array_sum(array_slice($closes4h, -21)) / 21;
            $ema9_4h  = $this->calcularEMA($closes4h, 9);
            $rsi4h    = $this->calcularRSI($closes4h, 14);

            if ($precoAtual > $ma21_4h && $ema9_4h > $ma21_4h)     $trend4h =  1;
            elseif ($precoAtual < $ma21_4h && $ema9_4h < $ma21_4h) $trend4h = -1;
        }

        // ── Fatores: base responsiva + acúmulo de ajustes, CLAMP ÚNICO no final ──
        // Antes cada indicador clampava na hora (washout + dependência de ordem).
        // Agora soma-se tudo e clampa-se uma vez só, então todo indicador conta.

        // Base: distância da MA21 (normalizador 0.03 → 3% de desvio = swing cheio).
        // Antes era 0.30 (precisava 30%), o que deixava a base sempre em ~0.50.
        $distancia  = ($precoAtual - $ma21) / $ma21;
        $baseCompra = 0.5 - ($distancia / 0.03);  // preço acima da média → compra menos
        $baseVenda  = 0.5 + ($distancia / 0.03);  // preço acima da média → vende mais

        $ajCompra = 0.0;
        $ajVenda  = 0.0;

        // EMA9 × MA21 (±0.10)
        $boost     = $ema9 > $ma21 ? 0.10 : -0.10;
        $ajCompra -= $boost;
        $ajVenda  += $boost;

        // RSI 1h: ±0.20/∓0.10 nos extremos
        if ($rsi <= 30)     { $ajCompra += 0.20; $ajVenda  -= 0.10; }
        elseif ($rsi >= 70) { $ajVenda  += 0.20; $ajCompra -= 0.10; }

        // MACD com DEADBAND: só conta se |histograma| > 0.1% do preço (ignora ruído)
        $macdDeadband = $precoAtual * 0.001;
        if (abs($macdData['histogram']) > $macdDeadband) {
            if ($macdData['macd'] > $macdData['signal']) { $ajVenda  += 0.10; $ajCompra -= 0.05; }
            else                                         { $ajCompra += 0.10; $ajVenda  -= 0.05; }
        }

        // Bollinger %B: ±0.10 nos extremos
        if ($bollData['pct_b'] <= 0.20)     { $ajCompra += 0.10; }
        elseif ($bollData['pct_b'] >= 0.80) { $ajVenda  += 0.10; }

        // Tendência 4h: ±0.15/∓0.08 (reforçado — antes ±0.10/∓0.05). Moderado de
        // propósito: o grid precisa de simetria compra/venda; reforço demais
        // paralisaria a captura de spread.
        if ($trend4h === 1)      { $ajVenda  += 0.15; $ajCompra -= 0.08; }
        elseif ($trend4h === -1) { $ajCompra += 0.15; $ajVenda  -= 0.08; }

        // RSI 4h: +0.10 nos extremos
        if ($rsi4h <= 35)     $ajCompra += 0.10;
        elseif ($rsi4h >= 65) $ajVenda  += 0.10;

        // ── Fear & Greed Index (Alternative.me, cache 1h) ───────────────
        // Tese de reversão contrarian: medo extremo (<25) → compra mais / vende
        // menos; ganância extrema (>75) → vende mais / compra menos. Zona neutra
        // (40-60) não altera. Pesos moderados pra somar aos outros indicadores.
        $fng = app(\App\Services\FearGreedService::class)->atual();
        $fngVal = $fng['value'] ?? 50;
        if ($fngVal <= 25)      { $ajCompra += 0.15; $ajVenda  -= 0.08; }
        elseif ($fngVal >= 75)  { $ajVenda  += 0.15; $ajCompra -= 0.08; }

        // Clamp único no final
        $fatorCompra = max(0.45, min(1.0, $baseCompra + $ajCompra));
        $fatorVenda  = max(0.45, min(1.0, $baseVenda  + $ajVenda));

        // Rótulo de tendência (alta/baixa/neutra) para exibição
        if ($distancia > 0.05 && $ema9 > $ma21)      $tendencia = 'alta';
        elseif ($distancia < -0.05 && $ema9 < $ma21) $tendencia = 'baixa';
        else                                          $tendencia = 'neutra';

        // Log só quando o bot executa de verdade; o dashboard chama com $registrarLog=false
        if ($registrarLog) {
            Log::info(sprintf(
                "BotExecutor: MA21=%.0f EMA9=%.0f RSI=%.1f ATR4h=%.0f salto=%d ATRdL=%.0f ATRdC=%.0f wMod=%.2f dist=%.2f%% MACD=%.0f sig=%.0f Boll%%B=%.2f W=%.3f trend4h=%+d RSI4h=%.1f MA21_4h=%.0f fC=%.2f fV=%.2f F&G=%d camada=%d-%d spread=%.2f%%",
                $ma21, $ema9, $rsi, $atr, $saltoDin, $atrLongaDia, $atrCurtaDia, $widthMod, $distancia * 100,
                $macdData['macd'], $macdData['signal'], $bollData['pct_b'], $bollData['width'],
                $trend4h, $rsi4h, $ma21_4h, $fatorCompra, $fatorVenda, $fngVal,
                $camadaMin, $camadaMax, ($saltoDin * 2 / max(1, $precoAtual)) * 100
            ));
        }

        return [
            'fator_compra'   => $fatorCompra,
            'fator_venda'    => $fatorVenda,
            'rsi'            => $rsi,
            'atr'            => $atr,
            'atr_longa'      => round($atrLongaDia, 2),
            'atr_curta'      => round($atrCurtaDia, 2),
            'atr_salto'      => round($atrSalto, 2),
            'salto_dinamico' => $saltoDin,
            // M5/M6 — faixa da camada vigente e spread efetivo do grid
            'camada_salto'      => [$camadaMin, $camadaMax],
            'spread_pct'        => round(($saltoDin * 2 / max(1, $precoAtual)) * 100, 3),
            'salto_minimo_spread' => (int) ceil($saltoMinimoSpread / 500) * 500,
            'macd'           => $macdData['macd'],
            'macd_signal'    => $macdData['signal'],
            'macd_hist'      => $macdData['histogram'],
            'boll_upper'     => $bollData['upper'],
            'boll_lower'     => $bollData['lower'],
            'boll_pct_b'     => $bollData['pct_b'],
            'boll_width'     => $bollData['width'],
            'trend_4h'       => $trend4h,
            'ma21_4h'        => $ma21_4h,
            'rsi_4h'         => $rsi4h,
            // campos de exibição (dashboard)
            'ma21'           => $ma21,
            'ema9'           => $ema9,
            'distancia_pct'  => round($distancia * 100, 2),
            'preco'          => $precoAtual,
            'tendencia'      => $tendencia,
            'fear_greed'     => $fngVal,
        ];
    }

    // ============================================================
    // M2/M3 — TENDÊNCIA FORTE (todos os indicadores alinhados)
    // ============================================================
    // "Forte" exige o CONJUNTO completo — meia-tendência não conta. Usado
    // pelo controle de alocação (pisos) e pelo painel.

    /** M2 — bull protection: EMA9>MA21, RSI4h>60, preço>MA21_4h, MACD>signal, trend4h+. */
    public function mercadoForteAltista(array $t): bool
    {
        return $t['ema9'] > $t['ma21']
            && $t['rsi_4h'] > 60
            && $t['preco'] > $t['ma21_4h']
            && $t['macd'] > $t['macd_signal']
            && $t['trend_4h'] === 1;
    }

    /** M3 — bear protection: espelho do mercadoForteAltista. */
    public function mercadoForteBaixista(array $t): bool
    {
        return $t['ema9'] < $t['ma21']
            && $t['rsi_4h'] < 40
            && $t['preco'] < $t['ma21_4h']
            && $t['macd'] < $t['macd_signal']
            && $t['trend_4h'] === -1;
    }

    // ============================================================
    // M1/M2/M3 — CONTROLE PATRIMONIAL BTC/BRL
    // ============================================================
    // valorBTC = saldoBTC × preço; patrimônio = valorBTC + saldoBRL;
    // percentuais contra os targets (50/50 default). Zonas de desvio:
    //   > alerta (70%)  → reduz o lado majoritário e amplia o contrário;
    //   > bloqueio (80%) → bloqueia ordens NORMAIS do lado majoritário
    //                      (all-in de exaustão é a ordem excepcional e passa).
    // Em tendência FORTE, os pisos btc/brl_minimo_tendencia (40%) travam o
    // lado que interessa com bloqueio TOTAL (nem all-in passa — piso é piso):
    // alta forte não deixa o %BTC cair do piso; baixa forte, o %BRL.
    // Precedência: zonas de alerta/bloqueio do M1 não são desfazíveis pelas
    // modulações do M2/M3 — vender em força extrema (BTC alto) é realizar no
    // melhor momento; o piso do M2 protege o lado de BAIXO, não o de cima.
    public function calcularAlocacao(float $saldoBRL, float $saldoBTC, float $precoAtual, BotConfig $config, array $tendencia): array
    {
        $valorBTC   = $saldoBTC * $precoAtual;
        $patrimonio = $valorBTC + $saldoBRL;
        $pctBTC     = $patrimonio > 0 ? ($valorBTC / $patrimonio) * 100 : 50.0;
        $pctBRL     = 100.0 - $pctBTC;

        $fatorCompra = 1.0;
        $fatorVenda  = 1.0;
        $bloquearCompraNormal = false;
        $bloquearVendaNormal  = false;
        $bloquearCompraTotal  = false;
        $bloquearVendaTotal   = false;
        $motivos = [];
        $zona = 'equilibrado';
        $forte = null;

        if ($this->mercadoForteAltista($tendencia)) {
            $forte = 'alta';
        } elseif ($this->mercadoForteBaixista($tendencia)) {
            $forte = 'baixa';
        }

        if ($patrimonio > 0) {
            $alerta   = (float) $config->limite_alerta_pct;
            $bloqueio = (float) $config->limite_bloqueio_pct;

            // ── M1: desvios contra o target ─────────────────────────────
            if ($pctBTC > $bloqueio) {
                $zona = 'bloqueio_btc_alto';
                $fatorCompra = 0.0;
                $fatorVenda  = 1.5;
                $bloquearCompraNormal = true;
                $motivos[] = sprintf('BTC %.1f%% > limite %.0f%%: compra normal bloqueada, venda ampliada', $pctBTC, $bloqueio);
            } elseif ($pctBTC > $alerta) {
                $zona = 'alerta_btc_alto';
                $fatorCompra = 0.5;
                $fatorVenda  = 1.5;
                $motivos[] = sprintf('BTC %.1f%% > alerta %.0f%%: compra reduzida, venda ampliada', $pctBTC, $alerta);
            } elseif ($pctBRL > $bloqueio) {
                $zona = 'bloqueio_brl_alto';
                $fatorVenda  = 0.0;
                $fatorCompra = 1.5;
                $bloquearVendaNormal = true;
                $motivos[] = sprintf('BRL %.1f%% > limite %.0f%%: venda normal bloqueada, compra ampliada', $pctBRL, $bloqueio);
            } elseif ($pctBRL > $alerta) {
                $zona = 'alerta_brl_alto';
                $fatorVenda  = 0.5;
                $fatorCompra = 1.5;
                $motivos[] = sprintf('BRL %.1f%% > alerta %.0f%%: venda reduzida, compra ampliada', $pctBRL, $alerta);
            }

            // ── M2/M3: tendência forte protege o lado vencedor ──────────
            $btcAlto = in_array($zona, ['alerta_btc_alto', 'bloqueio_btc_alto'], true);
            $brlAlto = in_array($zona, ['alerta_brl_alto', 'bloqueio_brl_alto'], true);

            if ($forte === 'alta') {
                $pisoBTC = (float) $config->btc_minimo_tendencia_alta;
                if (!$btcAlto) {
                    // Guard de meta (espelho da baixa forte): BRL bem abaixo do
                    // alvo → freio de venda suavizado (0.8) — o rebalanceamento
                    // pede é segurar caixa pra recomprar; freio forte × nível
                    // baixo gerava perna SELL dust.
                    $metaBrl  = (float) ($config->target_brl_pct ?? 0);
                    $guardPct = (float) ($config->guard_meta_pct ?? 0);
                    $brlAbaixoMeta = $guardPct > 0 && $metaBrl > 0
                        && $pctBRL <= $metaBrl * ($guardPct / 100.0);
                    $fatorVenda = min($fatorVenda, $brlAbaixoMeta ? 0.8 : 0.5);
                    $motivos[]  = $brlAbaixoMeta
                        ? sprintf('alta forte suavizada: BRL %.1f%% ≤ %.0f%% da meta %.0f%% (freio 0.8)', $pctBRL, $guardPct, $metaBrl)
                        : 'alta forte: vendas reduzidas';
                    if (!$bloquearCompraNormal) {
                        $fatorCompra = max($fatorCompra, 1.2);
                    }
                }
                if ($pctBTC <= $pisoBTC) {
                    $bloquearVendaTotal = true;
                    $fatorVenda = 0.0;
                    $motivos[]  = sprintf('alta forte com BTC %.1f%% ≤ piso %.0f%%: venda bloqueada (acumular)', $pctBTC, $pisoBTC);
                }
            } elseif ($forte === 'baixa') {
                $pisoBRL = (float) $config->brl_minimo_tendencia_baixa;
                if (!$brlAlto) {
                    // Guard de meta: BTC bem abaixo do alvo (default ≤80% da
                    // meta, i.e. ≤40% com alvo 50) → freio de compra suavizado
                    // (0.8 em vez de 0.5). Sem isso o bot freava compras pela
                    // metade exatamente quando o rebalanceamento pedia recomprar
                    // barato — e o freio ×0.5 multiplicado com níveis 6-8%
                    // produzia ordens de R$39-R$110 (dust).
                    $metaBtc  = (float) ($config->target_btc_pct ?? 0);
                    $guardPct = (float) ($config->guard_meta_pct ?? 0);
                    $btcAbaixoMeta = $guardPct > 0 && $metaBtc > 0
                        && $pctBTC <= $metaBtc * ($guardPct / 100.0);
                    $fatorCompra = min($fatorCompra, $btcAbaixoMeta ? 0.8 : 0.5);
                    $motivos[]   = $btcAbaixoMeta
                        ? sprintf('baixa forte suavizada: BTC %.1f%% ≤ %.0f%% da meta %.0f%% (freio 0.8)', $pctBTC, $guardPct, $metaBtc)
                        : 'baixa forte: compras reduzidas';
                    if (!$bloquearVendaNormal) {
                        $fatorVenda = max($fatorVenda, 1.2);
                    }
                }
                if ($pctBRL <= $pisoBRL) {
                    $bloquearCompraTotal = true;
                    $fatorCompra = 0.0;
                    $motivos[]   = sprintf('baixa forte com BRL %.1f%% ≤ piso %.0f%%: compra bloqueada (segurar caixa)', $pctBRL, $pisoBRL);
                }
            }
        }

        return [
            'pct_btc'     => round($pctBTC, 2),
            'pct_brl'     => round($pctBRL, 2),
            'valor_btc'   => round($valorBTC, 2),
            'patrimonio'  => round($patrimonio, 2),
            'target_btc'  => (float) $config->target_btc_pct,
            'desvio_btc'  => round($pctBTC - (float) $config->target_btc_pct, 2),
            'fator_compra' => round($fatorCompra, 3),
            'fator_venda'  => round($fatorVenda, 3),
            'bloquear_compra_normal' => $bloquearCompraNormal,
            'bloquear_venda_normal'  => $bloquearVendaNormal,
            'bloquear_compra_total'  => $bloquearCompraTotal,
            'bloquear_venda_total'   => $bloquearVendaTotal,
            'zona'    => $zona,
            'forte'   => $forte,
            'motivos' => $motivos,
        ];
    }

    // ============================================================
    // M4 — MODO "PREPARAR SUBIDA" AUTOMÁTICO
    // ============================================================
    // Ativa com o conjunto COMPLETO de força: EMA9>MA21, RSI4h>65, MACD>signal,
    // trend4h+ e F&G>60. Desativa com qualquer fraqueza: EMA9<MA21, RSI4h<55,
    // MACD cruzando pra baixo ou trend4h fora de alta. Histerese proposital
    // (ativa em 65 / desativa em 55) pra não liga/desliga em ruído.
    // Convive com o gatilho MANUAL: o manual (state->modo_subida) tem
    // precedência e fluxo próprio (inibe vendas por completo). O automático
    // apenas modula a criação de ordens (venda ×0,3 · compra ×1,3).
    private function avaliarModoSubidaAutomatico(BotState $state, array $t, BotConfig $config): void
    {
        if (!$config->modo_subida_auto_habilitado) {
            if ($state->modo_subida_auto) {
                $state->modo_subida_auto = false;
                $state->save();
                Log::info('BotExecutor: MODO SUBIDA AUTO desativado (desabilitado no config).');
            }
            return;
        }

        $fng = (int) ($t['fear_greed'] ?? 50);

        if (!$state->modo_subida_auto) {
            $ativa = $t['ema9'] > $t['ma21']
                && $t['rsi_4h'] > self::SUBIDA_AUTO_RSI_ENTRA
                && $t['macd'] > $t['macd_signal']
                && $t['trend_4h'] === 1
                && $fng > self::SUBIDA_AUTO_FNG;

            if ($ativa) {
                $state->modo_subida_auto = true;
                $state->save();
                Log::info(sprintf(
                    'BotExecutor: MODO SUBIDA AUTO ATIVADO — EMA9>MA21, RSI4h=%.1f, MACD>signal, trend4h=+, F&G=%d.',
                    $t['rsi_4h'], $fng
                ));
            }
            return;
        }

        $desativa = $t['ema9'] < $t['ma21']
            || $t['rsi_4h'] < self::SUBIDA_AUTO_RSI_SAI
            || $t['macd'] < $t['macd_signal']
            || $t['trend_4h'] !== 1;

        if ($desativa) {
            $state->modo_subida_auto = false;
            $state->save();
            Log::info(sprintf(
                'BotExecutor: MODO SUBIDA AUTO DESATIVADO — RSI4h=%.1f, trend4h=%+d, F&G=%d.',
                $t['rsi_4h'], $t['trend_4h'], $fng
            ));
        }
    }

    // ============================================================
    // M8 — LOG DETALHADO DO CICLO (uma linha por execução)
    // ============================================================
    // Preço, saldos, valor em BTC, patrimônio, alocação vs targets, desvios,
    // ATR, salto, RSI/RSI4h, MACD, F&G, modos de subida e direção. Falha ao
    // buscar saldos NÃO aborta o ciclo (só a linha fica sem a parte de saldo).
    private function logCicloDetalhado(string $userId, BotState $state, float $preco, array $t, BotConfig $config): void
    {
        $brl = null;
        $btc = null;

        try {
            $saldos = $this->binance->getSaldos();
            if (isset($saldos['balances'])) {
                $b   = collect($saldos['balances']);
                $brl = (float) ($b->firstWhere('asset', 'BRL')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BRL')['locked'] ?? 0);
                $btc = (float) ($b->firstWhere('asset', 'BTC')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BTC')['locked'] ?? 0);
            }
        } catch (\Throwable $e) {
            Log::warning("BotExecutor [{$userId}]: log de ciclo sem saldos — " . $e->getMessage());
        }

        if ($brl === null || $btc === null) {
            Log::info(sprintf(
                'CICLO [%s]: preco=%.0f (saldos indisponíveis) ATR=%.0f salto=%d RSI=%.1f RSI4h=%.1f MACDhist=%+.0f F&G=%d subidaManual=%d subidaAuto=%d dir=%s',
                $userId, $preco, $t['atr_salto'], $t['salto_dinamico'], $t['rsi'], $t['rsi_4h'],
                $t['macd_hist'], $t['fear_greed'], $state->modo_subida ? 1 : 0,
                $state->modo_subida_auto ? 1 : 0, $state->direcao_atual ?? '—'
            ));
            return;
        }

        $aloc = $this->calcularAlocacao($brl, $btc, $preco, $config, $t);

        Log::info(sprintf(
            'CICLO [%s]: preco=%.0f BRL=%.2f BTC=%.8f valBTC=%.2f patr=%.2f %%BTC=%.1f/%.0f(desvio %+.1f) %%BRL=%.1f ATR=%.0f salto=%d RSI=%.1f RSI4h=%.1f MACDhist=%+.0f F&G=%d subidaManual=%d subidaAuto=%d dir=%s zona=%s%s',
            $userId, $preco, $brl, $btc, $btc * $preco, $aloc['patrimonio'],
            $aloc['pct_btc'], $aloc['target_btc'], $aloc['desvio_btc'], $aloc['pct_brl'],
            $t['atr_salto'], $t['salto_dinamico'], $t['rsi'], $t['rsi_4h'], $t['macd_hist'],
            $t['fear_greed'], $state->modo_subida ? 1 : 0, $state->modo_subida_auto ? 1 : 0,
            $state->direcao_atual ?? '—', $aloc['zona'],
            $aloc['motivos'] ? ' · ' . implode(' · ', $aloc['motivos']) : ''
        ));
    }

    // ============================================================
    // M8 — FOTOGRAFIA DA BASE DE PERFORMANCE (uma vez, persistida)
    // ============================================================
    // Ponto de partida do painel de performance. Saldos free+locked (mesmo
    // critério do snapshot diário do cron e do log do ciclo). Falha da
    // Binance aqui NUNCA aborta o ciclo — tenta de novo no próximo (a base
    // segue null até conseguir).
    private function fotografarBasePerformance(BotConfig $config, float $precoAtual): void
    {
        if ($config->base_iniciada_em !== null && $config->patrimonio_inicial !== null) {
            return;
        }

        try {
            $saldos = $this->binance->getSaldos();
            if (!isset($saldos['balances'])) {
                return;
            }
            $b   = collect($saldos['balances']);
            $brl = (float) ($b->firstWhere('asset', 'BRL')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BRL')['locked'] ?? 0);
            $btc = (float) ($b->firstWhere('asset', 'BTC')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BTC')['locked'] ?? 0);

            $config->patrimonio_inicial = round($brl + $btc * $precoAtual, 2);
            $config->btc_inicial        = $btc;
            $config->brl_inicial        = round($brl, 2);
            $config->base_iniciada_em   = now();
            $config->save();

            Log::info(sprintf(
                'BotExecutor: base de performance fotografada — patr=%.2f BTC=%.8f BRL=%.2f.',
                $config->patrimonio_inicial, $btc, $brl
            ));
        } catch (\Throwable $e) {
            Log::warning('BotExecutor: falha ao fotografar base de performance (tenta de novo no próximo ciclo) — ' . $e->getMessage());
        }
    }

    // ============================================================
    // M6/M5 — ajudantes do salto (camada por ATR · piso por spread)
    // ============================================================

    /** Faixa [min, max] do salto conforme a camada do ATR diário (mistura 30d/14d). */
    private function camadaSaltoPorAtr(float $atr): array
    {
        foreach (self::CAMADAS_ATR as [$atrAte, $min, $max]) {
            if ($atr <= $atrAte) {
                return [$min, $max];
            }
        }
        return [self::SALTO_MIN, self::SALTO_MAX];
    }

    /** Salto mínimo para a largura do grid (2× salto) cobrir taxa + lucro líquido mínimo. */
    private function saltoMinimoPorSpread(float $precoAtual, ?BotConfig $config = null): float
    {
        $cfg = $config ?? BotConfig::atual();
        $spreadPct = (float) $cfg->spread_minimo_pct;
        if ($spreadPct <= 0) {
            // Sem spread explícito: taxa total configurada (M5) + lucro líquido mínimo.
            $taxa      = (float) $cfg->taxa_total_pct;
            $spreadPct = ($taxa > 0 ? $taxa : self::TAXA_TOTAL_PCT) + self::LUCRO_LIQUIDO_MIN_PCT;
        }
        return $precoAtual * ($spreadPct / 100.0) / 2.0;
    }

    // ============================================================
    // M9 — ADAPTAÇÃO DO GRID AO REGIME (reposicionamento por ATR)
    // ============================================================
    // O salto só era aplicado na CRIAÇÃO do par; um par podia viver dias com
    // grid desproporcional ao regime (apertado pós-explosão de vol → ciclos
    // com lucro líquido insuficiente; largo pós-calmaria → ordens nunca
    // alcançadas = "poucas operações"). Aqui, com o par completo, o grid é
    // reposicionado MANTENDO O CENTRO, aproximando/afastando as pernas para
    // o salto que o ATR atual pede. Nunca se move uma perna sozinha (quebra
    // a reconstrução do fill price precoRestante ∓ 2×salto).
    //
    // Travas anti-churn: (1) histerese de divergência; (2) persistência da
    // divergência (cache); (3) cooldown desde a criação do par; (4) preço
    // nunca além da perna velha (dist ≥ salto do par = execução iminente →
    // deixa executar) e clamp do anel garantindo que a BUY nova nasce abaixo
    // do mercado e a SELL acima (LIMIT válido).
    // Reposição NÃO registra direção — contadores intactos (mesmo caminho do
    // "cancelado por range → recria par sem registrar direção").
    private function adaptarGridARegime(string $userId, BotState $state, array $open, float $precoAtual, array $tendencia, BotConfig $config): string
    {
        $nada = 'Duas ou mais ordens ativas. Nada a fazer.';

        // Pré-condição: par completo exato (1 BUY + 1 SELL). Perna única é
        // território do state machine — nunca mexe.
        $buy = null;
        $sell = null;
        foreach ($open as $o) {
            if (($o['side'] ?? '') === 'BUY') $buy = $o;
            elseif (($o['side'] ?? '') === 'SELL') $sell = $o;
        }
        if ($buy === null || $sell === null || count($open) !== 2) {
            return $nada;
        }

        $saltoPar   = (float) $state->salto;
        $saltoIdeal = (int) $tendencia['salto_dinamico'];
        if ($saltoPar <= 0 || $saltoIdeal <= 0) {
            return $nada;
        }

        // Âncora = CENTRO DO PAR (preço de criação — as pernas são simétricas),
        // NUNCA o preço atual: recentralizar no valor atual faria o grid
        // "perseguir" o preço a cada adaptação e nenhuma ordem executaria.
        // Ex.: par 400k/420k centrado em 410k com ATR novo de 3k → 407k/413k,
        // mesmo que o BTC esteja lá em 419k.
        $centro = ((float) $buy['price'] + (float) $sell['price']) / 2.0;
        $dist   = abs($precoAtual - $centro);

        // Preço colado/alem da perna VELHA (dist ≥ salto do par): execução
        // iminente ou range rompido — não mexe (zombie guard resolve o resto).
        if ($dist >= $saltoPar) {
            Cache::forget(self::CACHE_ADAPT_DESDE);
            return $nada;
        }

        // Salto final da reposição, ancoraado no centro:
        //  · preço DENTRO da banda nova → salto ideal puro;
        //  · preço no ANEL entre a banda nova e a velha → "ordem limite":
        //    clampa no maior salto que ainda cerca o preço com o centro
        //    intacto (perna próxima fica ≥ 25% do salto novo além do preço).
        //    Sem isso, ao aproximar as pernas uma delas nasceria do lado
        //    errado do mercado (LIMIT que executa na hora como taker).
        if ($dist <= $saltoIdeal) {
            $saltoNovo = $saltoIdeal;
        } else {
            $margem    = max(500, (int) (0.25 * $saltoIdeal));
            $saltoNovo = min(
                (int) $saltoPar,
                (int) ceil(($dist + $margem) / 500) * 500
            );
        }

        if ($saltoNovo <= 0 || $saltoNovo === (int) $saltoPar) {
            return $nada;
        }

        // (1) Histerese: divergência mínima (default 30%) — medida contra o
        // salto que SERIA APLICADO. No anel ele sai clamped; se o clamp deixar
        // o grid quase igual ao atual, não há ganho real e não se mexe.
        $divergenciaPct = abs($saltoNovo - $saltoPar) / $saltoPar * 100.0;
        $histerese = (float) $config->adapt_histerese_pct ?: self::ADAPT_HISTERESE_PCT;
        if ($divergenciaPct < $histerese) {
            Cache::forget(self::CACHE_ADAPT_DESDE); // voltou à tolerância — zera a persistência
            return $nada;
        }

        // (2) Persistência: a divergência precisa durar (evita reposicionar por spike).
        $desde = Cache::get(self::CACHE_ADAPT_DESDE);
        if (!$desde) {
            Cache::put(self::CACHE_ADAPT_DESDE, now()->timestamp, now()->addHours(6));
            return sprintf('Grid divergente do regime (%.1f%%) — cronometrando persistência.', $divergenciaPct);
        }
        $persistenciaMin = (int) ($config->adapt_persistencia_min ?: self::ADAPT_PERSISTENCIA_MIN);
        $restamPersist = ($persistenciaMin * 60) - (now()->timestamp - (int) $desde);
        if ($restamPersist > 0) {
            return sprintf('Grid divergente do regime (%.1f%%) — persistência em %d min.', $divergenciaPct, (int) ceil($restamPersist / 60));
        }

        // (3) Cooldown desde a criação do par atual.
        $cooldownMin = (int) ($config->adapt_cooldown_min ?: self::ADAPT_COOLDOWN_MIN);
        if ($state->par_criado_em) {
            $idadeMin = (int) Carbon::parse($state->par_criado_em)->diffInMinutes(now());
            if ($idadeMin < $cooldownMin) {
                return sprintf('Grid divergente do regime (%.1f%%) — cooldown em %d min.', $divergenciaPct, $cooldownMin - $idadeMin);
            }
        }

        // Reposiciona: cancela o par e recria centrado no MESMO centro com o
        // salto NOVO (forçado — não o da análise, que pode divergir do clamp),
        // sem registrar direção (contadores intactos). O clamp do anel acima
        // já garantiu BUY < mercado < SELL no par novo (LIMIT válido).
        if (!$this->limparTodasOrdensEAguardar(self::SYMBOL)) {
            Log::warning("BotExecutor [{$userId}]: adaptação do grid — timeout ao cancelar par. Par mantido.");
            return 'Adaptação do grid: timeout no cancelamento. Par mantido.';
        }
        Cache::forget(self::CACHE_ADAPT_DESDE);

        if (!$this->criarOrdensNovas($state, $centro, false, $tendencia, $saltoNovo)) {
            return 'Adaptação do grid: falha ao recriar par (próximo ciclo recria no fluxo normal).';
        }

        $clamp = $dist > $saltoIdeal ? ' (clamp do anel)' : '';
        Log::info(sprintf(
            'BotExecutor [%s]: GRID ADAPTADO AO REGIME — salto %d→%d%s (divergência %.1f%%, ATR=%.0f), centro=%.0f mantido.',
            $userId, (int) $saltoPar, $saltoNovo, $clamp, $divergenciaPct, $tendencia['atr_salto'], $centro
        ));
        return sprintf('Grid adaptado ao regime: salto %d → %d%s (centro mantido).', (int) $saltoPar, $saltoNovo, $clamp);
    }

    private function calcularEMA(array $closes, int $periodo): float
    {
        $k   = 2 / ($periodo + 1);
        $ema = $closes[0];
        foreach (array_slice($closes, 1) as $close) {
            $ema = $close * $k + $ema * (1 - $k);
        }
        return $ema;
    }

    private function calcularRSI(array $closes, int $periodo = 14): float
    {
        if (count($closes) < $periodo + 1) return 50.0;

        $changes = [];
        for ($i = 1; $i < count($closes); $i++) {
            $changes[] = $closes[$i] - $closes[$i - 1];
        }

        $avgGain = $avgLoss = 0.0;
        for ($i = 0; $i < $periodo; $i++) {
            if ($changes[$i] > 0) $avgGain += $changes[$i];
            else                  $avgLoss += abs($changes[$i]);
        }
        $avgGain /= $periodo;
        $avgLoss /= $periodo;

        for ($i = $periodo; $i < count($changes); $i++) {
            $gain    = $changes[$i] > 0 ? $changes[$i] : 0.0;
            $loss    = $changes[$i] < 0 ? abs($changes[$i]) : 0.0;
            $avgGain = ($avgGain * ($periodo - 1) + $gain) / $periodo;
            $avgLoss = ($avgLoss * ($periodo - 1) + $loss) / $periodo;
        }

        if ($avgLoss == 0) return 100.0;
        return round(100 - (100 / (1 + $avgGain / $avgLoss)), 2);
    }

    private function calcularMACD(array $closes): array
    {
        if (count($closes) < 26) return ['macd' => 0.0, 'signal' => 0.0, 'histogram' => 0.0];

        $k12 = 2 / 13; $k26 = 2 / 27; $k9 = 2 / 10;
        $e12 = $e26 = $closes[0];
        $macdSeries = [];

        foreach ($closes as $c) {
            $e12 = $c * $k12 + $e12 * (1 - $k12);
            $e26 = $c * $k26 + $e26 * (1 - $k26);
            $macdSeries[] = $e12 - $e26;
        }

        $signal = $macdSeries[0];
        foreach ($macdSeries as $m) {
            $signal = $m * $k9 + $signal * (1 - $k9);
        }

        $macd = end($macdSeries);
        return ['macd' => round($macd, 2), 'signal' => round($signal, 2), 'histogram' => round($macd - $signal, 2)];
    }

    private function calcularBollinger(array $closes, int $periodo = 21): array
    {
        if (count($closes) < $periodo) return ['upper' => 0.0, 'lower' => 0.0, 'width' => 0.0, 'pct_b' => 0.5];

        $slice = array_slice($closes, -$periodo);
        $ma    = array_sum($slice) / $periodo;
        $std   = sqrt(array_sum(array_map(fn($c) => ($c - $ma) ** 2, $slice)) / $periodo);
        $upper = $ma + 2 * $std;
        $lower = $ma - 2 * $std;
        $range = $upper - $lower;
        $pctB  = $range > 0 ? (end($closes) - $lower) / $range : 0.5;
        $width = $ma > 0 ? $range / $ma : 0.0;

        return [
            'upper' => round($upper, 2),
            'lower' => round($lower, 2),
            'width' => round($width, 4),
            'pct_b' => round($pctB, 4),
        ];
    }

    private function calcularATR(array $highs, array $lows, array $closes, int $periodo = 14): float
    {
        $n = count($closes);
        if ($n < 2) return 0.0;

        $trs = [];
        for ($i = 1; $i < $n; $i++) {
            $trs[] = max(
                $highs[$i]  - $lows[$i],
                abs($highs[$i]  - $closes[$i - 1]),
                abs($lows[$i]   - $closes[$i - 1])
            );
        }

        $atr = array_sum(array_slice($trs, 0, $periodo)) / min($periodo, count($trs));
        foreach (array_slice($trs, $periodo) as $tr) {
            $atr = ($atr * ($periodo - 1) + $tr) / $periodo;
        }

        return round($atr, 2);
    }

    // ============================================================
    // CRIAÇÃO DE NOVAS ORDENS
    // ============================================================

    private function criarOrdensNovas(BotState $state, float $precoAtual, bool $soCompra = false, ?array $tendencia = null, ?int $saltoForcado = null): bool
    {
        $config = BotConfig::atual();
        // Reusa a análise do ciclo quando disponível (executar() já calculou —
        // evita re-buscar klines no mesmo minuto); só calcula quando chamado
        // isolado (ex.: inicialização).
        $tendencia = $tendencia ?? $this->analisarTendencia($precoAtual);

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-TENDENCIA', [
            'fatorCompraOriginal' => $tendencia['fator_compra'],
            'fatorVendaOriginal' => $tendencia['fator_venda'],
            'rsi' => $tendencia['rsi'],
            'rsi4h' => $tendencia['rsi_4h'],
            'macd' => $tendencia['macd'],
            'macdSignal' => $tendencia['macd_signal'],
            'macdHist' => $tendencia['macd_hist'],
            'fearGreed' => $tendencia['fear_greed'],
            'bollPctB' => $tendencia['boll_pct_b'],
            'trend4h' => $tendencia['trend_4h'],
            'saltoDinamico' => $tendencia['salto_dinamico']
        ]);

        // Salto sempre baseado nas métricas (ATR + Bollinger width). Não há mais salto fixo.
        // $saltoForcado (M9): a adaptação do grid passa o salto clamped pelo
        // anel — o da análise pode não cercar o preço com o centro mantido.
        // M5 (defesa): o piso de spread é inegociável — vale mesmo para o
        // salto forçado e para análise de outro centro de preço.
        $salto = max(
            $saltoForcado ?? (int) $tendencia['salto_dinamico'],
            (int) ceil($this->saltoMinimoPorSpread($precoAtual, $config) / 500) * 500
        );
        Log::info("BotExecutor: salto = {$salto} | ATR={$tendencia['atr']} BollW=" . round($tendencia['boll_width'], 4));
        $state->salto = $salto;

        // M9 — idade do par atual (base do cooldown da adaptação do grid).
        $state->par_criado_em = now();

        $precoCompra = max(1.0, $precoAtual - $salto);
        $precoVenda  = $precoAtual + $salto;

        $saldos = $this->binance->getSaldos();

        if (!isset($saldos['balances'])) {
            Log::warning("BotExecutor: falha ao buscar saldos em criarOrdensNovas.");
            return false;
        }

        $balances = collect($saldos['balances']);
        $saldoBRL = (float) ($balances->firstWhere('asset', 'BRL')['free'] ?? 0);
        $saldoBTC = (float) ($balances->firstWhere('asset', 'BTC')['free'] ?? 0);

        $direcao       = $state->direcao_atual;
        $contadorAtual = $direcao === 'up' ? $state->contador_subidas : $state->contador_quedas;
        $nivelMaximo   = (int) ($state->contador_anterior ?? 0);

        // All-in só dispara se, além da contagem longa, houver confirmação de
        // exaustão no RSI 4h. Sem isso, uma sequência de quedas numa tendência
        // estrutural de baixa (RSI 4h em ~48) faria o bot apostar 95% no fundo.
        $rsi4h = $tendencia['rsi_4h'];
        $allin = $contadorAtual >= $config->allin_threshold
            && ($direcao === 'down' ? $rsi4h <= 40 : $rsi4h >= 60);

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-ORDEM-INICIO', [
            'saldoBRL' => $saldoBRL,
            'saldoBTC' => $saldoBTC,
            'direcao' => $direcao,
            'contadorAtual' => $contadorAtual,
            'nivel1' => $config->nivel1,
            'nivel2' => $config->nivel2,
            'nivel3' => $config->nivel3,
            'nivel4' => $config->nivel4,
            'nivel5' => $config->nivel5,
            'nivel6' => $config->nivel6,
            'nivel7' => $config->nivel7,
            'allin' => $allin,
        ]);

        $fatorCompra = $tendencia['fator_compra'];
        $fatorVenda  = $tendencia['fator_venda'];

        // ── M1/M2/M3: controle patrimonial BTC/BRL ────────────────────
        // Fatores de alocação multiplicam os de tendência; bloqueios normais
        // seguram ordens comuns (all-in de exaustão é a excepcional e passa),
        // bloqueios TOTAIS (pisos de tendência forte) seguram tudo.
        // Modo subida MANUAL (soCompra) é decisão explícita do admin — fica
        // fora do controle de alocação.
        $alocacao = $soCompra
            ? null
            : $this->calcularAlocacao($saldoBRL, $saldoBTC, $precoAtual, $config, $tendencia);

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-ALOCACAO', [
            'pctBTC' => $alocacao['pct_btc'] ?? null,
            'pctBRL' => $alocacao['pct_brl'] ?? null,
            'fatorCompraAlocacao' => $alocacao['fator_compra'] ?? null,
            'fatorVendaAlocacao' => $alocacao['fator_venda'] ?? null,
            'zona' => $alocacao['zona'] ?? null,
            'forte' => $alocacao['forte'] ?? null,
            'motivos' => $alocacao['motivos'] ?? null,
        ]);

        if ($alocacao !== null) {
            $fatorCompra *= $alocacao['fator_compra'];
            $fatorVenda  *= $alocacao['fator_venda'];

            // ── M4: modo subida AUTOMÁTICO ativo (manual tem fluxo próprio) ──
            if ($state->modo_subida_auto) {
                $fatorVenda  = min($fatorVenda, 0.30);          // reduzir vendas com força
                $fatorCompra = min(1.50, $fatorCompra * 1.30);  // priorizar recompra/acumular
            }

            // ── M7: extremos — RSI 1h bloqueia o lado; Bollinger colado ──
            // reduz; F&G extremo favorece realização/acumulação.
            $bloquearCompraExtremo = $tendencia['rsi'] >= (float) $config->rsi_maximo_compra;
            $bloquearVendaExtrema  = $tendencia['rsi'] <= (float) $config->rsi_minimo_venda;
            if ($tendencia['boll_pct_b'] >= self::BOLL_PCT_B_MAX_COMPRA) $fatorCompra *= 0.5;
            if ($tendencia['boll_pct_b'] <= self::BOLL_PCT_B_MIN_VENDA)  $fatorVenda  *= 0.5;
            if ($tendencia['fear_greed'] >= self::FNG_EUFORIA) $fatorVenda  = min(1.5, $fatorVenda * 1.2);
            if ($tendencia['fear_greed'] <= self::FNG_PANICO)  $fatorCompra = min(1.5, $fatorCompra * 1.2);

            // Bloqueios compostos: total (piso) > normal+extremo (all-in passa).
            $compraBloqueada = $alocacao['bloquear_compra_total']
                || (($alocacao['bloquear_compra_normal'] || $bloquearCompraExtremo)
                    && !($allin && $direcao === 'down'));
            $vendaBloqueada = $alocacao['bloquear_venda_total']
                || (($alocacao['bloquear_venda_normal'] || $bloquearVendaExtrema)
                    && !($allin && $direcao === 'up'));

            if ($compraBloqueada || $vendaBloqueada) {
                Log::info('BotExecutor: bloqueio de ordem — ' . implode(' · ', array_merge(
                    $alocacao['motivos'],
                    array_filter([
                        $bloquearCompraExtremo && !($allin && $direcao === 'down') ? sprintf('RSI %.1f ≥ %.0f: compra bloqueada', $tendencia['rsi'], (float) $config->rsi_maximo_compra) : null,
                        $bloquearVendaExtrema && !($allin && $direcao === 'up') ? sprintf('RSI %.1f ≤ %.0f: venda bloqueada', $tendencia['rsi'], (float) $config->rsi_minimo_venda) : null,
                    ])
                )) . ($state->modo_subida_auto ? ' · (subida auto ativa)' : ''));
            }

            // Com fatores podendo somar >1 (rebalanceio/subida auto), trava o
            // tamanho no saldo disponível — nunca tenta comprar/vender além.
            $fatorCompra = max(0.0, $fatorCompra);
            $fatorVenda  = max(0.0, $fatorVenda);
        } else {
            $compraBloqueada = false;
            $vendaBloqueada  = false;
        }

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-FATORES-FINAIS', [
            'modoSubidaAuto' => $state->modo_subida_auto,
            'fatorCompraFinal' => $fatorCompra,
            'fatorVendaFinal' => $fatorVenda,
            'compraBloqueada' => $compraBloqueada ?? false,
            'vendaBloqueada' => $vendaBloqueada ?? false,
        ]);

        // Zera os ids antes de recriar: um id velho não pode fingir que a perna
        // ainda existe (o guard de "par incompleto" depende disso).
        $state->order_id_compra = null;
        $state->order_id_venda  = null;

        // ── COMPRA ───────────────────────────────────────────────────
        $minN        = $this->minNotionalEfetivo($config);
        $valorCompra = 0.0;

        if ($soCompra) {
            // Modo "preparar subida": sempre compra nível1 (independente da direção)
            // para capturar pullbacks sem realizar lucro cedo.
            $valorCompra = $saldoBRL * $config->nivel1 * $fatorCompra;
        } elseif ($allin && $direcao === 'down' && !($alocacao['bloquear_compra_total'] ?? false)) {
            // Excepcional (exaustão de queda): passa bloqueio NORMAL — o piso
            // de baixa forte (bloqueio total) continua valendo nem pro all-in.
            $valorCompra = $saldoBRL * self::ALLIN_CAP;
        } elseif ($compraBloqueada) {
            $valorCompra = 0.0;
        } elseif ($direcao === 'down') {
            $valorCompra = $saldoBRL * $this->percentualPorSalto($contadorAtual, $config) * $fatorCompra;
        } elseif ($direcao === 'up' || $direcao === null) {
            $valorCompra = $saldoBRL * $config->nivel1 * $fatorCompra;
        }

        // M1/M4: fatores somados podem passar de 1 — trava no saldo livre
        // (margem de 0,5% contra arredondamento de qty da Binance).
        if ($valorCompra > $saldoBRL * 0.995) {
            $valorCompra = $saldoBRL * 0.995;
        }

        // Bump simétrico ao da SELL: se o tamanho parcial ficou abaixo do
        // min_notional mas o BRL total comporta, comprar o mínimo. Espelho do
        // bug do lado SELL — sem isso, BRL baixo geraria loop "par incompleto".
        if ($valorCompra > 0 && $valorCompra < $minN && $saldoBRL >= $minN) {
            $valorCompra = (float) $minN;
        }

        // Piso de ordem relevante: nível × fatores pode encolher a ordem a
        // dust mesmo acima do min_notional (R$50 num patrimônio de 13k não
        // move nada). Se a perna vai entrar, entra com tamanho que mova o
        // rebalanceamento — no mínimo piso_ordem_pct% do saldo livre.
        $pisoPct = (float) ($config->piso_ordem_pct ?? 0);
        if ($pisoPct > 0 && $valorCompra > 0
            && $valorCompra < $saldoBRL * ($pisoPct / 100.0)) {
            $valorCompra = $saldoBRL * (min($pisoPct, 99.5) / 100.0);
        }

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-COMPRA', [
            'saldoBRL' => $saldoBRL,
            'valorCompraFinal' => $valorCompra,
            'percentualRealCompra' => $saldoBRL > 0
                ? round(($valorCompra / $saldoBRL) * 100, 2)
                : 0,
        ]);

        $criouCompra = false;
        if ($valorCompra >= $minN) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
            $criouCompra            = $state->order_id_compra !== null;
            if (!$criouCompra) {
                Log::warning("BotExecutor: BUY rejeitada pela Binance — " . json_encode($orderCompra, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($valorCompra > 0) {
            // Diagnóstico de dust: se isto aparecer com cfg_min=0, o config estava
            // zerado em runtime (floor de R$50 evitou a ordem poeira).
            Log::info("BotExecutor: BUY abaixo do min_notional, não criada. valorCompra=R$" . number_format($valorCompra, 2)
                . " < R$" . number_format($minN, 2) . " · saldoBRL=R$" . number_format($saldoBRL, 2)
                . " · dir={$direcao} contador={$contadorAtual} allin=" . ($allin ? 1 : 0)
                . " cfg_min=" . var_export($config->min_notional, true));
        }

        // ── VENDA ────────────────────────────────────────────────────
        if ($soCompra) {
            // Modo "preparar subida": inibe as ordens de venda — só compra,
            // pra não realizar lucro cedo numa subida forte.
            $state->save();
            Log::info("BotExecutor: modo subida ativo — venda inibida. Compra criada=" . ($criouCompra ? 'sim' : 'nao') . ".");
            return $criouCompra;
        }

        $percentualVenda = 0.0;

        // All-in de venda só faz sentido no topo (longa sequência de subidas =
        // realizar lucro). O guard de 'up' é o espelho do guard de compra (down);
        // sem ele, uma sequência de 15+ quedas venderia 95% do BTC no fundo.
        if ($allin && $direcao === 'up' && !($alocacao['bloquear_venda_total'] ?? false)) {
            // Excepcional (realizar no topo): passa bloqueio NORMAL — o piso
            // de alta forte (bloqueio total) não deixa nem o all-in vender.
            $percentualVenda = self::ALLIN_CAP;
        } elseif ($vendaBloqueada) {
            $percentualVenda = 0.0;
        } elseif ($direcao === 'up') {
            $offset          = $nivelMaximo >= 3 ? 1 : 0;
            $percentualVenda = $this->percentualPorSalto($contadorAtual + $offset, $config) * $fatorVenda;
        } elseif ($direcao === 'down' || $direcao === null) {
            $percentualVenda = $this->percentualPorSalto(max(1, $contadorAtual), $config) * $fatorVenda;
        }

        // M1/M4: fatores somados podem passar de 1 — teto do all-in (95%).
        if ($percentualVenda > self::ALLIN_CAP) {
            $percentualVenda = self::ALLIN_CAP;
        }

        $criouVenda = false;
        $valorVenda = $saldoBTC * $percentualVenda * $precoVenda;
        $qtyVenda   = $saldoBTC * $percentualVenda;

        // Bump até o min_notional: se o tamanho parcial ficou abaixo do piso mas
        // o BTC total comporta, vender o mínimo em vez de bloquear. Sem isso, o
        // bot entrava em loop de "par incompleto" (recriava sem a perna SELL)
        // quando o BTC estava baixo — regressão observada em 2026-07-21.
        if ($valorVenda < $minN && $percentualVenda > 0 && $saldoBTC > 0
            && $saldoBTC * $precoVenda >= $minN) {
            $qtyVenda   = min($saldoBTC, $minN / $precoVenda);
            $valorVenda = $qtyVenda * $precoVenda;
        }

        // Piso de ordem relevante (espelho da compra): a perna SELL que vai
        // entrar vale no mínimo piso_ordem_pct% do BTC — evita vender 3% do
        // estoque (R$171 num valBTC de R$5.1k) por pura compressão de nível.
        $pisoPct = (float) ($config->piso_ordem_pct ?? 0);
        if ($pisoPct > 0 && $percentualVenda > 0 && $valorVenda > 0
            && $valorVenda < $saldoBTC * $precoVenda * ($pisoPct / 100.0)) {
            $percentualVenda = min($pisoPct, self::ALLIN_CAP * 100.0) / 100.0;
            $qtyVenda        = $saldoBTC * $percentualVenda;
            $valorVenda      = $qtyVenda * $precoVenda;
        }

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-VENDA', [
            'saldoBTC' => $saldoBTC,
            'precoVenda' => $precoVenda,
            'percentualVendaFinal' => $percentualVenda,
            'qtyVenda' => $qtyVenda,
            'valorVendaFinal' => $valorVenda,
            'percentualRealBTC' => $saldoBTC > 0
                ? round(($qtyVenda / $saldoBTC) * 100, 2)
                : 0,
        ]);

        if ($percentualVenda > 0 && $saldoBTC > 0 && $valorVenda >= $minN) {
            $orderVenda            = $this->binance->sellLimit($precoVenda, $qtyVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
            $criouVenda            = $state->order_id_venda !== null;
            if (!$criouVenda) {
                Log::warning("BotExecutor: SELL rejeitada pela Binance — " . json_encode($orderVenda, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($percentualVenda > 0 && $saldoBTC > 0) {
            Log::info("BotExecutor: SELL abaixo do min_notional, não criada. valorVenda=R$" . number_format($valorVenda, 2)
                . " < R$" . number_format($minN, 2) . " · saldoBTC=" . number_format($saldoBTC, 8)
                . " · dir={$direcao} contador={$contadorAtual} cfg_min=" . var_export($config->min_notional, true));
        }

        // ── DEBUG TEMPORÁRIO (remover após diagnosticar tamanho de ordens) ──
        Log::info('DEBUG-RESUMO', [
            'COMPRA_BRL' => $valorCompra,
            'VENDA_BRL' => $valorVenda,
            'pctCompraDoSaldoBRL' => $saldoBRL > 0
                ? round(($valorCompra / $saldoBRL) * 100, 2)
                : 0,
            'pctVendaDoBTC' => $saldoBTC > 0
                ? round(($qtyVenda / $saldoBTC) * 100, 2)
                : 0,
        ]);

        $state->save();

        // Se ambas as pernas foram tentadas e nenhuma entrou, o par nasceu vazio.
        // Sinalizar falha evita o loop de "par incompleto" que recria e falha pra
        // sempre (ex: saldo abaixo do mínimo da Binance dos dois lados).
        $tentouAlguma = $valorCompra >= $this->minNotionalEfetivo($config)
            || ($percentualVenda > 0 && $saldoBTC > 0 && $valorVenda >= $this->minNotionalEfetivo($config));
        if ($tentouAlguma && !$criouCompra && !$criouVenda) {
            Log::error("BotExecutor: nenhuma ordem criada (compra nem venda). Par vazio — verifique saldo/mínimos da Binance.");
            return false;
        }

        return true;
    }
}
