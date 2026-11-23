<?php

namespace App\Services;

use App\Http\Controllers\BinanceController;
use App\Models\BotWithdrawalRequest;
use Illuminate\Support\Facades\Log;

/**
 * POOL DE BNB — o "BRL2" das cotas.
 *
 * As taxas da Binance (0,075% por perna) são pagas em BNB. O pool vivia
 * invisível à contabilidade e só diminui: quando zera, a taxa passa a 0,1%
 * cobrada na moeda do trade — mais cara e descontada DIRETO das cotas.
 *
 * Política (definida pelo admin em 23/11/2026):
 *   · o pool é PATRIMÔNIO das cotas (os cálculos de preço da cota somam
 *     BNB×preço — ver SaqueService / DepositoService / snapshot diário);
 *     a reposição BRL→BNB é troca de ativo DENTRO do fundo, neutra à cota;
 *   · manter o valor do pool entre R$ 150 e R$ 300: abaixo de 150 compra
 *     a mercado até ~300, usando BRL LIVRE da conta (nunca mexe no locked
 *     do grid) — a perna de compra do par nasce menor naquele ciclo, que
 *     é o custo aceitável da reposição;
 *   · nunca durante PIX de saque em andamento (o caixa é reservado).
 *
 * Reposição por transferência externa (de fora) também é válida: o pool é
 * patrimônio, entra igual — sem crédito de cotas.
 */
class BnbPoolService
{
    /** Abaixo disso compra. */
    private const POOL_MIN_BRL = 150.0;

    /** Alvo da reposição. */
    private const POOL_ALVO_BRL = 300.0;

    /** BRL livre que fica de fora da reposição — min_notional do grid. */
    private const FOLGA_CAIXA_BRL = 50.0;

    public function __construct(private BinanceController $binance)
    {
    }

    /**
     * Mantém o pool na faixa. Idempotente (na faixa = nada a fazer) — pode
     * rodar todo ciclo, transversal às estratégias. Retorna o status pra log.
     */
    public function manter(): string
    {
        // PIX de saque em andamento: o BRL livre é reservado ao saque.
        if (BotWithdrawalRequest::where('status', 'confirmado')->exists()) {
            return 'saque em andamento — pool intacto';
        }

        try {
            $saldos = $this->binance->getSaldos();
            if (!isset($saldos['balances'])) {
                return 'falha ao ler saldos';
            }

            $b = collect($saldos['balances']);
            $bnb = (float) ($b->firstWhere('asset', 'BNB')['free'] ?? 0)
                 + (float) ($b->firstWhere('asset', 'BNB')['locked'] ?? 0);
            $brlLivre = (float) ($b->firstWhere('asset', 'BRL')['free'] ?? 0);

            $precoBnb = $this->binance->getPrecoBNB();
            if ($precoBnb <= 0) {
                return 'preço BNBBRL indisponível';
            }

            $valorPool = $bnb * $precoBnb;
            if ($valorPool >= self::POOL_MIN_BRL) {
                return sprintf('pool ok (R$ %.2f)', $valorPool);
            }

            // Reposição: até o alvo, limitado ao caixa livre menos a folga
            // do grid. Dinheiro de cliente (depósito condicional) retido na
            // conta não é capital do fundo — mesma regra dos outros serviços.
            $brlUtil = $brlLivre - app(DepositoService::class)->retidosNaBinance() - self::FOLGA_CAIXA_BRL;
            $custo = min(self::POOL_ALVO_BRL - $valorPool, $brlUtil);

            if ($custo < 20.0) {
                Log::warning(sprintf(
                    'BNB pool: pool em R$ %.2f, mas caixa livre (R$ %.2f) não cobre a reposição agora — tenta no próximo ciclo.',
                    $valorPool, $brlLivre
                ));
                return 'sem caixa pra repor agora';
            }

            $resultado = $this->binance->buyMarketBNB($custo);
            if (!isset($resultado['orderId'])) {
                Log::warning('BNB pool: compra rejeitada pela Binance — ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
                return 'compra de BNB rejeitada';
            }

            Log::info(sprintf(
                'BNB pool: comprados R$ %.2f de BNB a mercado (pool %.8f BNB ≈ R$ %.2f -> +%.2f).',
                $custo, $bnb, $valorPool, self::POOL_ALVO_BRL - $valorPool
            ));

            return sprintf('pool reposto (+R$ %.2f de BNB)', $custo);
        } catch (\Throwable $e) {
            Log::warning('BNB pool: falha no ciclo — ' . $e->getMessage());
            return 'falha no ciclo do pool';
        }
    }
}
