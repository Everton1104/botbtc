<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\BinanceController;
use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;

class RecarregarBNB extends Command
{
    protected $signature   = 'bots:recarregar-bnb';
    protected $description = 'Compra BNB automaticamente quando o saldo cai abaixo de R$ 100 (custo rateado proporcionalmente entre todos os investidores)';

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
        // A Binance engasga de vez em quando (timeout/connect). Sem isso, o
        // comando morria em ConnectionException e o cron marcava falha a cada
        // blip. Retornar SUCCESS faz o próximo ciclo tentar de novo, sem ruído.
        try {
            $saldos = $binance->getSaldos();
            $precos = $binance->getPrecos();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('RecarregarBNB: Binance indisponível (timeout). Tentará no próximo ciclo — ' . $e->getMessage());
            return Command::SUCCESS;
        }

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

        // ── 3. Patrimônio (BRL + BTC) e preço por cota ───────────────
        // O BNB paga as taxas de trading do bot inteiro — benefício coletivo.
        // Portanto o custo da recarga é rateado PROPORCIONALMENTE entre todos
        // os investidores, conforme a participação de cada um nas cotas.
        // (Antes era debitado somente do admin — injusto.)
        $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');
        $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');

        // Reusa o preço já buscado em getPrecos() — uma chamada a menos à Binance
        // (e um ponto de falha a menos).
        $precoBTC = (float) ($precos['BTCBRL'] ?? 0);
        if ($precoBTC <= 0) {
            $this->error('Não foi possível obter o preço do BTC.');
            return Command::FAILURE;
        }

        $patrimonioAtual = ((float)($brl['free'] ?? 0) + (float)($brl['locked'] ?? 0))
                         + (((float)($btc['free'] ?? 0) + (float)($btc['locked'] ?? 0)) * $precoBTC);

        // Valor total a gastar na compra de BNB (limitado ao patrimônio real)
        $valorCompraTotal = min(self::COMPRA_BRL, $patrimonioAtual);

        if ($valorCompraTotal <= 0) {
            $this->error('Patrimônio insuficiente para recarregar BNB.');
            return Command::FAILURE;
        }

        // ── 4. Ratear o custo entre TODOS os investidores ────────────
        // Snapshot por investidor para reverter com exatidão se a Binance falhar.
        $alteracoes = [];

