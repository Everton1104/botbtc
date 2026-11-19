<?php

namespace App\Services;

use App\Http\Controllers\BinanceController;
use App\Models\BotConfig;
use App\Models\BotEstrategiaHistorico;
use App\Models\BotInvestment;
use App\Models\BotState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Controles do bot pelo admin — a regra que antes vivia inline nas rotas
 * do web.php, agora num serviço só que serve o SITE e o APP (AdminApiController).
 * Uma lógica, duas cascas HTTP.
 *
 * Convenção de retorno: ['ok' => bool, 'code' => int (HTTP em falha)] +
 * payload direto (mensagem / estado) — as cascas só escolhem o formato de saída.
 */
class BotAdminService
{
    // ── Configuração do bot ───────────────────────────────────────────────────

    /**
     * Aplica os campos da configuração com os MESMOS clamps do site (form
     * "Configuração do Bot"). Só toca nos campos presentes no $input — o
     * app manda o form inteiro, mas nada impedia um POST parcial antes.
     */
    public function salvarConfig(array $input): array
    {
        $cfg = BotConfig::atual();

        // Salto não é mais configurável — sempre dinâmico (ATR). Mantém forçado em 0.
        $cfg->salto = 0;

        foreach (['nivel1','nivel2','nivel3','nivel4','nivel5','nivel6','nivel7','nivel_final'] as $n) {
            if (array_key_exists($n, $input)) {
                $cfg->$n = max(0.01, min(1.0, (float) $input[$n]));
            }
        }

        if (array_key_exists('allin_threshold', $input)) {
            $cfg->allin_threshold = max(5, min(50, (int) $input['allin_threshold']));
        }

        if (array_key_exists('min_notional', $input)) {
            // Valor mínimo por ordem (BRL) — evita dust trades. Piso de 10 pra não
            // ferir o mínimo operacional da Binance; teto de 500 pra não travar o bot.
            $cfg->min_notional = max(10.0, min(500.0, (float) $input['min_notional']));
        }

        // ── Otimização M1-M9 — mesmos campos/defaults da migration, com clamps ──
        $floats = [
            // M1 — alocação: zonas/pisos RELATIVOS ao alvo (pts de distância).
            // target_btc_pct é tratado abaixo (BRL derivado, nunca divergem).
            'delta_alerta_pct'     => [10.0, 45.0],
            'delta_bloqueio_pct'   => [15.0, 50.0],
            // M2/M3 — pisos por tendência forte (pts abaixo do alvo)
            'piso_alta_delta_pct'  => [5.0, 30.0],
            'piso_baixa_delta_pct' => [5.0, 30.0],
            // Dimensionamento de ordens — piso por ordem (% do saldo) e guard de
            // meta do rebalanceamento (% do alvo; 0 desliga cada um)
            'piso_ordem_pct'            => [0.0, 50.0],
            'guard_meta_pct'            => [0.0, 100.0],
            // M5 — spread mínimo do grid (0,1% a 5%) e taxa total (0 a 2%)
            'spread_minimo_pct'         => [0.1, 5.0],
            'taxa_total_pct'            => [0.0, 2.0],
            // M7 — extremos de RSI 1h
            'rsi_maximo_compra'         => [50.0, 100.0],
            'rsi_minimo_venda'          => [0.0, 50.0],
            // M9 — histerese da adaptação do grid
            'adapt_histerese_pct'       => [5.0, 90.0],
        ];
        foreach ($floats as $campo => [$min, $max]) {
            if (array_key_exists($campo, $input)) {
                $cfg->$campo = max($min, min($max, (float) $input[$campo]));
            }
        }

        $ints = [
            'adapt_cooldown_min'     => [0, 1440],
            'adapt_persistencia_min' => [0, 720],
        ];
        foreach ($ints as $campo => [$min, $max]) {
            if (array_key_exists($campo, $input)) {
                $cfg->$campo = max($min, min($max, (int) $input[$campo]));
            }
        }

        // ── M1 — alvo de alocação: BTC é a única fonte (5–95; 0% de um lado mata
        // o grid — a perna nasce abaixo do min_notional e nunca é criada) e BRL é
        // sempre derivado (100−BTC), os dois lados nunca divergem no banco.
        if (array_key_exists('target_btc_pct', $input) || array_key_exists('target_brl_pct', $input)) {
            $btc = array_key_exists('target_btc_pct', $input)
                ? (float) $input['target_btc_pct']
                : 100.0 - (float) $input['target_brl_pct'];
            $btc = max(5.0, min(95.0, $btc));
            $cfg->target_btc_pct = $btc;
            $cfg->target_brl_pct = 100.0 - $btc;
        }

        // Bloqueio precisa ser mais largo que o alerta (senão a zona morta some).
        if ($cfg->delta_bloqueio_pct <= $cfg->delta_alerta_pct) {
            $cfg->delta_bloqueio_pct = min(50.0, $cfg->delta_alerta_pct + 5.0);
        }

        foreach (['modo_subida_auto_habilitado', 'camadas_atr_habilitado'] as $campo) {
            if (array_key_exists($campo, $input)) {
                $cfg->$campo = (bool) filter_var($input[$campo], FILTER_VALIDATE_BOOL);
            }
        }

        // ── Boost de ordens: multiplicador fixo no tamanho de TODAS as ordens
        // (0 = desligado). Trocou o valor → cancela as ordens abertas; o
        // próximo ciclo recria o par já no novo tamanho (mesmo ritual da
        // pausa manual, sem congelar nada).
        $boostAntigo = (float) ($cfg->boost_pct ?? 0);
        if (array_key_exists('boost_pct', $input)) {
            $cfg->boost_pct = max(0.0, min(100.0, (float) $input['boost_pct']));
        }
        $boostMudou = (float) $cfg->boost_pct !== $boostAntigo;

        // ── Estratégia do grid: 'atr' (dinâmico, padrão) × 'fixo' (spread
        // fixo — valorização no lugar do ATR). Os parâmetros do modo fixo
        // vêm clamped junto. Piso do gatilho = taxa TOTAL: um ciclo captura
        // o gatilho (SELL a C+g executa, recria centrado no fill, BUY a C
        // executa → ganho bruto = g), então gatilho abaixo da taxa total
        // executa no prejuízo por construção (0,15% era break-even exato).
        $estrategiaAntiga = (string) $cfg->estrategia_grid;
        if (array_key_exists('estrategia_grid', $input)) {
            $nova = strtolower(trim((string) $input['estrategia_grid']));
            $cfg->estrategia_grid = in_array($nova, ['atr', 'fixo'], true) ? $nova : 'atr';
        }
        $valorOrdemAntigo = (float) $cfg->fixo_valor_ordem;
        if (array_key_exists('fixo_valor_ordem', $input)) {
            $cfg->fixo_valor_ordem = max(50.0, min(50000.0, (float) $input['fixo_valor_ordem']));
        }
        $gatilhoAntigo = (float) $cfg->fixo_gatilho_pct;
        if (array_key_exists('fixo_gatilho_pct', $input)) {
            $taxa = (float) ($cfg->taxa_total_pct ?: 0.15);
            $cfg->fixo_gatilho_pct = max(round($taxa, 3), min(5.0, (float) $input['fixo_gatilho_pct']));
        }
        if (array_key_exists('fixo_espera_min', $input)) {
            $cfg->fixo_espera_min = max(1, min(1440, (int) $input['fixo_espera_min']));
        }
        $trocouEstrategia = (string) $cfg->estrategia_grid !== $estrategiaAntiga;
        // Mudança de gatilho/valor com o fixo ATIVO recria o par na hora
        // (ritual do boost): sem isso, o par aberto seguiria no gatilho
        // velho até o próximo fill.
        $mudouParametroFixo = !$trocouEstrategia
            && (string) $cfg->estrategia_grid === 'fixo'
            && (abs((float) $cfg->fixo_gatilho_pct - $gatilhoAntigo) >= 0.0005
                || abs((float) $cfg->fixo_valor_ordem - $valorOrdemAntigo) >= 0.01);

        $cfg->save();

        if ($trocouEstrategia) {
            $canceladas = $this->aplicarTrocaDeEstrategia((string) $cfg->estrategia_grid);

            $nome = $cfg->estrategia_grid === 'fixo' ? 'Spread fixo' : 'ATR dinâmico';
            return [
                'ok'       => true,
                'mensagem' => "Configuração salva. Estratégia trocada para {$nome} — {$canceladas} ordem(ns) cancelada(s); o próximo ciclo cria o par no formato novo.",
            ];
        }

        if ($mudouParametroFixo) {
            $canceladas = $this->cancelarOrdensAbertas();

            $g = number_format((float) $cfg->fixo_gatilho_pct, 3, ',', '.');
            return [
                'ok'       => true,
                'mensagem' => "Configuração salva. Spread fixo agora: gatilho ±{$g}%, ordens de R$ "
                    . number_format((float) $cfg->fixo_valor_ordem, 2, ',', '.')
                    . " — {$canceladas} ordem(ns) cancelada(s); o próximo ciclo (≤1 min) recria o par já no novo gatilho.",
            ];
        }

        if ($boostMudou) {
            $canceladas = $this->cancelarOrdensAbertas();

            $pct = number_format((float) $cfg->boost_pct, 1, ',', '.');
            return [
                'ok'     => true,
                'mensagem' => 'Configuração salva. Boost de ordens agora é '
                    . ($cfg->boost_pct > 0 ? "+{$pct}%" : 'desligado')
                    . " — {$canceladas} ordem(ns) cancelada(s); o próximo ciclo recria o par no novo tamanho.",
            ];
        }

        return ['ok' => true, 'mensagem' => 'Configuração salva com sucesso!'];
    }

