<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotConfig;
use App\Models\BotTrade;
use App\Http\Controllers\BinanceController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BotExecutor
{
    protected BinanceController $binance;

    private const SYMBOL  = 'BTCBRL';
    private const ALLIN_CAP = 0.95;

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
    private function executarModoSubida(BotState $state, array $open, float $precoAtual): string
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
            $ok = $this->criarOrdensNovas($state, $precoAtual, true);
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
        // MODO "PREPARAR SUBIDA" (gatilho manual do admin) — fluxo próprio:
        // cancela ordens de venda, mantém/cria só compra, sem tocar no state
        // machine (contadores congelam). Volta ao normal quando desligado.
        // ============================================================
        if ($state->modo_subida) {
            return $this->executarModoSubida($state, $open, $precoAtual);
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
            if (!$this->criarOrdensNovas($state, $precoAtual)) {
                return "Erro ao criar par (saldo ou API). Verifique os logs.";
            }
            Log::info("BotExecutor [{$userId}]: 0 ordens abertas. Par recriado em {$precoAtual}.");
            return "Nenhuma ordem aberta. Par recriado.";
        }

        // ============================================================
        // 2 OU MAIS ORDENS → nada a fazer
        // ============================================================
        if ($qtd >= 2) {
            return "Duas ou mais ordens ativas. Nada a fazer.";
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
            if (!$this->criarOrdensNovas($state, $precoAtual)) {
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
            if (!$this->criarOrdensNovas($state, $precoAtual)) {
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

        if (!$this->criarOrdensNovas($state, $precoExecucao)) {
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

        return $total;
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

            // insertOrIgnore respeita o unique (symbol, binance_trade_id) → dedup seguro.
            $total += BotTrade::insertOrIgnore($rows);

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
    // (sequência longa na mesma direção) o percentual cai para 1%:
    // tese de reversão graduada, apostar cada vez menos até o all-in.
    // ============================================================

    private function percentualPorSalto(int $contador, BotConfig $config): float
    {
        return $config->niveis()[max(1, $contador)] ?? 0.01;
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
        $saltoDin = $atrSalto > 0
            ? (int) round(max(self::SALTO_MIN, min(self::SALTO_MAX, $base * $widthMod)) / 500) * 500
            : 2500;

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
                "BotExecutor: MA21=%.0f EMA9=%.0f RSI=%.1f ATR4h=%.0f salto=%d ATRdL=%.0f ATRdC=%.0f wMod=%.2f dist=%.2f%% MACD=%.0f sig=%.0f Boll%%B=%.2f W=%.3f trend4h=%+d RSI4h=%.1f MA21_4h=%.0f fC=%.2f fV=%.2f F&G=%d",
                $ma21, $ema9, $rsi, $atr, $saltoDin, $atrLongaDia, $atrCurtaDia, $widthMod, $distancia * 100,
                $macdData['macd'], $macdData['signal'], $bollData['pct_b'], $bollData['width'],
                $trend4h, $rsi4h, $ma21_4h, $fatorCompra, $fatorVenda, $fngVal
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

    private function criarOrdensNovas(BotState $state, float $precoAtual, bool $soCompra = false): bool
    {
        $config    = BotConfig::atual();
        $tendencia = $this->analisarTendencia($precoAtual);

        // Salto sempre baseado nas métricas (ATR + Bollinger width). Não há mais salto fixo.
        $salto = $tendencia['salto_dinamico'];
        Log::info("BotExecutor: salto = {$salto} | ATR={$tendencia['atr']} BollW=" . round($tendencia['boll_width'], 4));
        $state->salto = $salto;

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

        $fatorCompra = $tendencia['fator_compra'];
        $fatorVenda  = $tendencia['fator_venda'];

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
        } elseif ($allin && $direcao === 'down') {
            $valorCompra = $saldoBRL * self::ALLIN_CAP;
        } elseif ($direcao === 'down') {
            $valorCompra = $saldoBRL * $this->percentualPorSalto($contadorAtual, $config) * $fatorCompra;
        } elseif ($direcao === 'up' || $direcao === null) {
            $valorCompra = $saldoBRL * $config->nivel1 * $fatorCompra;
        }

        // Bump simétrico ao da SELL: se o tamanho parcial ficou abaixo do
        // min_notional mas o BRL total comporta, comprar o mínimo. Espelho do
        // bug do lado SELL — sem isso, BRL baixo geraria loop "par incompleto".
        if ($valorCompra > 0 && $valorCompra < $minN && $saldoBRL >= $minN) {
            $valorCompra = (float) $minN;
        }

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
        if ($allin && $direcao === 'up') {
            $percentualVenda = self::ALLIN_CAP;
        } elseif ($direcao === 'up') {
            $offset          = $nivelMaximo >= 3 ? 1 : 0;
            $percentualVenda = $this->percentualPorSalto($contadorAtual + $offset, $config) * $fatorVenda;
        } elseif ($direcao === 'down' || $direcao === null) {
            $percentualVenda = $this->percentualPorSalto(max(1, $contadorAtual), $config) * $fatorVenda;
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
