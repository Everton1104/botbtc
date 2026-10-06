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
                'id'         => $p->id,
                'valor'      => (float) $p->valor,
                'pago_em'    => $p->pago_em?->format('d/m/Y H:i') ?? '—',
                'btc_price'  => $p->btc_price ? (float) $p->btc_price : null,
                'status'     => $p->status,
                'registrado' => (bool) $p->registrado,
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
                'id'         => $p->id,
                'txid'       => $p->txid,
                'user_id'    => $p->user_id,
                'user_name'  => $p->user?->name  ?? 'Desconhecido',
                'user_email' => $p->user?->email ?? '—',
                'valor'      => (float) $p->valor,
                'liquido'    => $this->valorACreditar($p),
                'metodo'     => str_starts_with($p->txid, 'manual_')
                    ? 'Manual'
                    : ($p->capture_method === 'credit_card'
                        ? 'Cartão ' . (int) $p->installments . 'x'
                        : 'PIX'),
                'pago_em'    => $p->pago_em?->format('d/m/Y H:i'),
                'registrado' => (bool) $p->registrado,
                'estornado'  => $p->status === 'estornado',
            ])->all();
    }

    /**
     * REGISTRAR NO BOT — os 2 passos do site (investir-manual + marcar
     * registrado) numa única transaction. Crédito pelo valor LÍQUIDO.
     */
    public function registrarDeposito(int $pixId): array
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
            $this->creditarCotasNaTransacao($pix->user_id, $valor, $patrimonio);

            $updates = ['registrado' => true];
            if ($btcPrice) {
                $updates['btc_price'] = $btcPrice;
            }
            $pix->update($updates);
        });

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
     * $valorJaNoPatrimonio distingue os dois fluxos:
     *  • PIX/registrar (true): o dinheiro JÁ caiu na Binance antes do
     *    registro — subtrai o valor do patrimônio pra não inflar o preço
     *    da cota (comportamento original);
     *  • manual (false): o admin registra ANTES de transferir — o
     *    patrimônio lido ainda não contém o aporte, então é usado puro.
     *    Foi o bug das cotas infladas do depósito de R$ 5.000 (subtraiu
     *    dinheiro que ainda não estava lá: preço/cota R$ 0,35 vs R$ 1,16
     *    real → 3,3× mais cotas que o certo).
     */
    private function creditarCotasNaTransacao(
        int $userId,
        float $valor,
        float $patrimonioAtual,
        bool $valorJaNoPatrimonio = true,
    ): void {
        // Lock para evitar corrida com outros depósitos simultâneos
        $totalCotas = (float) BotInvestment::lockForUpdate()->sum('cotas');

        // No fluxo PIX o dinheiro já está na Binance, mas não deve inflar
        // o preço da cota antes do registro; no manual ele nem chegou lá.
        $base            = $valorJaNoPatrimonio ? max(0, $patrimonioAtual - $valor) : $patrimonioAtual;
        $precoPorCota    = $totalCotas > 0 ? $base / $totalCotas : 1.0;
        $novasCotas      = $valor / $precoPorCota;

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
