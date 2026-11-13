<?php

namespace App\Services;

use App\Http\Controllers\BinanceController;
use App\Models\BotInvestment;
use App\Models\BotState;
use App\Models\PixPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Regra de negócio de DEPÓSITOS PIX — fonte única do site (web.php) e do
 * app (Api/DepositoApiController). A cobrança em si (criar/consultar) já
 * vivia no PixController + MercadoPagoService e é reutilizada por ambos
 * os lados; o que veio pra cá é a TELA do app, o crédito de cotas
 * (extraído da closure /bot/investir-manual) e as ações do admin.
 *
 * Crédito de cotas: o site fazia 2 chamadas (investir-manual + registrar);
 * o registrarDeposito funde as duas numa única transaction — sem a janela
 * em que o crédito era dado mas o PIX ficava sem a flag "registrado"
 * (um retry do admin duplicaria as cotas). O site preserva o fluxo
 * original por decisão; a matemática é a MESMA (creditarCotasNaTransacao).
 *
 * Convenção de retorno: igual ao SaqueService — array com 'ok' e
 * 'mensagem'; 'code' carrega o HTTP sugerido em falhas.
 */
class DepositoService
{
    public function __construct(
        private BinanceController $binance,
        private MercadoPagoService $mp,
    ) {}

    // ── Tela ─────────────────────────────────────────────────────────────

    /** Tudo que a tela de depósitos do app precisa (uma chamada só). */
    public function tela(int $userId): array
    {
        return [
            'pendente_atual' => $this->pendenteAtual($userId),
            'historico'      => $this->historico($userId),
            // Nome amigável do gateway ATUAL (env GATEWAY_PADRAO) — o app
            // explica ao cliente quem processa o pagamento; trocar de
            // operadora é só mudar o env, sem tocar no app.
            'gateway_nome'   => $this->nomeGateway(),
        ];
    }

    /** 'infinitepay' → 'InfinitePay', 'mercadopago' → 'Mercado Pago'. */
    public function nomeGateway(): string
    {
        return match (config('services.infinitepay.gateway_padrao', 'mercadopago')) {
            'infinitepay' => 'InfinitePay',
            'mercadopago' => 'Mercado Pago',
            default       => 'PIX',
        };
    }

