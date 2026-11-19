<?php

namespace App\Services;

use App\Http\Controllers\BinanceController;
use App\Models\BotConfig;
use App\Models\BotState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ESTRATÉGIA "SPREAD FIXO" — valorização no lugar do ATR.
 *
 * Grid de valor fixo: ordens sempre de R$ fixo_valor_ordem (default R$ 1.000),
 * compra a −fixo_gatilho_pct da base e venda a +fixo_gatilho_pct. MATEMÁTICA
 * DO CICLO: o ganho bruto de um vaivém é o GATILHO (SELL executa a C+g, par
 * recriado centrado no fill, BUY executa a C → ganho g; NÃO é 2g). Com o
 * gatilho em 0,30%: bruto 0,30% − taxa total 0,15% (0,075% × 2 pernas) =
 * líquido 0,15%, "lucro igual à taxa" — a tese original. Gatilho em 0,15%
 * é break-even exato (ciclo zero-sum) e é barrado no clamp do admin.
 *
 * Diferenças conscientes do grid ATR (é a tese da estratégia):
 *   · Sem state machine de direção, sem níveis progressivos, sem all-in —
 *     o valor da ordem é SEMPRE o mesmo, só cai quando o saldo não cobre.
 *   · Sem bloqueios de alocação/tendência, sem modo subida, sem adaptação
 *     de grid: o que decide é só a valorização desde a base.
 *   · Contadores/direção do bot_state ficam CONGELADOS — voltar ao grid ATR
 *     é trocar bot_config.estrategia_grid de volta, sem estado corrompido.
 *
 * Falta de saldo pra uma perna ("sobrou pouco BRL ou BTC"):
 *   · Segura o lado faltante por fixo_espera_min minutos (janela em
 *     bot_state.fixo_espera_desde), mantendo a perna viva intocada;
 *   · Estourou a janela sem o mercado estabilizar → REBASE: cancela o que
 *     houver e recria o par centrado no preço ATUAL com o saldo disponível
 *     (lado pobre entra com o que der, sempre ≥ min_notional). É a "nova
 *     base vendendo/comprando progressivamente até o valor estabilizar" —
 *     a cada janela a base persegue o preço; quando estabiliza, as duas
 *     pernas voltam a existir e o grid gira sozinho (cada venda financia
 *     a próxima compra e vice-versa).
 *
 * Transversais que seguem valendo: pausas (manual/saque) são checadas no
 * BotExecutor ANTES do dispatch; sincronização de trades/push FCM e snapshot
 * de patrimônio rodam no ExecutarBots fora da estratégia.
 */
class SpreadFixoService
{
    private const SYMBOL = 'BTCBRL';

    public function __construct(private BinanceController $binance)
    {
    }

