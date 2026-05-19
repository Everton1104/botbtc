<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotConfig;
use App\Http\Controllers\BinanceController;
use Illuminate\Support\Facades\Log;

class BotExecutor
{
    protected BinanceController $binance;

    public function __construct(BinanceController $binance)
    {
        $this->binance = $binance;
    }

    public function executar(string $userId)
    {
        $state = BotState::where('id_user', $userId)->first();

        // Se o bot ainda não existe → inicializar sem dividir capital
        if (!$state) {
            return $this->inicializarBotSemDivisao($userId);
        }

        // Buscar ordens abertas
        $open = $this->binance->getOpenOrders("BTCBRL");

        if (!is_array($open)) {
            Log::warning("BotExecutor [{$userId}]: falha ao buscar ordens abertas.");
            return "Erro ao buscar ordens abertas.";
        }

        $precoAtual = $this->binance->getPrecoBTC();

        // ============================================================
        // PROTEÇÃO: cancelar ordens fora do preço atual com margem
        // ============================================================
        $margem = $state->salto * 1.5;

        $cancelledAny = false;

        foreach ($open as $ordem) {
            $side  = $ordem['side'];
            $price = (float) $ordem['price'];

            // VENDA: só cancela se o preço atual estiver MUITO acima da ordem
            if ($side === 'SELL' && ($precoAtual - $price) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
                $cancelledAny = true;
                Log::info("BotExecutor [{$userId}]: SELL cancelada por fora do range (ordem {$price}, atual {$precoAtual}).");
            }

            // COMPRA: só cancela se o preço atual estiver MUITO abaixo da ordem
            if ($side === 'BUY' && ($price - $precoAtual) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
                $cancelledAny = true;
                Log::info("BotExecutor [{$userId}]: BUY cancelada por fora do range (ordem {$price}, atual {$precoAtual}).");
            }
        }

        // Atualizar lista após cancelamentos
        $open = $this->binance->getOpenOrders("BTCBRL");
        $qtd  = count($open);

        // ============================================================
        // 0 ORDENS → recriar par
        // ============================================================
        if ($qtd === 0) {
            $this->criarOrdensNovas($state, $precoAtual);
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

        // Se cancelamos uma ordem por estar fora do range e sobrou 1,
        // a ordem cancelada NÃO foi executada — apenas saiu do range.
        // Não registrar direção: recriar o par no preço atual.
        if ($cancelledAny) {
            $this->limparTodasOrdensEAguardar("BTCBRL");
            $this->criarOrdensNovas($state, $precoAtual);
            Log::info("BotExecutor [{$userId}]: ordem fora do range removida. Par recriado em {$precoAtual}.");
            return "Ordem fora do range cancelada (não executada). Par recriado no preço atual.";
        }

        // Registrar direção ANTES de apagar tudo
        if ($side === 'SELL') {
            // BUY foi executada → BTC caiu
            $this->processarQueda($state, $precoAtual);
            Log::info("BotExecutor [{$userId}]: QUEDA registrada. Contador quedas: {$state->contador_quedas}. Preço: {$precoAtual}.");
        } else {
            // SELL foi executada → BTC subiu
            $this->processarSubida($state, $precoAtual);
            Log::info("BotExecutor [{$userId}]: SUBIDA registrada. Contador subidas: {$state->contador_subidas}. Preço: {$precoAtual}.");
        }

        // Cancelar a ordem restante e criar novo par
        $this->limparTodasOrdensEAguardar("BTCBRL");
        $this->criarOrdensNovas($state, $precoAtual);

        return "Uma ordem restante detectada. Direção registrada e novo par criado.";
    }

    // ============================================================
    // LIMPAR TODAS AS ORDENS E AGUARDAR
    // ============================================================

    private function limparTodasOrdensEAguardar(string $symbol)
    {
        $open = $this->binance->getOpenOrders($symbol);

        foreach ($open as $ordem) {
            $this->binance->cancelarOrdem($symbol, $ordem['orderId']);
        }

        // Aguarda até a Binance confirmar remoção (até 2 segundos)
        for ($i = 0; $i < 20; $i++) {
            usleep(100000); // 100ms

            if (empty($this->binance->getOpenOrders($symbol))) {
                return true;
            }
        }

        return false;
    }

    // ============================================================
    // INICIALIZAÇÃO SEM DIVISÃO DE CAPITAL
    // ============================================================

    private function inicializarBotSemDivisao(string $userId)
    {
        $saldos = $this->binance->getSaldos();

        $saldoBRL = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BRL')['free'] ?? 0);

        $saldoBTC = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BTC')['free'] ?? 0);

        if ($saldoBRL < 10 && $saldoBTC <= 0) {
            return "Saldo insuficiente para iniciar o bot.";
        }

        $precoAtual = $this->binance->getPrecoBTC();
        $config     = BotConfig::atual();

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

        $this->criarOrdensIniciaisSemDivisao($state, $config, $precoAtual, $saldoBRL, $saldoBTC);

        Log::info("BotExecutor [{$userId}]: bot inicializado. Preço: {$precoAtual}, salto: {$config->salto}.");

        return "Bot inicializado para o usuário {$userId}";
    }

    private function criarOrdensIniciaisSemDivisao(BotState $state, BotConfig $config, float $precoAtual, float $saldoBRL, float $saldoBTC)
    {
        $salto = $state->salto;

        $precoCompra = max(1.0, $precoAtual - $salto);
        $precoVenda  = $precoAtual + $salto;

        // Compra inicial (p1% do BRL, lido do config)
        $valorCompra = $saldoBRL * $config->p1;

        if ($valorCompra > 10) {
            $quantidadeBTC          = $valorCompra / $precoCompra;
            $orderCompra            = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // Venda inicial (p1% do BTC, lido do config)
        $quantidadeVenda = $saldoBTC * $config->p1;

        if ($quantidadeVenda > 0) {
            $orderVenda            = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();
    }

    // ============================================================
    // LÓGICA DE SUBIDA E QUEDA
    // ============================================================

    private function processarSubida(BotState $state, float $precoAtual)
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

    private function processarQueda(BotState $state, float $precoAtual)
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
    // PERCENTUAIS — recebe config já carregado, sem hit extra no DB
    // ============================================================

    private function percentualPorSalto(int $contador, BotConfig $config): float
    {
        return match (true) {
            $contador === 1 => $config->p1,
            $contador === 2 => $config->p2,
            $contador === 3 => $config->p3,
            $contador === 4 => $config->p4,
            default         => 0.01,
        };
    }

    // ============================================================
    // CRIAÇÃO DE NOVAS ORDENS
    // ============================================================

    private function criarOrdensNovas(BotState $state, float $precoAtual)
    {
        $config = BotConfig::atual(); // único hit no DB por execução
        $salto  = $config->salto;
        $state->salto = $salto;

        $precoCompra = max(1.0, $precoAtual - $salto); // guard: nunca ≤ 0
        $precoVenda  = $precoAtual + $salto;

        $saldos   = $this->binance->getSaldos();
        $saldoBRL = (float) (collect($saldos['balances'])->firstWhere('asset', 'BRL')['free'] ?? 0);
        $saldoBTC = (float) (collect($saldos['balances'])->firstWhere('asset', 'BTC')['free'] ?? 0);

        $direcao       = $state->direcao_atual;
        $contadorAtual = $direcao === 'up' ? $state->contador_subidas : $state->contador_quedas;
        $nivelMaximo   = (int) ($state->contador_anterior ?? 0);
        $allin         = $contadorAtual >= 8;

        // ── COMPRA ───────────────────────────────────────────────────
        $valorCompra = 0.0;

        if ($allin && $direcao === 'down') {
            $valorCompra = $saldoBRL; // all-in caindo: compra tudo
        } elseif ($direcao === 'down') {
            $valorCompra = $saldoBRL * $this->percentualPorSalto($contadorAtual, $config);
        } elseif ($direcao === 'up' || $direcao === null) {
            // Subindo ou estado inicial: standby de compra em p1
            $valorCompra = $saldoBRL * $config->p1;
        }

        if ($valorCompra > 10) {
            $quantidadeBTC          = $valorCompra / $precoCompra;
            $orderCompra            = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // ── VENDA ────────────────────────────────────────────────────
        $percentualVenda = 0.0;

        if ($allin) {
            $percentualVenda = 1.0; // all-in: sai com tudo
        } elseif ($direcao === 'up') {
            // Reset com proteção p2 após queda funda (≥3 níveis)
            $offset          = $nivelMaximo >= 3 ? 1 : 0;
            $percentualVenda = $this->percentualPorSalto($contadorAtual + $offset, $config);
        } elseif ($direcao === 'down' || $direcao === null) {
            // Caindo ou estado inicial: standby de venda no nível atual
            $percentualVenda = $this->percentualPorSalto(max(1, $contadorAtual), $config);
        }

        if ($percentualVenda > 0 && $saldoBTC > 0) {
            $quantidadeVenda       = $saldoBTC * $percentualVenda;
            $orderVenda            = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();
    }
}