    /**
     * Investidores para o seletor do depósito manual e a lista de contas
     * (só admin usa — o controller anexa à tela quando o usuário é o id 1).
     * 'cotas' diz se a conta pode ser removida (cotas zeradas = sem dinheiro).
     */
    public function investidores(): array
    {
        $cotas = BotInvestment::pluck('cotas', 'user_id');

        return User::orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn($u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'cotas' => (float) ($cotas[$u->id] ?? 0),
            ])
            ->all();
    }

    /**
     * Cobrança pendente ainda válida — o app retoma o QR ao reabrir a
     * tela. Sem isto, uma cobrança do MercadoPago (30 min de vida)
     * viraria órfã se o usuário minimizasse o app no meio do pagamento.
     */
    public function pendenteAtual(int $userId): ?array
    {
        $pix = PixPayment::where('user_id', $userId)
            ->where('status', 'pendente')
            ->where('expiracao', '>', now())
            ->orderByDesc('id')
            ->first();

        if (!$pix) {
            return null;
        }

        // InfinitePay reaproveita copia_e_cola como URL do link de pagamento
        $infinitepay = str_starts_with($pix->txid, 'ip_');

        return [
            'txid'         => $pix->txid,
            'valor'        => (float) $pix->valor,
            'qr_code'      => $infinitepay ? null : $pix->qr_code,
            'copia_e_cola' => $pix->copia_e_cola,
            'payment_url'  => $infinitepay ? $pix->copia_e_cola : null,
            'expiracao'    => $pix->expiracao?->toISOString(),
            'gateway'      => $infinitepay ? 'infinitepay' : 'mercadopago',
        ];
    }

    /** Depósitos pagos (e estornados) do investidor, mais recentes primeiro. */
    public function historico(int $userId): array
    {
        return PixPayment::where('user_id', $userId)
            ->whereIn('status', ['pago', 'estornado'])
            ->orderByDesc('pago_em')
            ->get()
            ->map(fn($p) => [
                'id'                 => $p->id,
                'valor'              => (float) $p->valor,
                'pago_em'            => $p->pago_em?->format('d/m/Y H:i') ?? '—',
                'btc_price'          => $p->btc_price ? (float) $p->btc_price : null,
                'status'             => $p->status,
                'registrado'         => (bool) $p->registrado,
                // Depósito condicional retido: sem cotas até o BTC cair
                // até o gatilho (o cliente vê o status no histórico).
                'aguardando_gatilho' => $p->isAguardandoGatilho(),
                'gatilho_preco'      => $p->gatilho_preco ? (float) $p->gatilho_preco : null,
            ])->all();
    }

    // ── Admin ────────────────────────────────────────────────────────────

    /** Depósitos confirmados/estornados de todos (painel do admin). */
    public function adminDepositos(): array
    {
        return PixPayment::whereIn('status', ['pago', 'estornado'])
            ->with('user:id,name,email')
            ->orderByDesc('pago_em')
            ->get()
            ->map(fn($p) => [
                'id'                 => $p->id,
                'txid'               => $p->txid,
                'user_id'            => $p->user_id,
                'user_name'          => $p->user?->name  ?? 'Desconhecido',
                'user_email'         => $p->user?->email ?? '—',
                'valor'              => (float) $p->valor,
                'liquido'            => $this->valorACreditar($p),
                'metodo'             => str_starts_with($p->txid, 'manual_')
                    ? 'Manual'
                    : ($p->capture_method === 'credit_card'
                        ? 'Cartão ' . (int) $p->installments . 'x'
                        : 'PIX'),
                'pago_em'            => $p->pago_em?->format('d/m/Y H:i'),
                'registrado'         => (bool) $p->registrado,
                'estornado'          => $p->status === 'estornado',
                'aguardando_gatilho' => $p->isAguardandoGatilho(),
                'gatilho_preco'      => $p->gatilho_preco ? (float) $p->gatilho_preco : null,
                'transferido'        => $p->transferido_em !== null,
            ])->all();
    }

    /**
     * REGISTRAR NO BOT — os 2 passos do site (investir-manual + marcar
     * registrado) numa única transaction. Crédito pelo valor LÍQUIDO.
     *
     * $gatilhoPreco (admin, opcional): em vez de creditar na hora, RETÉM o
     * depósito — vira cotas só quando o BTC CAIR até o preço-gatilho (o
     * ciclo confere a cada minuto). O dinheiro fica na custódia de sempre
     * (fora da Binance): nada muda no patrimônio nem no preço da cota
     * enquanto espera. Re-registrar sem gatilho converte na hora.
     */
    public function registrarDeposito(int $pixId, ?float $gatilhoPreco = null): array
    {
        $pix = PixPayment::where('id', $pixId)->where('status', 'pago')->first();

        if (!$pix) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Depósito não encontrado ou já processado.'];
        }
        if ($pix->registrado) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Depósito já registrado no bot.'];
        }
        if (!$pix->user_id) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Depósito sem investidor associado.'];
        }

        // ── Retenção por gatilho: marca e sai sem tocar em cotas ────────
        if ($gatilhoPreco !== null && $gatilhoPreco > 0) {
            try {
                $precoAtual = (float) $this->binance->getPrecoBTC();
            } catch (\Throwable) {
                return ['ok' => false, 'code' => 500, 'mensagem' => 'Erro ao ler o preço do BTC. Tente novamente.'];
            }
            if ($gatilhoPreco >= $precoAtual) {
                $fmt = number_format($gatilhoPreco, 2, ',', '.');
                return ['ok' => false, 'code' => 422, 'mensagem' =>
                    "BTC já está abaixo de R$ {$fmt} (agora R$ " . number_format($precoAtual, 2, ',', '.')
                    . ') — use um gatilho MENOR que o preço atual, ou registre sem gatilho.'];
            }

            $pix->update(['gatilho_preco' => $gatilhoPreco]);

            $fmt = number_format($gatilhoPreco, 2, ',', '.');
            return ['ok' => true, 'mensagem' =>
                'Depósito retido: vira cotas quando o BTC cair até R$ ' . $fmt
                . '. O bot pausa sozinho na conversão pra você transferir o valor pra Binance.'];
        }

        $valor = $this->valorACreditar($pix);

        // Patrimônio lido FORA da transaction (chamada externa — Binance)
        try {
            $patrimonio = $this->patrimonioAtual();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 500, 'mensagem' => 'Erro ao ler o patrimônio na Binance. Tente novamente.'];
        }

        // btc_price do pagamento, ou o atual se o webhook não conseguiu
        $btcPrice = $pix->btc_price;
        if (!$btcPrice) {
            try { $btcPrice = $this->binance->getPrecoBTC(); } catch (\Throwable) { $btcPrice = null; }
        }

        DB::transaction(function () use ($pix, $valor, $patrimonio, $btcPrice) {
            // Retido JÁ transferido: o valor está na Binance dentro dos
            // retidos — não subtrai de novo (o retidosNaBinance da base já
            // o exclui). Fluxo comum (dinheiro recém-chegado): subtrai.
            $jaNoPatrimonio = $pix->transferido_em === null;
            $this->creditarCotasNaTransacao($pix->user_id, $valor, $patrimonio, $jaNoPatrimonio);

            // gatilho_preco null: registro imediato cancela qualquer
            // retenção anterior (re-registrar um retido = antecipar).
            $updates = ['registrado' => true, 'gatilho_preco' => null];
            if ($btcPrice) {
                $updates['btc_price'] = $btcPrice;
            }
            $pix->update($updates);
        });

        // Cliente fica sabendo na hora que as cotas entraram ("liberei").
        \App\Services\FcmService::notificarAtivosLiberados($pix->user_id, $valor);

        return ['ok' => true, 'mensagem' => 'Depósito registrado no bot — cotas creditadas!'];
    }

    /**
     * DEPÓSITO MANUAL (admin) — aporte direto, sem gateway: nasce pago e
     * registrado, com as cotas creditadas na MESMA transaction. Cria o
     * registro em pix_payments (txid manual_*) para o depósito aparecer no
     * histórico do investidor e na lista de confirmados — rastreável como
     * qualquer outro. Não há taxa: valor integral vira cotas.
     *
     * Aqui o dinheiro AINDA NÃO está na Binance (o admin registra primeiro
     * e transfere depois — fluxo inverso do PIX), então o patrimônio NÃO
     * tem o valor subtraído no cálculo do preço da cota. O bot é PAUSADO
     * até o admin completar a transferência e liberar (banner no app).
     */
    public function depositoManual(int $userId, float $valor): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Investidor não encontrado.'];
        }
        if ($valor <= 0) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Valor inválido.'];
        }

        // Patrimônio e preço lidos FORA da transaction (chamadas externas)
        try {
            $patrimonio = $this->patrimonioAtual();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 500, 'mensagem' => 'Erro ao ler o patrimônio na Binance. Tente novamente.'];
        }
        try { $btcPrice = $this->binance->getPrecoBTC(); } catch (\Throwable) { $btcPrice = null; }

        DB::transaction(function () use ($userId, $valor, $patrimonio, $btcPrice) {
            PixPayment::create([
                'user_id'       => $userId,
                'txid'          => 'manual_' . uniqid(),
                'valor'         => $valor,
                'valor_liquido' => $valor,
                'descricao'     => 'Depósito manual (admin)',
                'status'        => 'pago',
                'registrado'    => true,
                'btc_price'     => $btcPrice,
            ]);

            $this->creditarCotasNaTransacao($userId, $valor, $patrimonio, valorJaNoPatrimonio: false);
        });

        // Cliente fica sabendo na hora que as cotas entraram ("liberei").
        \App\Services\FcmService::notificarAtivosLiberados($userId, $valor);

        // Bot parado enquanto o dinheiro não chega na Binance (o cálculo de
        // posição ficaria inconsistente com as cotas novas). Teto de 7 dias
        // como rede de segurança; o normal é o admin liberar em minutos.
        BotState::query()->update([
            'pausado_ate'  => now()->addDays(7),
            'pausa_motivo' => 'deposito',
        ]);

        return ['ok' => true, 'mensagem' => 'Depósito manual de R$ ' . number_format($valor, 2, ',', '.') . ' registrado para ' . $user->name . '. Bot pausado — libere após transferir o valor para a Binance.'];
    }

    /** Estorno via MercadoPago (igual à closure /admin/depositos-pix/{id}/estornar). */
    public function estornar(int $pixId): array
    {
        $pix = PixPayment::where('id', $pixId)->where('status', 'pago')->first();

        if (!$pix) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Depósito não encontrado.'];
        }
        if (str_starts_with($pix->txid, 'ip_')) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Estorno não suportado para pagamentos InfinitePay.'];
        }

        try {
            $this->mp->estornar($pix->txid);
            $pix->update(['status' => 'estornado']);

            return ['ok' => true, 'mensagem' => 'Estorno realizado. O cliente receberá o valor integral em até 5 dias úteis.'];
        } catch (\Exception $e) {
            Log::error('Estorno PIX falhou', ['id' => $pixId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 500, 'mensagem' => 'Erro ao processar estorno: ' . $e->getMessage()];
        }
    }

    // ── Depósito condicional (gatilho de preço) ─────────────────────────

    /**
     * TOTAL retido na Binance esperando gatilho (pago, sem cotas, com
     * gatilho e JÁ transferido): dinheiro de cliente que existe no saldo
     * real mas NÃO é capital do fundo. O preço da cota e o saldo operacional
     * do bot descontam este valor até a conversão — quem não é cotista ainda
     * não pode inflacionar/operar com o fundo.
     */
    public function retidosNaBinance(): float
    {
        return PixPayment::queryRetidosNaBinance()->get()
            ->sum(fn ($p) => $this->valorACreditar($p));
    }

    /**
     * Depósitos retidos cujo gatilho foi atingido — chamado pelo ciclo do
     * bot (ExecutarBots) quando o BTC está <= gatilho. Dois estados:
     *
     * • JÁ TRANSFERIDO pra Binance: converte NA HORA — credita cotas pelo
     *   preço da cota do momento (base = patrimônio do fundo, retidos
     *   excluídos), marca convertido e avisa os dois lados. SEM pausa: o
     *   dinheiro já está lá dentro, nada a fazer de madrugada.
     * • AINDA NÃO TRANSFERIDO: NÃO converte sozinho (o dinheiro não está
     *   na Binance — creditar cotas sem lastro e pausar às 3h da manhã é
     *   o que esta v2 evita). Manda UM push pro admin decidir: transferir
     *   e marcar "transferido" (o próximo ciclo converte), ou registrar
     *   sem gatilho (fluxo manual clássico com pausa).
     *
     * Um falhar não bloqueia os outros.
     */
    public function processarGatilhos(float $precoBtc): int
    {
        $retidos = PixPayment::where('status', 'pago')
            ->where('registrado', false)
            ->whereNull('convertido_em')
            ->whereNotNull('gatilho_preco')
            ->where('gatilho_preco', '>=', $precoBtc)
            ->with('user:id,name')
            ->get();

        foreach ($retidos as $pix) {
            try {
                $nome    = $pix->user?->name ?? 'investidor';
                $valor   = $this->valorACreditar($pix);
                $gatilho = (float) $pix->gatilho_preco;

                // ── Ainda fora da Binance: só avisa (uma vez) ────────────
                if ($pix->transferido_em === null) {
                    $jaAvisado = $pix->aviso_gatilho_em !== null
                        && $pix->aviso_gatilho_em->gt(now()->subDay());
                    if (!$jaAvisado) {
                        $pix->update(['aviso_gatilho_em' => now()]);
                        \App\Services\FcmService::notificarGatilhoAguardandoTransferencia($valor, $nome, $gatilho);
                        Log::info("Gatilho de depósito atingido (BTC R$ " . number_format($gatilho, 2, ',', '.') . ") mas #{$pix->id} fora da Binance — admin avisado.");
                    }
                    continue;
                }

                // ── Transferido: conversão automática, sem pausa ─────────
                // Patrimônio lido FORA da transaction (Binance) — por retido,
                // porque cada crédito muda o preço da cota do próximo.
                $patrimonio = $this->patrimonioAtual();

                DB::transaction(function () use ($pix, $valor, $patrimonio, $precoBtc) {
                    // Crédito ANTES de marcar convertido: o próprio valor
                    // continua no bucket dos retidos durante o cálculo da
                    // base (fluxo false = patrimônio do fundo puro).
                    $this->creditarCotasNaTransacao($pix->user_id, $valor, $patrimonio, valorJaNoPatrimonio: false);
                    $pix->update([
                        'registrado'    => true,
                        'convertido_em' => now(),
                        'btc_price'     => $precoBtc,
                    ]);
                });

                \App\Services\FcmService::notificarGatilhoConvertido($valor, $nome, $gatilho);
                \App\Services\FcmService::notificarAtivosLiberados($pix->user_id, $valor);

                Log::info("Gatilho de depósito atingido (BTC R$ " . number_format($gatilho, 2, ',', '.') . "): R$ {$valor} de {$nome} virou cotas (automático, valor já na Binance).");
            } catch (\Throwable $e) {
                Log::warning("DepositoService: falha ao converter depósito retido #{$pix->id} (tenta de novo no próximo ciclo): " . $e->getMessage());
            }
        }

        return $retidos->count();
    }

    /**
     * MARCAR TRANSFERIDO (admin): o valor do depósito retido já foi pra
     * Binance — a partir daqui o dinheiro é excluído do capital do fundo
     * (cota e bot) e o gatilho converte sozinho, sem pausa. Se o preço já
     * estiver no gatilho, o próximo ciclo (≤1 min) converte.
     */
    public function marcarTransferido(int $pixId): array
    {
        $pix = PixPayment::where('id', $pixId)->where('status', 'pago')->first();

        if (!$pix) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Depósito não encontrado ou já processado.'];
        }
        if ($pix->registrado) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Depósito já registrado no bot.'];
        }
        if ($pix->gatilho_preco === null) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Este depósito não está retido por gatilho.'];
        }
        if ($pix->transferido_em !== null) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Depósito já estava marcado como transferido.'];
        }

        $pix->update(['transferido_em' => now(), 'aviso_gatilho_em' => null]);

        $gatilho = number_format((float) $pix->gatilho_preco, 2, ',', '.');
        return ['ok' => true, 'mensagem' =>
            "Marcado como transferido — o valor agora espera o gatilho (BTC ≤ R$ {$gatilho}) já dentro da Binance, fora do capital do fundo. "
            . 'A conversão em cotas é automática quando o preço atingir, sem pausar o bot.'];
    }

    // ── Crédito de cotas (núcleo compartilhado) ──────────────────────────

    /**
     * Investimento manual sem gateway — extraído da closure
     * /bot/investir-manual (o site continua chamando por aqui).
     */
    public function investirManual(int $userId, float $valor): array
    {
        if ($valor <= 0) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Valor inválido'];
        }

        // Patrimônio lido ANTES da transaction (chamada externa — Binance)
        try {
            $patrimonio = $this->patrimonioAtual();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 500, 'mensagem' => 'Erro ao ler o patrimônio na Binance. Tente novamente.'];
        }

        DB::transaction(fn () => $this->creditarCotasNaTransacao($userId, $valor, $patrimonio));

        // Cliente fica sabendo na hora que as cotas entraram ("liberei") —
        // é aqui que o fluxo do SITE credita (investir-manual + flag depois).
        \App\Services\FcmService::notificarAtivosLiberados($userId, $valor);

        return ['ok' => true, 'mensagem' => 'Investimento realizado com sucesso!'];
    }

    /**
     * Valor LÍQUIDO a creditar — regra que vivia no JS da blade, agora no
     * servidor (fonte única): InfinitePay já computou a taxa no
     * valor_liquido; legado MercadoPago desconta 1% (admin isento).
     */
    public function valorACreditar(PixPayment $pix): float
    {
        $liquido = (float) ($pix->valor_liquido ?? 0);
        if ($liquido > 0) {
            return $liquido;
        }

        return $pix->user_id === 1 ? (float) $pix->valor : round((float) $pix->valor * 0.99, 2);
    }

    /**
     * Crédito de cotas — corpo exato da antiga closure (matemática idêntica).
     *
     * $valorJaNoPatrimonio distingue os fluxos:
     *  • PIX/registrar comum (true): o dinheiro JÁ caiu na Binance antes do
     *    registro — subtrai o valor do patrimônio pra não inflar o preço
     *    da cota (comportamento original);
     *  • manual (false): o admin registra ANTES de transferir — o
     *    patrimônio lido ainda não contém o aporte, então é usado puro.
     *    Foi o bug das cotas infladas do depósito de R$ 5.000 (subtraiu
     *    dinheiro que ainda não estava lá: preço/cota R$ 0,35 vs R$ 1,16
     *    real → 3,3× mais cotas que o certo).
     *  • conversão de retido transferido (false): o dinheiro ESTÁ na
     *    Binance, mas dentro dos retidos — descontado junto com os demais
     *    (a base é sempre o patrimônio do FUNDO, não o saldo bruto).
     *
     * SEMPRE desconta os retidos-na-Binance (depósitos condicionais
     * esperando gatilho): são dinheiro de cliente sem cota — não podem
     * inflacionar o preço que o novo depósito vai pagar. Chamar o crédito
     * ANTES de marcar convertido_em para o próprio retido continuar no
     * bucket durante o cálculo.
     */
    private function creditarCotasNaTransacao(
        int $userId,
        float $valor,
        float $patrimonioAtual,
        bool $valorJaNoPatrimonio = true,
    ): void {
        // Lock para evitar corrida com outros depósitos simultâneos
        $totalCotas = (float) BotInvestment::lockForUpdate()->sum('cotas');

        $base = max(0, $patrimonioAtual - $this->retidosNaBinance() - ($valorJaNoPatrimonio ? $valor : 0));
        $precoPorCota = $totalCotas > 0 ? $base / $totalCotas : 1.0;
        $novasCotas   = $valor / $precoPorCota;

        $invest = BotInvestment::where('user_id', $userId)->lockForUpdate()->first();

        if ($invest) {
            $invest->investimento_inicial += $valor;
            $invest->cotas                += $novasCotas;
            $invest->save();
        } else {
            BotInvestment::create([
                'user_id'              => $userId,
                'investimento_inicial' => $valor,
                'cotas'                => $novasCotas,
            ]);
        }
    }

    /** Patrimônio BRL + BTC×preço — mesma fórmula do SaqueService::solicitar. */
    private function patrimonioAtual(): float
    {
        $saldos = $this->binance->getSaldos();
        $preco  = $this->binance->getPrecoBTC();

        $brl = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BRL');
        $btc = collect($saldos['balances'])->first(fn($b) => $b['asset'] === 'BTC');

        return ((float) ($brl['free'] ?? 0) + (float) ($brl['locked'] ?? 0))
             + (((float) ($btc['free'] ?? 0) + (float) ($btc['locked'] ?? 0)) * $preco);
    }
}
