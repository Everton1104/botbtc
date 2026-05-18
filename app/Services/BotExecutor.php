<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotConfig;
use App\Http\Controllers\BinanceController;

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
            return "Erro ao buscar ordens abertas.";
        }

        $precoAtual = $this->binance->getPrecoBTC();

        // ============================================================
        // PROTEÇÃO: cancelar ordens fora do preço atual com margem
        // ============================================================
        $margem = 1000; // margem de tolerância em BRL

        $cancelledAny = false;

        foreach ($open as $ordem) {
            $side  = $ordem['side'];
            $price = (float)$ordem['price'];

            // VENDA: só cancela se o preço atual estiver MUITO acima da ordem
            if ($side === 'SELL' && ($precoAtual - $price) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
                $cancelledAny = true;
            }

            // COMPRA: só cancela se o preço atual estiver MUITO abaixo da ordem
            if ($side === 'BUY' && ($price - $precoAtual) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
                $cancelledAny = true;
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
        // significa que a ordem cancelada NÃO foi executada — apenas saiu do range.
        // Não registrar direção: apenas recriar o par no preço atual.
        if ($cancelledAny) {
            $this->limparTodasOrdensEAguardar("BTCBRL");
            $this->criarOrdensNovas($state, $precoAtual);

            return "Ordem fora do range cancelada (não executada). Par recriado no preço atual.";
        }

        // Registrar direção ANTES de apagar tudo
        if ($side === 'SELL') {
            // BUY foi executada → BTC caiu
            $this->processarQueda($state, $precoAtual);
        } else {
            // SELL foi executada → BTC subiu
            $this->processarSubida($state, $precoAtual);
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
        // 1. Cancelar todas as ordens abertas
        $open = $this->binance->getOpenOrders($symbol);

        foreach ($open as $ordem) {
            $this->binance->cancelarOrdem($symbol, $ordem['orderId']);
        }

        // 2. Esperar até que a Binance realmente remova todas
        for ($i = 0; $i < 20; $i++) { // tenta por até 2 segundos
            usleep(100000); // 100ms

            $open = $this->binance->getOpenOrders($symbol);

            if (empty($open)) {
                return true; // tudo limpo
            }
        }

        return false; // ainda sobrou algo, mas seguimos mesmo assim
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

        $state = new BotState();
        $state->id_user = $userId;
        $state->preco_referencia = $precoAtual;
        $config = BotConfig::atual();
        $state->salto = $config->salto;
        $state->direcao_atual = null;
        $state->contador_subidas = 0;
        $state->contador_quedas = 0;
        $state->ativo = 1;
        $state->save();

        $this->criarOrdensIniciaisSemDivisao($state, $precoAtual, $saldoBRL, $saldoBTC);

        return "Bot inicializado (sem divisão de capital) para o usuário {$userId}";
    }

    private function criarOrdensIniciaisSemDivisao(BotState $state, float $precoAtual, float $saldoBRL, float $saldoBTC)
    {
        $salto = $state->salto;

        $precoCompra = $precoAtual - $salto;
        $precoVenda  = $precoAtual + $salto;

        // Compra inicial (25% do BRL)
        $valorCompra = $saldoBRL * 0.25;

        if ($valorCompra > 10) {
            $quantidadeBTC = $valorCompra / $precoCompra;

            $orderCompra = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // Venda inicial (25% do BTC)
        $quantidadeVenda = $saldoBTC * 0.25;

        if ($quantidadeVenda > 0) {
            $orderVenda = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
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
            // Guarda quantas quedas consecutivas vieram antes de inverter
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
            // Guarda quantas subidas consecutivas vieram antes de inverter
            $state->contador_anterior = $state->contador_subidas;
            $state->contador_subidas  = 0;
            $state->contador_quedas   = 0;
        }

        $state->direcao_atual   = 'down';
        $state->contador_quedas++;
        $state->preco_referencia = $precoAtual;
    }

    // ============================================================
    // PERCENTUAIS
    // ============================================================

    // Retorna p1, p2, p3, p4 conforme o contador; p5/p6/p7 = 1%
    private function percentualPorSalto(int $contador): float
    {
        $cfg = BotConfig::atual();

        return match (true) {
            $contador === 1 => $cfg->p1,
            $contador === 2 => $cfg->p2,
            $contador === 3 => $cfg->p3,
            $contador === 4 => $cfg->p4,
            default         => 0.01,   // saltos 5, 6, 7 = 1%
        };
    }

    // Espelho dinâmico: vende no nível (nivelMaximo - contadorSubidas + 1)
    // Exemplo: nivelMaximo=7, subida 1 → vende 1% (nível 7), subida 4 → vende p4 (5%), etc.
    private function percentualEspelhoSubida(int $contadorSubidas, int $nivelMaximo): float
    {
        $nivel = $nivelMaximo - $contadorSubidas + 1;
        if ($nivel < 1) return 0.0;
        return $this->percentualPorSalto($nivel);
    }

    // ============================================================
    // CRIAÇÃO DE NOVAS ORDENS
    // ============================================================

    private function criarOrdensNovas(BotState $state, float $precoAtual)
    {
        $config = BotConfig::atual();
        $salto  = $config->salto;
        $state->salto = $salto;

        $precoCompra = $precoAtual - $salto;
        $precoVenda  = $precoAtual + $salto;

        $saldos = $this->binance->getSaldos();

        $contadorAtual = $state->direcao_atual === 'up'
            ? $state->contador_subidas
            : $state->contador_quedas;

        // Profundidade da sequência anterior (usada como teto do espelho de volta)
        $nivelMaximo = (int) ($state->contador_anterior ?? 0);

        // All-in no 8º salto consecutivo na mesma direção.
        // Compra ou vende 100% do montante e posiciona ordem de saída 1 salto oposto.
        // A cada salto adicional a saída se atualiza acompanhando o preço.
        $allin = $contadorAtual >= 8;

        // ============================================================
        // COMPRA
        // ============================================================

        $saldoBRL = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BRL')['free'] ?? 0);

        if ($allin && $state->direcao_atual === 'down') {
            // All-in caindo: compra 100% do BRL disponível
            $valorCompra = $saldoBRL;
        } elseif ($state->direcao_atual === 'down') {
            // Saltos 1–7: percentual progressivo (p1→p2→p3→p4→1%→1%→1%)
            $percentualCompra = $this->percentualPorSalto($contadorAtual);
            $valorCompra      = $saldoBRL * $percentualCompra;
        } else {
            // Subindo: standby buy reservado para próxima queda (p1)
            $valorCompra = $saldoBRL * $config->p1;
        }

        if (!empty($valorCompra) && $valorCompra > 10) {
            $quantidadeBTC = $valorCompra / $precoCompra;
            $orderCompra   = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // ============================================================
        // VENDA
        // ============================================================

        $saldoBTC = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BTC')['free'] ?? 0);

        if ($allin) {
            // All-in (qualquer direção): 100% — saída ou entrada total
            $percentualVenda = 1.0;
        } elseif ($state->direcao_atual === 'up') {
            // Espelho dinâmico: vende no nível (nivelMaximo - passo + 1)
            // Ex: caiu 7 → subida 1 vende 1% (nível 7), subida 5 vende p3 (10%), etc.
            $percentualVenda = $nivelMaximo > 0
                ? $this->percentualEspelhoSubida($contadorAtual, $nivelMaximo)
                : $this->percentualPorSalto($contadorAtual);
        } else {
            // Caindo: standby sell espelha o nível atual da queda
            $percentualVenda = $this->percentualPorSalto($contadorAtual);
        }

        if ($percentualVenda > 0) {
            $quantidadeVenda = $saldoBTC * $percentualVenda;

            if ($quantidadeVenda > 0) {
                $orderVenda = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
                $state->order_id_venda = $orderVenda['orderId'] ?? null;
            }
        }

        $state->save();
    }

}