    /**
     * Cancela as ordens abertas do BTCBRL (ritual da troca de boost/estrategia:
     * o próximo ciclo do bot recria tudo no formato novo). Falha aqui não
     * bloqueia nada — o ciclo recria de qualquer forma.
     */
    private function cancelarOrdensAbertas(): int
    {
        $canceladas = 0;
        try {
            $binance = app(BinanceController::class);
            $open    = $binance->getOpenOrders('BTCBRL');
            if (is_array($open)) {
                foreach ($open as $ordem) {
                    $binance->cancelarOrdem('BTCBRL', $ordem['orderId']);
                    $canceladas++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('BotAdminService: falha ao cancelar ordens abertas (o ciclo recria de qualquer forma): ' . $e->getMessage());
        }

        return $canceladas;
    }

    // ── Estratégia do grid: botão ATR ↔ Spread fixo ──────────────────────────

    /**
     * Alterna a estratégia pelo BOTÃO do painel (mesmo ritual do salvarConfig:
     * limpa flags da estratégia antiga e cancela as ordens abertas — o próximo
     * ciclo cria o par no formato novo, com os parâmetros já configurados).
     * Chamar já na estratégia pedida é no-op (não cancela nada).
     */
    public function alternarEstrategia(bool $fixo): array
    {
        $cfg  = BotConfig::atual();
        $nova = $fixo ? 'fixo' : 'atr';

        if ((string) $cfg->estrategia_grid === $nova) {
            return [
                'ok'              => true,
                'estrategia_grid' => $nova,
                'canceladas'      => 0,
                'mensagem'        => 'A estratégia já é ' . ($fixo ? 'spread fixo' : 'ATR dinâmico') . ' — nada a fazer.',
            ];
        }

        $cfg->estrategia_grid = $nova;
        $cfg->save();

        $canceladas = $this->aplicarTrocaDeEstrategia($nova);

        return [
            'ok'              => true,
            'estrategia_grid' => $nova,
            'canceladas'      => $canceladas,
            'mensagem'        => ($fixo
                ? 'Spread fixo ATIVADO (R$ ' . number_format((float) $cfg->fixo_valor_ordem, 2, ',', '.')
                    . ' ±' . number_format((float) $cfg->fixo_gatilho_pct, 3, ',', '.')
                    . '% por perna) — '
                : 'ATR dinâmico restaurado — ')
                . $canceladas . ' ordem(ns) cancelada(s); o próximo ciclo cria o par no formato novo.',
        ];
    }

    /**
     * Ritual da troca de estratégia (comum ao salvarConfig e ao botão do
     * painel): estado de uma estratégia não serve pra outra — o modo subida é
     * freio do grid ATR (não se aplica ao fixo) e a espera do fixo não
     * significa nada pro ATR. Cancela as ordens abertas (o próximo ciclo
     * recria no formato novo) e registra a era no histórico (base da
     * comparação de performance entre os modos). Retorna quantas cancelou.
     */
    private function aplicarTrocaDeEstrategia(string $nova): int
    {
        if ($nova === 'fixo') {
            Log::info('BotAdminService: estratégia trocada ATR → SPREAD FIXO (valor R$ '
                . number_format((float) BotConfig::atual()->fixo_valor_ordem, 2, ',', '.')
                . ', gatilho ±' . number_format((float) BotConfig::atual()->fixo_gatilho_pct, 3, ',', '.')
                . '%, espera ' . BotConfig::atual()->fixo_espera_min . 'min).');
        } else {
            BotState::whereNotNull('fixo_espera_desde')->update(['fixo_espera_desde' => null]);
            Log::info('BotAdminService: estratégia trocada SPREAD FIXO → ATR (grid dinâmico restaurado).');
        }

        $this->registrarEra($nova);

        return $this->cancelarOrdensAbertas();
    }

    /**
     * Linha do tempo das estratégias: encerra a era vigente e abre a da nova
     * estratégia, fotografando preço BTC e patrimônio na borda (mesmo critério
     * do snapshot diário do ExecutarBots: BRL livre+bloqueado + BTC×preço).
     * Falha na Binance NUNCA bloqueia a troca — a era abre sem a foto.
     */
    private function registrarEra(string $nova): void
    {
        try {
            $binance = app(BinanceController::class);
            $preco   = (float) $binance->getPrecos()['BTCBRL'];
            $saldos  = $binance->getSaldos();

            $foto = null;
            if ($preco > 0 && !empty($saldos['balances'])) {
                $b   = collect($saldos['balances']);
                $brl = (float) ($b->firstWhere('asset', 'BRL')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BRL')['locked'] ?? 0);
                $btc = (float) ($b->firstWhere('asset', 'BTC')['free'] ?? 0) + (float) ($b->firstWhere('asset', 'BTC')['locked'] ?? 0);
                $foto = ['preco_btc' => $preco, 'patrimonio' => round($brl + $btc * $preco, 2)];
            }

            $vigente = BotEstrategiaHistorico::vigente();
            if ($vigente) {
                $vigente->encerrado_em = now();
                $vigente->save();
            }

            BotEstrategiaHistorico::create(array_merge([
                'estrategia'  => $nova,
                // gatilho vigente na abertura da era (contexto da comparação
                // por gatilho; só existe na estratégia fixa)
                'gatilho_pct' => $nova === 'fixo' ? (float) BotConfig::atual()->fixo_gatilho_pct : null,
                'iniciado_em' => now(),
            ], $foto ?? []));
        } catch (\Throwable $e) {
            Log::warning('BotAdminService: falha ao registrar era no histórico (a troca segue): ' . $e->getMessage());
        }
    }

    // ── Pausa manual e modo "preparar subida" ─────────────────────────────────

    /**
     * Pausa manual do admin: cancela TODAS as ordens abertas na hora e congela
     * o bot — não recria o par até o admin despausar. Sem isso, cancelar ordens
     * na mão pra operar seria desfeito pelo próximo ciclo (que vê 0 ordens e
     * recria). O guard no BotExecutor::executar repete o cancelamento como
     * defesa (rate limit aqui não pode deixar ordem órfã rodando durante a pausa).
     */
    public function pausarManual(int $idUser, bool $pausando, BinanceController $binance): array
    {
        $state = BotState::where('id_user', $idUser)->first();
        if (!$state) {
            return ['ok' => false, 'code' => 404, 'erro' => 'Estado não encontrado'];
        }

        $state->pausado_manual = $pausando;
        $state->save();

        // Cancela na hora (feedback imediato pro painel); ao despausar NÃO cria
        // nada aqui — o próximo ciclo do bot recria o par no preço atual.
        $canceladas = 0;
        if ($pausando) {
            $open = $binance->getOpenOrders('BTCBRL');
            if (is_array($open)) {
                foreach ($open as $ordem) {
                    $binance->cancelarOrdem('BTCBRL', $ordem['orderId']);
                    $canceladas++;
                }
            }
        }

        Log::info('BotExecutor: pausa manual ' . ($pausando ? 'ATIVADA' : 'DESATIVADA') . " pelo admin. {$canceladas} ordem(ns) cancelada(s) na hora.");

        return ['ok' => true, 'pausado_manual' => $pausando, 'canceladas' => $canceladas];
    }

    /**
     * Estado operacional pro app: flags manuais do admin + pausa automática
     * dos saques (a mesma leitura do banner "Liberar bot" do site).
     */
    public function estado(int $idUser): array
    {
        $state = BotState::where('id_user', $idUser)->first();

        return [
            'pausado_manual'    => (bool) ($state?->pausado_manual ?? false),
            // Modo "preparar subida" removido (2026-11-19) — mantido sempre
            // false pra app antigo instalado continuar parseando o estado.
            'modo_subida'       => false,
            'modo_subida_auto'  => (bool) ($state?->modo_subida_auto ?? false),
            'bot_ativo'         => (bool) ($state?->ativo ?? false),
            'pausa_saques'      => app(SaqueService::class)->statusPausa(),
        ];
    }

    // ── Performance (M8) ──────────────────────────────────────────────────────

    /**
     * Zera a base de performance; o próximo ciclo do bot fotografia de novo
     * (útil após saque/depósito grande que distorce a variação desde a base).
     */
    public function resetarBasePerformance(): array
    {
        $cfg = BotConfig::atual();
        $cfg->patrimonio_inicial = null;
        $cfg->btc_inicial        = null;
        $cfg->brl_inicial        = null;
        $cfg->base_iniciada_em   = null;
        $cfg->save();

        Log::info('BotConfig: base de performance resetada pelo admin — será re-fotografada no próximo ciclo.');

        return ['ok' => true, 'mensagem' => 'Base resetada. O próximo ciclo do bot fotografia a nova base.'];
    }

    // ── Lixeira de investimentos ──────────────────────────────────────────────

    /**
     * Lixeira da tabela de investidores: TRANSFERE as cotas do usuário para a
     * conta do admin (1:1 — a fatia dele no bot passa a ser do admin) e remove
     * o registro. Sem cotas, é a correção de cadastro de sempre (só apaga).
     * O aporte HISTÓRICO do cliente não soma no admin: não é dinheiro que ele
     * pôs — o valor herdado aparece direto no lucro/participação dele.
     */
    public function esvaziarCotas(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'A lixeira transfere cotas para a conta do admin — não faz sentido na sua própria conta.'];
        }

        $invest = BotInvestment::where('user_id', $userId)->first();

        if (!$invest) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Nenhum investimento encontrado para este usuário.'];
        }

        $cotas = (float) $invest->cotas;

        DB::transaction(function () use ($invest, $cotas, $adminId) {
            if ($cotas > 0) {
                $admin = BotInvestment::firstOrCreate(['user_id' => $adminId]);
                $admin->cotas = (float) $admin->cotas + $cotas;
                $admin->save();
            }
            $invest->delete();
        });

        return [
            'ok'        => true,
            'mensagem'  => $cotas > 0
                ? 'Cotas transferidas para a sua conta (admin) e registro removido.'
                : 'Investimento removido com sucesso (não havia cotas).',
        ];
    }
}
