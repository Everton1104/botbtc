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
    private const ALLIN_CAP = 0.95;

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

        $config      = BotConfig::atual();
        $valorCompra = $saldoBRL * $config->nivel1;

        if ($valorCompra > 10) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        $quantidadeVenda = $saldoBTC * $config->nivel1;

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
    // PERCENTUAIS — lidos do banco, fallback 0.01 abaixo do nível 7
    // ============================================================

    private function percentualPorSalto(int $contador, BotConfig $config): float
    {
        return $config->niveis()[max(1, $contador)] ?? 0.01;
    }

    // ============================================================
    // ANÁLISE DE TENDÊNCIA — MA21, EMA9, RSI14, ATR14, MACD, Bollinger
    // ============================================================

    private function analisarTendencia(float $precoAtual): array
    {
        $fallback = ['fator_compra' => 0.5, 'fator_venda' => 0.5, 'rsi' => 50.0, 'atr' => 0.0, 'salto_dinamico' => 2500,
                     'macd' => 0.0, 'macd_signal' => 0.0, 'macd_hist' => 0.0,
                     'boll_upper' => 0.0, 'boll_lower' => 0.0, 'boll_pct_b' => 0.5, 'boll_width' => 0.0,
                     'trend_4h' => 0, 'ma21_4h' => 0.0, 'rsi_4h' => 50.0];

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

        // ATR calculado em candles diários — volatilidade na escala do grid
        $klinesD  = $this->binance->getKlines(self::SYMBOL, '1d', 30);
        $atr      = 0.0;
        if (is_array($klinesD) && count($klinesD) >= 15) {
            $highsD  = array_map(fn($k) => (float) $k[2], $klinesD);
            $lowsD   = array_map(fn($k) => (float) $k[3], $klinesD);
            $closesD = array_map(fn($k) => (float) $k[4], $klinesD);
            $atr     = $this->calcularATR($highsD, $lowsD, $closesD, 14);
        }
        $saltoDin = $atr > 0
            ? max(1500, min(15000, (int) (round($atr * 0.5 / 500) * 500)))
            : 2500;
        $macdData  = $this->calcularMACD($closes);
        $bollData  = $this->calcularBollinger($closes, 21);

        $distancia   = ($precoAtual - $ma21) / $ma21;
        $fatorCompra = max(0.45, min(1.0, 0.5 - ($distancia / 0.30)));
        $fatorVenda  = max(0.45, min(1.0, 0.5 + ($distancia / 0.30)));

        // EMA9 × MA21 boost ±0.10
        $boost       = $ema9 > $ma21 ? 0.10 : -0.10;
        $fatorCompra = max(0.45, min(1.0, $fatorCompra - $boost));
        $fatorVenda  = max(0.45, min(1.0, $fatorVenda  + $boost));

        // RSI boost ±0.20 nos extremos
        if ($rsi <= 30) {
            $fatorCompra = min(1.0,  $fatorCompra + 0.20);
            $fatorVenda  = max(0.45, $fatorVenda  - 0.10);
        } elseif ($rsi >= 70) {
            $fatorVenda  = min(1.0,  $fatorVenda  + 0.20);
            $fatorCompra = max(0.45, $fatorCompra - 0.10);
        }

        // MACD boost ±0.10 conforme cruzamento MACD × Signal
        if ($macdData['macd'] > $macdData['signal']) {
            $fatorVenda  = min(1.0,  $fatorVenda  + 0.10);
            $fatorCompra = max(0.45, $fatorCompra - 0.05);
        } else {
            $fatorCompra = min(1.0,  $fatorCompra + 0.10);
            $fatorVenda  = max(0.45, $fatorVenda  - 0.05);
        }

        // Bollinger: posição %B boost ±0.10 nos extremos
        if ($bollData['pct_b'] <= 0.20) {
            $fatorCompra = min(1.0,  $fatorCompra + 0.10);
        } elseif ($bollData['pct_b'] >= 0.80) {
            $fatorVenda  = min(1.0,  $fatorVenda  + 0.10);
        }

        // 4h multi-timeframe: confirmação de tendência de médio prazo
        $trend4h = 0;
        $ma21_4h = 0.0;
        $rsi4h   = 50.0;
        $klines4h = $this->binance->getKlines(self::SYMBOL, '4h', 30);
        if (is_array($klines4h) && count($klines4h) >= 22) {
            $closes4h = array_map(fn($k) => (float) $k[4], $klines4h);
            $ma21_4h  = array_sum(array_slice($closes4h, -21)) / 21;
            $ema9_4h  = $this->calcularEMA($closes4h, 9);
            $rsi4h    = $this->calcularRSI($closes4h, 14);

            if ($precoAtual > $ma21_4h && $ema9_4h > $ma21_4h)     $trend4h =  1;
            elseif ($precoAtual < $ma21_4h && $ema9_4h < $ma21_4h) $trend4h = -1;

            if ($trend4h === 1) {
                $fatorVenda  = min(1.0,  $fatorVenda  + 0.10);
                $fatorCompra = max(0.45, $fatorCompra - 0.05);
            } elseif ($trend4h === -1) {
                $fatorCompra = min(1.0,  $fatorCompra + 0.10);
                $fatorVenda  = max(0.45, $fatorVenda  - 0.05);
            }

            if ($rsi4h <= 35)     $fatorCompra = min(1.0,  $fatorCompra + 0.10);
            elseif ($rsi4h >= 65) $fatorVenda  = min(1.0,  $fatorVenda  + 0.10);
        }

        Log::info(sprintf(
            "BotExecutor: MA21=%.0f EMA9=%.0f RSI=%.1f ATR=%.0f salto=%d dist=%.2f%% MACD=%.0f sig=%.0f Boll%%B=%.2f W=%.3f trend4h=%+d RSI4h=%.1f MA21_4h=%.0f fC=%.2f fV=%.2f",
            $ma21, $ema9, $rsi, $atr, $saltoDin, $distancia * 100,
            $macdData['macd'], $macdData['signal'], $bollData['pct_b'], $bollData['width'],
            $trend4h, $rsi4h, $ma21_4h, $fatorCompra, $fatorVenda
        ));

        return [
            'fator_compra'   => $fatorCompra,
            'fator_venda'    => $fatorVenda,
            'rsi'            => $rsi,
            'atr'            => $atr,
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

    private function criarOrdensNovas(BotState $state, float $precoAtual): bool
    {
        $config    = BotConfig::atual();
        $tendencia = $this->analisarTendencia($precoAtual);

        $salto        = $tendencia['salto_dinamico'];
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
        $allin         = $contadorAtual >= $config->allin_threshold;

        $fatorCompra = $tendencia['fator_compra'];
        $fatorVenda  = $tendencia['fator_venda'];

        // ── COMPRA ───────────────────────────────────────────────────
        $valorCompra = 0.0;

        if ($allin && $direcao === 'down') {
            $valorCompra = $saldoBRL * self::ALLIN_CAP;
        } elseif ($direcao === 'down') {
            $valorCompra = $saldoBRL * $this->percentualPorSalto($contadorAtual, $config) * $fatorCompra;
        } elseif ($direcao === 'up' || $direcao === null) {
            $valorCompra = $saldoBRL * $config->nivel1 * $fatorCompra;
        }

        if ($valorCompra > 10) {
            $orderCompra            = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // ── VENDA ────────────────────────────────────────────────────
        $percentualVenda = 0.0;

        if ($allin) {
            $percentualVenda = self::ALLIN_CAP;
        } elseif ($direcao === 'up') {
            $offset          = $nivelMaximo >= 3 ? 1 : 0;
            $percentualVenda = $this->percentualPorSalto($contadorAtual + $offset, $config) * $fatorVenda;
        } elseif ($direcao === 'down' || $direcao === null) {
            $percentualVenda = $this->percentualPorSalto(max(1, $contadorAtual), $config) * $fatorVenda;
        }

        if ($percentualVenda > 0 && $saldoBTC > 0) {
            $orderVenda            = $this->binance->sellLimit($precoVenda, $saldoBTC * $percentualVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();

        return true;
    }
}