    public function executar(string $userId): string
    {
        $config = BotConfig::atual();
        $state  = BotState::where('id_user', $userId)->first();

        $precoAtual = $this->binance->getPrecoBTC();
        if ($precoAtual <= 0) {
            Log::warning("SpreadFixo [{$userId}]: preço inválido ({$precoAtual}). Abortando.");
            return 'Preço inválido. Abortando execução.';
        }

        // M8 — base de performance do painel é transversal às estratégias.
        app(BotExecutor::class)->fotografarBasePerformance($config, $precoAtual);

        if (!$state) {
            $state = new BotState();
            $state->id_user          = $userId;
            $state->preco_referencia = $precoAtual;
            $state->salto            = 0;
            $state->direcao_atual    = null;
            $state->contador_subidas = 0;
            $state->contador_quedas  = 0;
            $state->contador_anterior = 0;
            $state->ativo            = 1;
            $state->save();
            Log::info("SpreadFixo [{$userId}]: state criado na ativação da estratégia fixa.");
        }

        $open = $this->binance->getOpenOrders(self::SYMBOL);
        if (!is_array($open)) {
            Log::warning("SpreadFixo [{$userId}]: falha ao buscar ordens abertas.");
            return 'Erro ao buscar ordens abertas.';
        }

        $gatilhoAbs = $precoAtual * ((float) $config->fixo_gatilho_pct / 100.0);
        $minN       = max((float) $config->min_notional, 50.0); // mesmo floor do grid ATR
        $qtd        = count($open);

        $this->logCiclo($userId, $state, $precoAtual, $config, $gatilhoAbs, $qtd);

        // ── 2+ ordens: grid completo (ou par ainda girando) — deixa o mercado
        // trabalhar. Defesa leve: par com espaçamento ~4× maior que o gatilho
        // é resíduo do grid ATR assumido vivo — troca pelo par fixo mantendo
        // o CENTRO (uma vez só; o par novo já nasce no espaçamento certo).
        if ($qtd >= 2) {
            if ($state->fixo_espera_desde !== null) {
                $state->fixo_espera_desde = null;
                $state->save();
            }

            $buy  = null;
            $sell = null;
            $extras = 0;
            foreach ($open as $o) {
                if (($o['side'] ?? '') === 'BUY')       $buy  = $o;
                elseif (($o['side'] ?? '') === 'SELL')  $sell = $o;
                else                                    $extras++;
            }

            if ($buy !== null && $sell !== null && $extras === 0) {
                $centroPar  = ((float) $buy['price'] + (float) $sell['price']) / 2.0;
                $distPernas = ((float) $sell['price'] - (float) $buy['price']) / 2.0;
                if ($distPernas > 3.0 * $gatilhoAbs) {
                    Log::info(sprintf(
                        'SpreadFixo [%s]: par com espaçamento %.0f >> gatilho %.0f (resíduo do grid ATR?) — recriando no gatilho fixo, centro %.0f mantido.',
                        $userId, $distPernas, $gatilhoAbs, $centroPar
                    ));
                    if ($this->cancelarTodas()) {
                        return $this->criarPar($state, $centroPar, $config, $gatilhoAbs, $minN);
                    }
                    return 'Par legado detectado, mas timeout ao cancelar. Mantido.';
                }
            }

            return 'Par completo. Nada a fazer.';
        }

        // ── Espera ativa (perna faltante por falta de saldo) ────────────────
        if ($state->fixo_espera_desde !== null) {
            $esperaMin = max(1, (int) $config->fixo_espera_min);
            // abs(): Carbon 3 devolve o diff COM SINAL (passado → negativo).
            $estourou  = abs(now()->diffInMinutes($state->fixo_espera_desde)) >= $esperaMin;

            if ($qtd === 1 && !$estourou) {
                // Dentro da janela: se o saldo do lado faltante voltou (fill
                // parcial, depósito), recompõe o par; senão segura de verdade.
                $recomposto = $this->recomporPernaFaltante($state, $open[0], $config, $gatilhoAbs, $minN);
                if ($recomposto !== null) {
                    return $recomposto;
                }
                $restam = $esperaMin - now()->diffInMinutes($state->fixo_espera_desde);
                return "Segurando perna faltante — rebase em {$restam} min se não estabilizar.";
            }

            if ($estourou) {
                // REBASE — o mercado não estabilizou: nova base no preço atual.
                if (!$this->cancelarTodas()) {
                    return 'Rebase: timeout ao cancelar ordens. Pernas mantidas (tenta de novo no próximo ciclo).';
                }
                $state->fixo_espera_desde = now(); // nova janela se o par ainda sair incompleto
                $state->save();
                $res = $this->criarPar($state, $precoAtual, $config, $gatilhoAbs, $minN);
                Log::info("SpreadFixo [{$userId}]: REBASE — nova base em {$precoAtual}.");
                return "Rebase: {$res}";
            }
        }

        // ── 0 ordens: par recém-executado dos dois lados (whipsaw), primeira
        // execução da estratégia ou fim de pausa — par novo no preço atual.
        if ($qtd === 0) {
            return 'Sem ordens abertas. ' . $this->criarPar($state, $precoAtual, $config, $gatilhoAbs, $minN);
        }

        // ── 1 ordem, sem espera: a perna OPOSTA executou desde o último ciclo.
        $lado         = $open[0]['side'] ?? '';
        $precoRestante = (float) $open[0]['price'];
        $fill         = $lado === 'SELL'
            ? max(1.0, $precoRestante - 2 * $gatilhoAbs) // BUY executou (baixou)
            : $precoRestante + 2 * $gatilhoAbs;           // SELL executou (subiu)

        // Perna órfã longe do mercado (gap entre ciclos ou resíduo do grid
        // ATR): o fill estimado não representa nada — nova base no preço atual.
        if (abs($precoAtual - $fill) > 2 * $gatilhoAbs) {
            Log::info(sprintf(
                'SpreadFixo [%s]: perna %s a %.0f está longe do mercado (%.0f) — rebase no preço atual em vez do fill estimado.',
                $userId, $lado, $precoRestante, $precoAtual
            ));
            $fill = $precoAtual;
        }

        if (!$this->cancelarTodas()) {
            return 'Timeout ao cancelar ordem restante. Próximo ciclo tenta de novo.';
        }

        $res = $this->criarPar($state, $fill, $config, $gatilhoAbs, $minN);
        Log::info(sprintf(
            'SpreadFixo [%s]: perna %s executou — par recriado centrado no fill %.2f.',
            $userId, $lado === 'SELL' ? 'BUY (queda)' : 'SELL (subida)', $fill
        ));
        return $res;
    }

