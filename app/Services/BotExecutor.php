<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotConfig;
use App\Http\Controllers\BinanceController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Log;

class BotExecutor
{
    protected BinanceController $binance;

    private const SYMBOL  = 'BTCBRL';
    private const LIMITES = [1 => 0.85, 2 => 0.60, 3 => 0.30, 4 => 0.10];

    public function __construct(BinanceController $binance)
    {
        $this->binance = $binance;
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
        // PROTEÇÃO: cancelar ordens fora do preço atual com margem
        // ============================================================
        $margem       = $state->salto * 1.5;
        $cancelledAny = false;

        foreach ($open as $ordem) {
            $side  = $ordem['side'];
            $price = (float) $ordem['price'];

            if ($side === 'SELL' && ($precoAtual - $price) > $margem) {
                $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                $cancelledAny = true;
                Log::info("BotExecutor [{$userId}]: SELL cancelada por fora do range (ordem {$price}, atual {$precoAtual}).");
            }

            if ($side === 'BUY' && ($price - $precoAtual) > $margem) {
                $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
                $cancelledAny = true;
                Log::info("BotExecutor [{$userId}]: BUY cancelada por fora do range (ordem {$price}, atual {$precoAtual}).");
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

        // Registrar direção e persistir estado ANTES de operações que podem falhar
        $valorOrdem = (float) $ordem['price'] * (float) $ordem['origQty'];

        if ($side === 'SELL') {
            // BUY foi executada → BTC caiu
            $this->processarQueda($state, $precoAtual);
            $state->save();
            Log::info("BotExecutor [{$userId}]: QUEDA registrada. Contador quedas: {$state->contador_quedas}. Preço: {$precoAtual}.");
            WhatsappController::notificarOrdemConcluida('Compra', $valorOrdem);
        } else {
            // SELL foi executada → BTC subiu
            $this->processarSubida($state, $precoAtual);
            $state->save();
            Log::info("BotExecutor [{$userId}]: SUBIDA registrada. Contador subidas: {$state->contador_subidas}. Preço: {$precoAtual}.");
            WhatsappController::notificarOrdemConcluida('Venda', $valorOrdem);
        }

        // Cancelar ordem restante e criar novo par
        if (!$this->limparTodasOrdensEAguardar(self::SYMBOL)) {
            Log::warning("BotExecutor [{$userId}]: timeout ao cancelar ordem restante. Abortando criação de par.");
            return "Timeout ao cancelar ordem restante. Direção já registrada.";
        }

        if (!$this->criarOrdensNovas($state, $precoAtual)) {
            return "Direção registrada mas erro ao criar novo par. Verifique os logs.";
        }

        return "Uma ordem restante detectada. Direção registrada e novo par criado.";
    }

    // ============================================================
    // LIMPAR TODAS AS ORDENS E AGUARDAR
    // ============================================================

    private function limparTodasOrdensEAguardar(string $symbol): bool
    {
        $open = $this->binance->getOpenOrders($symbol);

        if (is_array($open)) {
            foreach ($open as $ordem) {
                $this->binance->cancelarOrdem($symbol, $ordem['orderId']);
            }
        }

        // Aguarda até a Binance confirmar remoção (até 2 segundos)
        for ($i = 0; $i < 20; $i++) {
            usleep(100000); // 100ms

            $restantes = $this->binance->getOpenOrders($symbol);
            if (is_array($restantes) && empty($restantes)) {
                return true;
            }
        }

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

        $config = BotConfig::atual();

        $state = new BotState();
        $state->id_user           = $userId;
        $state->preco_referencia  = $precoAtual;
        $state->salto             = $config->salto;
        $state->direcao_atual     = null;
        $state->contador_subidas  = 0;
        $state->contador_quedas   = 0;
        $state->contador_anterior = 0;
        $state->ativo             = 1;
        $state->save();

        $this->criarOrdensIniciaisSemDivisao($state, $precoAtual, $saldoBRL, $saldoBTC);

        Log::info("BotExecutor [{$userId}]: bot inicializado. Preço: {$precoAtual}, salto: {$config->salto}.");

        return "Bot inicializado para o usuário {$userId}";
    }

    private function criarOrdensIniciaisSemDivisao(BotState $state, float $precoAtual, float $saldoBRL, float $saldoBTC): void
    {
        $salto       = $state->salto;
        $precoCompra = max(1.0, $precoAtual - $salto);
        $precoVenda  = $precoAtual + $salto;

        $valorCompra = $saldoBRL * self::LIMITES[1];

        if ($valorCompra > 10) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        $quantidadeVenda = $saldoBTC * self::LIMITES[1];

        if ($quantidadeVenda > 0) {
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
    // PERCENTUAIS — limites máximos fixos por nível
    // ============================================================

    private function percentualPorSalto(int $contador): float
    {
        return self::LIMITES[$contador] ?? 0.01;
    }

    // ============================================================
    // ANÁLISE DE TENDÊNCIA — MA21, EMA9, distância do preço
    // Retorna fator_compra e fator_venda entre 0.15 e 1.0
    // ============================================================

    private function analisarTendencia(float $precoAtual): array
    {
        $fallback = ['fator_compra' => 0.5, 'fator_venda' => 0.5];

        $klines = $this->binance->getKlines(self::SYMBOL, '1h', 50);

        if (!is_array($klines) || count($klines) < 21) {
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

        // Distância percentual do preço atual em relação à MA21
        // Positivo = preço acima da MA (sobrecomprado)
        // Negativo = preço abaixo da MA (sobrevendido)
        $distancia = ($precoAtual - $ma21) / $ma21;

        // Fator linear centrado em 0.5 com amplitude ±15%
        $fatorCompra = max(0.15, min(1.0, 0.5 - ($distancia / 0.30)));
        $fatorVenda  = max(0.15, min(1.0, 0.5 + ($distancia / 0.30)));

        // Ajuste pelo cruzamento EMA9 × MA21 (±0.10)
        // EMA9 > MA21 = tendência de alta → vende mais, compra menos
        // EMA9 < MA21 = tendência de baixa → compra mais, vende menos
        $boost       = $ema9 > $ma21 ? 0.10 : -0.10;
        $fatorCompra = max(0.15, min(1.0, $fatorCompra - $boost));
        $fatorVenda  = max(0.15, min(1.0, $fatorVenda  + $boost));

        Log::info(sprintf(
            "BotExecutor Tendência: MA21=%.2f EMA9=%.2f dist=%.2f%% fC=%.2f fV=%.2f",
            $ma21, $ema9, $distancia * 100, $fatorCompra, $fatorVenda
        ));

        return ['fator_compra' => $fatorCompra, 'fator_venda' => $fatorVenda];
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

    // ============================================================
    // CRIAÇÃO DE NOVAS ORDENS
    // ============================================================

    private function criarOrdensNovas(BotState $state, float $precoAtual): bool
    {
        $config       = BotConfig::atual();
        $salto        = $config->salto;
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
        $allin         = $contadorAtual >= 8;

        $tendencia   = $this->analisarTendencia($precoAtual);
        $fatorCompra = $tendencia['fator_compra'];
        $fatorVenda  = $tendencia['fator_venda'];

        // ── COMPRA ───────────────────────────────────────────────────
        $valorCompra = 0.0;

        if ($allin && $direcao === 'down') {
            $valorCompra = $saldoBRL;
        } elseif ($direcao === 'down') {
            $valorCompra = $saldoBRL * $this->percentualPorSalto($contadorAtual) * $fatorCompra;
        } elseif ($direcao === 'up' || $direcao === null) {
            $valorCompra = $saldoBRL * self::LIMITES[1] * $fatorCompra;
        }

        if ($valorCompra > 10) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // ── VENDA ────────────────────────────────────────────────────
        $percentualVenda = 0.0;

        if ($allin) {
            $percentualVenda = 1.0;
        } elseif ($direcao === 'up') {
            $offset          = $nivelMaximo >= 3 ? 1 : 0;
            $percentualVenda = $this->percentualPorSalto($contadorAtual + $offset) * $fatorVenda;
        } elseif ($direcao === 'down' || $direcao === null) {
            $percentualVenda = $this->percentualPorSalto(max(1, $contadorAtual)) * $fatorVenda;
        }

        if ($percentualVenda > 0 && $saldoBTC > 0) {
            $orderVenda            = $this->binance->sellLimit($precoVenda, $saldoBTC * $percentualVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();

        return true;
    }
}