        try {
            DB::transaction(function () use ($patrimonioAtual, $valorCompraTotal, &$alteracoes) {

                $totalCotas   = (float) BotInvestment::lockForUpdate()->sum('cotas');
                $precoPorCota = $totalCotas > 0 ? $patrimonioAtual / $totalCotas : 0;

                if ($totalCotas <= 0 || $precoPorCota <= 0) {
                    throw new \Exception('Nenhum investidor com cotas para ratear o BNB.');
                }

                // Total de cotas que representam o valor da compra de BNB
                $totalCotasQueimar = $valorCompraTotal / $precoPorCota;

                $investidores = BotInvestment::lockForUpdate()->orderBy('user_id')->get();

                foreach ($investidores as $inv) {
                    $origCotas   = (float) $inv->cotas;
                    $origInicial = (float) $inv->investimento_inicial;

                    // Fração do custo proporcional à participação do investidor
                    $frac     = $totalCotas > 0 ? ($origCotas / $totalCotas) : 0;
                    $cotasInv = $totalCotasQueimar * $frac;
                    if ($cotasInv <= 0) {
                        continue;
                    }

                    $valorInv = $cotasInv * $precoPorCota;

                    // Registra o "saque" interno (uso com BNB) por investidor
                    $saque = BotWithdrawalRequest::create([
                        'user_id'        => $inv->user_id,
                        'valor_bruto'    => $valorInv,
                        'valor_liquido'  => $valorInv, // sem taxa, é uso interno
                        'cotas'          => $cotasInv,
                        'cotas_taxa'     => 0,
                        'preco_por_cota' => $precoPorCota,
                        'patrimonio_bot' => $patrimonioAtual,
                        'status'         => 'confirmado',
                        'confirmado_at'  => now(),
                    ]);

                    $cotasRestantes = $origCotas - $cotasInv;
                    $valorRestante  = $cotasRestantes * $precoPorCota;
                    $deletou        = false;

                    // Zera o registro se queimou tudo OU se o resíduo virou poeira (< R$ 1)
                    if ($cotasInv >= $origCotas || $valorRestante < 1) {
                        $inv->delete();
                        $deletou = true;
                    } else {
                        $inv->cotas               = $cotasRestantes;
                        $inv->investimento_inicial = max(0, $origInicial - $valorInv);
                        $inv->save();
                    }

                    $alteracoes[] = [
                        'user_id'         => $inv->user_id,
                        'saque_id'        => $saque->id,
                        'orig_cotas'      => $origCotas,
                        'orig_inicial'    => $origInicial,
                        'cotas_queimadas' => $cotasInv,
                        'valor'           => $valorInv,
                        'deletou'         => $deletou,
                    ];
                }
            });
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            Log::error('RecarregarBNB: falha ao ratear cotas — ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($alteracoes)) {
            $this->error('Nenhum investidor com cotas para ratear o BNB.');
            return Command::FAILURE;
        }

        // ── 5. Executar compra a mercado (fora da transaction — é externa) ──
        // ATENÇÃO: as cotas já foram rateadas/queimadas na transação acima.
        // Se a compra der timeout, a ordem PODE ter executado na Binance mesmo
        // sem resposta. Reverter cegamente seria errado (quebraria se fillou).
        // Logar CRITICAL pra verificação manual em vez de auto-reverter.
        try {
            $resultado = $binance->comprarBNBMercado($valorCompraTotal);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::critical('RecarregarBNB: TIMEOUT na compra de BNB APÓS rateio de cotas. Verificar se a ordem executou e o saldo BNB subiu. Cotas já rateadas — NÃO reverter cegamente.', [
                'valor_compra' => $valorCompraTotal,
                'erro'         => $e->getMessage(),
                'alteracoes'   => $alteracoes,
            ]);
            $this->error('Timeout na compra de BNB após rateio de cotas — verificação manual necessária (ver log CRITICAL).');
            return Command::FAILURE;
        }

        if (!empty($resultado['code'])) {
            // Binance retornou erro — reverter TUDO ao estado original
            DB::transaction(function () use ($alteracoes) {
                foreach ($alteracoes as $a) {
                    BotWithdrawalRequest::where('id', $a['saque_id'])->delete();

                    $inv = BotInvestment::where('user_id', $a['user_id'])->lockForUpdate()->first();
                    if ($inv) {
                        $inv->cotas               = $a['orig_cotas'];
                        $inv->investimento_inicial = $a['orig_inicial'];
                        $inv->save();
                    } else {
                        // Registro tinha sido deletado — recria com o snapshot original
                        BotInvestment::create([
                            'user_id'              => $a['user_id'],
                            'investimento_inicial' => $a['orig_inicial'],
                            'cotas'                => $a['orig_cotas'],
                        ]);
                    }
                }
            });

            $erro = $resultado['msg'] ?? 'Erro desconhecido';
            $this->error("Compra de BNB falhou: {$erro}");
            Log::error('RecarregarBNB: compra falhou', $resultado);
            return Command::FAILURE;
        }

        // ── 6. Registrar cooldown ────────────────────────────────────
        file_put_contents($lockFile, time());

        $msg = "BNB recarregado: R$ " . number_format($valorCompraTotal, 2, ',', '.')
             . " gastos (rateado entre " . count($alteracoes) . " investidor(es))";
        $this->info($msg);
        Log::info("RecarregarBNB: {$msg}", [
            'preco_bnb'             => $precoBNB,
            'saldo_anterior'        => $bnbBRL,
            'total_cotas_queimadas' => array_sum(array_column($alteracoes, 'cotas_queimadas')),
            'investidores'          => $alteracoes,
            'order'                 => $resultado,
        ]);

        return Command::SUCCESS;
    }
}