    // ============================================================
    // CRIAÇÃO DO PAR (valor fixo nos dois lados)
    // ============================================================

    /**
     * Cria o par BUY/SELL centrado em $centro com o gatilho fixo. Cada perna
     * vale fixo_valor_ordem — ou o saldo disponível quando ele não cobre
     * (sempre ≥ min_notional; abaixo disso a perna fica de fora e a espera
     * é ligada). Retorna o resultado textual do ciclo.
     */
    private function criarPar(BotState $state, float $centro, BotConfig $config, float $gatilhoAbs, float $minN): string
    {
        $saldos = $this->binance->getSaldos();
        if (!isset($saldos['balances'])) {
            Log::warning('SpreadFixo: falha ao buscar saldos — par não criado.');
            return 'Falha ao buscar saldos; par não criado.';
        }

        $balances = collect($saldos['balances']);
        $brl = $this->brlDisponivel((float) ($balances->firstWhere('asset', 'BRL')['free'] ?? 0));
        $btc = (float) ($balances->firstWhere('asset', 'BTC')['free'] ?? 0);

        $valorPadrao = max($minN, (float) $config->fixo_valor_ordem);
        $precoCompra = $this->precoNoTick(max(1.0, $centro - $gatilhoAbs));
        $precoVenda  = $this->precoNoTick($centro + $gatilhoAbs);

        // Zera os ids antes de recriar (mesma higiene do grid ATR: id velho
        // não pode fingir que a perna existe).
        $state->order_id_compra = null;
        $state->order_id_venda  = null;
        $state->par_criado_em   = now();
        $state->salto           = (int) round($gatilhoAbs); // coerência do painel

        // ── COMPRA: valor fixo, limitado ao BRL livre (99,5% contra rounding) ──
        $valorCompra = min($valorPadrao, $brl * 0.995);
        $criouCompra = false;
        if ($valorCompra >= $minN) {
            $r = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            $state->order_id_compra = $r['orderId'] ?? null;
            $criouCompra = $state->order_id_compra !== null;
            if (!$criouCompra) {
                Log::warning('SpreadFixo: BUY rejeitada pela Binance — ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }

        // ── VENDA: valor fixo do estoque, limitado ao BTC livre (99,5%) ──
        $valorVenda = min($valorPadrao, $btc * $precoVenda * 0.995);
        $criouVenda = false;
        if ($valorVenda >= $minN) {
            $r = $this->binance->sellLimit($precoVenda, $valorVenda / $precoVenda);
            $state->order_id_venda = $r['orderId'] ?? null;
            $criouVenda = $state->order_id_venda !== null;
            if (!$criouVenda) {
                Log::warning('SpreadFixo: SELL rejeitada pela Binance — ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }

        $ladoFaltante = !$criouCompra && !$criouVenda
            ? 'compra e venda'
            : (!$criouCompra ? 'compra' : 'venda');

        if ($criouCompra && $criouVenda) {
            $state->fixo_espera_desde = null; // grid completo: espera desarma
            $state->save();
            Log::info(sprintf(
                'SpreadFixo: par criado — centro %.2f, pernas %.2f / %.2f (± %.2f), R$ %.2f cada.',
                $centro, $precoCompra, $precoVenda, $gatilhoAbs, $valorPadrao
            ));
            return sprintf('Par fixo criado (compra %.2f / venda %.2f).', $precoCompra, $precoVenda);
        }

        // Lado faltante: liga a espera (mantém a janela que já corria — só
        // marca AGORA quando não havia janela aberta).
        $state->fixo_espera_desde = $state->fixo_espera_desde ?? now();
        $state->save();
        Log::info(sprintf(
            'SpreadFixo: par INCOMPLETO (sem %s) — BRL=%.2f BTC=%.8f. Espera até rebase.',
            $ladoFaltante, $brl, $btc
        ));
        return "Par incompleto: sem saldo pra {$ladoFaltante}. Segurando (espera p/ rebase).";
    }

    /**
     * Dentro da janela de espera com UMA perna viva: se o saldo do lado
     * faltante voltou, cria a perna que falta ancorada na viva (centro =
     * perna ± gatilho) e desarma a espera. Retorna null quando segue
     * esperando (sem saldo ainda).
     */
    private function recomporPernaFaltante(BotState $state, array $ordem, BotConfig $config, float $gatilhoAbs, float $minN): ?string
    {
        $lado = $ordem['side'] ?? '';
        $precoViva = (float) $ordem['price'];

        $saldos = $this->binance->getSaldos();
        if (!isset($saldos['balances'])) {
            return 'Sem leitura de saldos; segurando perna faltante.'; // segue esperando
        }

        $balances = collect($saldos['balances']);
        $brl = $this->brlDisponivel((float) ($balances->firstWhere('asset', 'BRL')['free'] ?? 0));
        $btc = (float) ($balances->firstWhere('asset', 'BTC')['free'] ?? 0);
        $valorPadrao = max($minN, (float) $config->fixo_valor_ordem);

        if ($lado === 'BUY') {
            // Falta a SELL: precisa de estoque.
            $precoVenda = $this->precoNoTick($precoViva + 2 * $gatilhoAbs); // centro = viva + gatilho
            $valorVenda = min($valorPadrao, $btc * $precoVenda * 0.995);
            if ($valorVenda < $minN) {
                return null; // ainda sem estoque — segue esperando
            }
            $r = $this->binance->sellLimit($precoVenda, $valorVenda / $precoVenda);
            if (!isset($r['orderId'])) {
                Log::warning('SpreadFixo: SELL de recomposição rejeitada — ' . json_encode($r, JSON_UNESCAPED_UNICODE));
                return null;
            }
            $state->order_id_venda  = $r['orderId'];
            $state->fixo_espera_desde = null;
            $state->save();
            Log::info(sprintf('SpreadFixo: perna SELL recomposta em %.2f (saldo voltou).', $precoVenda));
            return sprintf('Par recomposto: SELL %.2f criada.', $precoVenda);
        }

        if ($lado === 'SELL') {
            // Falta a BUY: precisa de caixa.
            $precoCompra = $this->precoNoTick(max(1.0, $precoViva - 2 * $gatilhoAbs));
            $valorCompra = min($valorPadrao, $brl * 0.995);
            if ($valorCompra < $minN) {
                return null; // ainda sem caixa — segue esperando
            }
            $r = $this->binance->buyLimit($precoCompra, $valorCompra / $precoCompra);
            if (!isset($r['orderId'])) {
                Log::warning('SpreadFixo: BUY de recomposição rejeitada — ' . json_encode($r, JSON_UNESCAPED_UNICODE));
                return null;
            }
            $state->order_id_compra  = $r['orderId'];
            $state->fixo_espera_desde = null;
            $state->save();
            Log::info(sprintf('SpreadFixo: perna BUY recomposta em %.2f (saldo voltou).', $precoCompra));
            return sprintf('Par recomposto: BUY %.2f criada.', $precoCompra);
        }

        return null;
    }

    // ============================================================
    // LOG DO CICLO (uma linha por execução, como o CICLO do grid ATR)
    // ============================================================

    private function logCiclo(string $userId, BotState $state, float $preco, BotConfig $config, float $gatilhoAbs, int $qtd): void
    {
        $espera = $state->fixo_espera_desde
            ? sprintf(' espera=%dmin', (int) abs(now()->diffInMinutes($state->fixo_espera_desde)))
            : '';

        Log::info(sprintf(
            'CICLO-FIXO [%s]: preco=%.2f ordens=%d gatilho=%.3f%% (±%.2f) valor=R$%.2f%s',
            $userId, $preco, $qtd, (float) $config->fixo_gatilho_pct, $gatilhoAbs,
            (float) $config->fixo_valor_ordem, $espera
        ));
    }

    // ============================================================
    // AJUDANTES (mesmas regras de negócio do grid ATR)
    // ============================================================

    /**
     * Arredonda o preço pro tick da Binance. O gatilho percentual gera
     * preços com centavos (ex.: 371693.62) e o BTCBRL só aceita inteiro
     * (tickSize 1) — sem isso toda ordem morre com PRICE_FILTER. Filtros
     * do exchangeInfo com cache de 1h; sem resposta, fallback tick=1
     * (valor vigente do par).
     */
    private function precoNoTick(float $preco): float
    {
        $tick = (float) Cache::remember('bot.filtros.btcbrl.tick', now()->addHour(), function () {
            try {
                $f = $this->binance->getExchangeInfo(self::SYMBOL);
                if (is_array($f) && $f['tick'] > 0) {
                    return $f['tick'];
                }
            } catch (\Throwable $e) {
                Log::warning('SpreadFixo: falha ao buscar tick do símbolo (usando 1): ' . $e->getMessage());
            }
            return 1.0;
        });

        if ($tick <= 0) {
            $tick = 1.0;
        }

        return round($preco / $tick) * $tick;
    }

    /**
     * BRL disponível pro bot: saldo real menos depósitos condicionais
     * retidos na Binance (mesma conta do BotExecutor::brlDisponivel —
     * dinheiro de cliente sem cota não é capital do fundo).
     */
    private function brlDisponivel(float $brlReal): float
    {
        $retidos = app(\App\Services\DepositoService::class)->retidosNaBinance();
        return max(0.0, $brlReal - $retidos);
    }

    /** Cancela todas as ordens e espera a Binance confirmar (até 3s). */
    private function cancelarTodas(): bool
    {
        $open = $this->binance->getOpenOrders(self::SYMBOL);
        if (!is_array($open)) {
            Log::warning('SpreadFixo: falha ao listar ordens antes de cancelar.');
            return false;
        }

        foreach ($open as $ordem) {
            $this->binance->cancelarOrdem(self::SYMBOL, $ordem['orderId']);
        }

        for ($i = 0; $i < 30; $i++) {
            usleep(100000); // 100ms
            $restantes = $this->binance->getOpenOrders(self::SYMBOL);
            if (is_array($restantes) && empty($restantes)) {
                return true;
            }
        }

        Log::warning('SpreadFixo: timeout ao aguardar remoção de ordens.');
        return false;
    }
}
