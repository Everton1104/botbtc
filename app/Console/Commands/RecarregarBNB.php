<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BinanceController;
use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;

class RecarregarBNB extends Command
{
    protected $signature   = 'bots:recarregar-bnb';
    protected $description = 'Compra BNB automaticamente quando o saldo cai abaixo de R$ 100';

    // Valor em BRL que dispara a compra
    const LIMITE_BRL  = 100.0;

    // Quanto gastar na compra
    const COMPRA_BRL  = 500.0;

    // Cooldown em minutos para evitar compras repetidas
    const COOLDOWN_MIN = 60;

    public function handle(BinanceController $binance): int
    {
        // ── 1. Verificar cooldown (arquivo de lock simples) ──────────
        $lockFile = storage_path('app/bnb_recarregar.lock');
        if (file_exists($lockFile)) {
            $ultimaCompra = (int) file_get_contents($lockFile);
            if ((time() - $ultimaCompra) < self::COOLDOWN_MIN * 60) {
                return Command::SUCCESS; // ainda no cooldown, não faz nada
            }
        }

        // ── 2. Buscar saldo BNB e preço atual ────────────────────────
        $saldos   = $binance->getSaldos();
        $precos   = $binance->getPrecos();
        $precoBNB = (float) ($precos['BNBBRL'] ?? 0);

        if ($precoBNB <= 0) {
            $this->error('Não foi possível obter o preço do BNB.');
            return Command::FAILURE;
        }

        $bnbBal = collect($saldos['balances'] ?? [])->first(fn($b) => $b['asset'] === 'BNB');
        $bnbQty = (float) ($bnbBal['free'] ?? 0) + (float) ($bnbBal['locked'] ?? 0);
        $bnbBRL = $bnbQty * $precoBNB;

        $this->info("BNB atual: {$bnbQty} ≈ R$ " . number_format($bnbBRL, 2, ',', '.'));

        if ($bnbBRL > self::LIMITE_BRL) {
            return Command::SUCCESS; // saldo ok, nada a fazer
        }

        // ── 3. Debitar do investimento do admin (user_id = 1) ────────
        $saldosBinance = $binance->getSaldos();
        $btc           = collect($saldosBinance['balances'])->first(fn($b) => $b['asset'] === 'BTC');
        $brl           = collect($saldosBinance['balances'])->first(fn($b) => $b['asset'] === 'BRL');

        $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                         + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $binance->getPrecoBTC());

        $totalCotas   = (float) BotInvestment::sum('cotas');
        $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 1.0;

        $adminInvest = BotInvestment::where('user_id', 1)->first();

        if (!$adminInvest || $adminInvest->cotas <= 0) {
            $this->error('Admin não possui saldo de cotas para a compra de BNB.');
            return Command::FAILURE;
        }

        $valorCompra   = min(self::COMPRA_BRL, $adminInvest->cotas * $precoPorCota);
        $cotasAQueimar = $precoPorCota > 0 ? $valorCompra / $precoPorCota : 0;

        if ($cotasAQueimar <= 0) {
            $this->error('Cotas insuficientes do admin para recarregar BNB.');
            return Command::FAILURE;
        }

        // ── 4. Criar registro de saque (como saque normal confirmado) ──
        $saque = BotWithdrawalRequest::create([
            'user_id'        => 1,
            'valor_bruto'    => $valorCompra,
            'valor_liquido'  => $valorCompra, // sem taxa, é uso interno
            'cotas'          => $cotasAQueimar,
            'preco_por_cota' => $precoPorCota,
            'patrimonio_bot' => $patrimonioAtual,
            'status'         => 'confirmado',
            'confirmado_at'  => now(),
        ]);

        // Queimar as cotas do admin
        if ($cotasAQueimar >= $adminInvest->cotas) {
            $adminInvest->delete();
        } else {
            $adminInvest->cotas              -= $cotasAQueimar;
            $adminInvest->investimento_inicial = max(0, $adminInvest->investimento_inicial - $valorCompra);
            $adminInvest->save();
        }

        // ── 5. Executar compra a mercado ─────────────────────────────
        $resultado = $binance->comprarBNBMercado($valorCompra);

        if (!empty($resultado['code'])) {
            // Binance retornou erro — reverter tudo
            $saque->delete();

            $adminInvest = BotInvestment::where('user_id', 1)->first();
            if ($adminInvest) {
                $adminInvest->cotas              += $cotasAQueimar;
                $adminInvest->investimento_inicial += $valorCompra;
                $adminInvest->save();
            } else {
                BotInvestment::create([
                    'user_id'              => 1,
                    'investimento_inicial' => $valorCompra,
                    'cotas'                => $cotasAQueimar,
                ]);
            }

            $erro = $resultado['msg'] ?? 'Erro desconhecido';
            $this->error("Compra de BNB falhou: {$erro}");
            Log::error('RecarregarBNB: compra falhou', $resultado);
            return Command::FAILURE;
        }

        // ── 6. Registrar cooldown ────────────────────────────────────
        file_put_contents($lockFile, time());

        $msg = "BNB recarregado: R$ " . number_format($valorCompra, 2, ',', '.') . " gastos";
        $this->info($msg);
        Log::info("RecarregarBNB: {$msg}", [
            'preco_bnb'     => $precoBNB,
            'saldo_anterior'=> $bnbBRL,
            'cotas_queimadas' => $cotasAQueimar,
            'order'         => $resultado,
        ]);

        return Command::SUCCESS;
    }
}
