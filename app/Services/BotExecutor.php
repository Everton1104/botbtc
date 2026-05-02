<?php

namespace App\Services;

use App\Models\BotState;
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

        foreach ($open as $ordem) {
            $side  = $ordem['side'];
            $price = (float)$ordem['price'];

            // VENDA: só cancela se o preço atual estiver MUITO acima da ordem
            if ($side === 'SELL' && ($precoAtual - $price) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
            }

            // COMPRA: só cancela se o preço atual estiver MUITO abaixo da ordem
            if ($side === 'BUY' && ($price - $precoAtual) > $margem) {
                $this->binance->cancelarOrdem("BTCBRL", $ordem['orderId']);
            }
        }

        // Atualizar lista após cancelamentos
        $open = $this->binance->getOpenOrders("BTCBRL");
        $qtd  = count($open);

        // ============================================================
        // 0 ORDENS → recriar par
        // ============================================================
        if ($qtd === 0) {

            // garantir que está realmente vazio
            $this->limparTodasOrdensEAguardar("BTCBRL");

            $this->criarOrdensNovas($state, $precoAtual);
            $state->save();

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

        $saldos = $this->binance->getSaldos();

        // Registrar direção ANTES de apagar tudo
        if ($side === 'SELL') {
            // BUY foi executada → BTC caiu
            $this->processarQueda($state, $precoAtual, $saldos);
        } else {
            // SELL foi executada → BTC subiu
            $this->processarSubida($state, $precoAtual, $saldos);
        }

        // Agora pode apagar tudo
        $this->limparTodasOrdensEAguardar("BTCBRL");

        // Criar novo par
        $this->criarOrdensNovas($state, $precoAtual);
        $state->save();

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
        $state->salto = 2000; // padrao inicial (os proximos seguem a tabela)
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
        $valorCompra = $saldoBRL * 0.50;

        if ($valorCompra > 10) {
            $quantidadeBTC = $valorCompra / $precoCompra;

            $orderCompra = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // Venda inicial (25% do BTC)
        $quantidadeVenda = $saldoBTC * 0.50;

        if ($quantidadeVenda > 0) {
            $orderVenda = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();
    }

    // ============================================================
    // LÓGICA DE SUBIDA E QUEDA
    // ============================================================

    private function processarSubida(BotState $state, float $precoAtual, array $saldos)
    {
        if ($state->direcao_atual !== 'up') {
            $state->contador_subidas = 0;
            $state->contador_quedas  = 0;
        }

        $state->direcao_atual = 'up';
        $state->contador_subidas++;

        $percentual = $this->percentualPorSalto($state->contador_subidas);

        $precoVenda = $precoAtual + $state->salto;

        $this->venderPercentualBTC($percentual, $saldos, $precoVenda);

        $state->preco_referencia = $precoAtual;
    }

    private function processarQueda(BotState $state, float $precoAtual, array $saldos)
    {
        if ($state->direcao_atual !== 'down') {
            $state->contador_subidas = 0;
            $state->contador_quedas  = 0;
        }

        $state->direcao_atual = 'down';
        $state->contador_quedas++;

        $percentual = $this->percentualPorSalto($state->contador_quedas);

        $precoCompra = $precoAtual - $state->salto;

        $this->comprarPercentualBRL($percentual, $saldos, $precoCompra);

        $state->preco_referencia = $precoAtual;
    }

    // ============================================================
    // PERCENTUAL POR SALTO
    // ============================================================

    private function percentualPorSalto(int $contador): float
    {
        return match (true) {
            $contador === 1 => 0.50,
            $contador === 2 => 0.25,
            $contador === 3 => 0.15,
            $contador === 4 => 0.10,
            default => 0.01,
        };
    }

    // ============================================================
    // COMPRA E VENDA
    // ============================================================

    private function venderPercentualBTC(float $percentual, array $saldos, float $precoVenda)
    {
        $saldoBTC = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BTC')['free'] ?? 0);

        $quantidade = $saldoBTC * $percentual;

        if ($quantidade > 0) {
            $this->binance->sellLimit($precoVenda, $quantidade);
        }
    }

    private function comprarPercentualBRL(float $percentual, array $saldos, float $precoCompra)
    {
        $saldoBRL = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BRL')['free'] ?? 0);

        $valor = $saldoBRL * $percentual;

        if ($valor <= 10) return;

        $quantidade = $valor / $precoCompra;

        if ($quantidade > 0) {
            $this->binance->buyLimit($precoCompra, $quantidade);
        }
    }

    // ============================================================
    // CRIAÇÃO DE NOVAS ORDENS
    // ============================================================

    private function criarOrdensNovas(BotState $state, float $precoAtual)
    {
        $salto = $state->salto;

        $precoCompra = $precoAtual - $salto;
        $precoVenda  = $precoAtual + $salto;

        $saldos = $this->binance->getSaldos();

        // Percentual reduzido baseado na tendência
        $percentualReducao = $this->percentualPorSalto(
            $state->direcao_atual === 'up'
                ? $state->contador_subidas
                : $state->contador_quedas
        );

        // Percentual fixo para o lado oposto
        $percentualFixo = 0.50;

        // ============================================================
        // COMPRA
        // ============================================================

        $saldoBRL = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BRL')['free'] ?? 0);

        // Se tendência é de alta → compra usa percentual fixo
        // Se tendência é de queda → compra usa percentual reduzido
        $percentualCompra = $state->direcao_atual === 'down'
            ? $percentualReducao
            : $percentualFixo;

        $valorCompra = $saldoBRL * $percentualCompra;

        if ($valorCompra > 10) {
            $quantidadeBTC = $valorCompra / $precoCompra;

            $orderCompra = $this->binance->buyLimit($precoCompra, $quantidadeBTC);
            $state->order_id_compra = $orderCompra['orderId'] ?? null;
        }

        // ============================================================
        // VENDA
        // ============================================================

        $saldoBTC = (float) (collect($saldos['balances'])
            ->firstWhere('asset', 'BTC')['free'] ?? 0);

        // Se tendência é de queda → venda usa percentual fixo
        // Se tendência é de alta → venda usa percentual reduzido
        $percentualVenda = $state->direcao_atual === 'up'
            ? $percentualReducao
            : $percentualFixo;

        $quantidadeVenda = $saldoBTC * $percentualVenda;

        if ($quantidadeVenda > 0) {
            $orderVenda = $this->binance->sellLimit($precoVenda, $quantidadeVenda);
            $state->order_id_venda = $orderVenda['orderId'] ?? null;
        }

        $state->save();
    }

}
