<?php

namespace App\Http\Controllers;

use App\Http\Controllers\BinanceController;
use App\Http\Controllers\WhatsappController;
use App\Models\PixPayment;
use App\Services\InfinitePayService;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PixController extends Controller
{
    public function __construct(private MercadoPagoService $mp, private InfinitePayService $ip) {}

    /** Gateway ativo: 'infinitepay' (link) ou 'mercadopago' (PIX QR). */
    private function gateway(): string
    {
        return (string) config('services.infinitepay.gateway_padrao', 'mercadopago');
    }

    /** Helper: marca um pagamento como pago + dispara notificação. */
    private function confirmarPago(PixPayment $pagamento, ?array $payload = null, array $extra = []): void
    {
        $btcPrice = null;
        try { $btcPrice = app(BinanceController::class)->getPrecoBTC(); } catch (\Throwable) {}

        $pagamento->update(array_merge([
            'status'          => 'pago',
            'pago_em'         => now(),
            'btc_price'       => $btcPrice,
            'payload_webhook' => $payload,
        ], $extra));

        $pagamento->loadMissing('user');
        WhatsappController::notificarDeposito(
            (float) $pagamento->valor,
            $pagamento->user?->name ?? 'Desconhecido'
        );
    }

    /**
     * Valor LÍQUIDO a creditar, conforme o método (taxas da conta compartilhada
     * InfinitePay — lojista paga o cartão até 6x; PIX é grátis; 7-12x o cliente
     * paga). Só desconta no cartão 1-6x; nos demais, integral.
     */
    private function calcularValorLiquido(float $valor, ?string $captureMethod, int $installments): float
    {
        if ($captureMethod === 'credit_card' && $installments >= 1 && $installments <= 6) {
            // À vista (1x) ≈ 3,15% oficial; +~0,85%/parcela, cap 12,4% (aproxima a curva).
            $taxa = min(0.124, 0.0315 + max(0, $installments - 1) * 0.0085);
        } else {
            $taxa = 0.0; // PIX (grátis) ou cartão 7-12x (cliente paga) → integral.
        }
        return round($valor * (1 - $taxa), 2);
    }

    /**
     * Cria uma nova cobrança PIX para o usuário autenticado.
     * POST /pix/criar
     *
     * Body JSON: { "valor": 29.90, "descricao": "Recarga de saldo" }
     */
    public function criar(Request $request)
    {
        $request->validate([
            'valor'     => 'required|numeric|min:0.01',
            'descricao' => 'nullable|string|max:140',
        ]);

        if ($this->gateway() === 'infinitepay') {
            return $this->criarInfinitePay($request);
        }

        try {
            $pagamento = $this->mp->criarCobranca(
                userId:       auth()->id(),
                valor:        (float) $request->valor,
                descricao:    $request->descricao ?? 'Pagamento',
                emailPagador: auth()->user()->email,
            );

            return response()->json([
                'txid'         => $pagamento->txid,
                'valor'        => $pagamento->valor,
                'status'       => $pagamento->status,
                'qr_code'      => $pagamento->qr_code,      // base64 → <img src="data:image/png;base64,{qr_code}">
                'copia_e_cola' => $pagamento->copia_e_cola,
                'expiracao'    => $pagamento->expiracao,
                'gateway'      => 'mercadopago',
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao criar cobrança PIX', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Não foi possível gerar o PIX. Tente novamente.'], 500);
        }
    }

    /**
     * Cria um link de pagamento InfinitePay (redirect).
     * O webhook é validado pelo token EMBEDDED na URL (enviada no criarLink).
     */
    private function criarInfinitePay(Request $request)
    {
        try {
            $pagamento = PixPayment::create([
                'user_id'  => auth()->id(),
                'txid'     => 'ip_' . Str::uuid()->toString(),
                'valor'    => (float) $request->valor,
                'descricao'=> $request->descricao ?? 'Pagamento',
                'status'   => 'pendente',
                'expiracao'=> now()->addDay(),
            ]);

            $link = $this->ip->criarLink($pagamento);

            if (!empty($link['erro'])) {
                $pagamento->update(['status' => 'cancelado']);
                return response()->json(['error' => $link['msg']], 500);
            }

            // Persiste o slug (lido pelo payment_check) e a URL no copia_e_cola
            // (campo reaproveitado: "o que o usuário usa pra pagar" — link ou QR).
            $pagamento->update([
                'infinitepay_slug' => $link['slug'] ?: null,
                'copia_e_cola'     => $link['url'],
            ]);

            return response()->json([
                'txid'         => $pagamento->txid,
                'valor'        => (float) $pagamento->valor,
                'status'       => 'pendente',
                'payment_url'  => $link['url'],     // blade: botão "Pagar" → redirect
                'expiracao'    => $pagamento->expiracao,
                'gateway'      => 'infinitepay',
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao criar link InfinitePay', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Não foi possível gerar o link de pagamento.'], 500);
        }
    }

    /**
     * Consulta o status de um pagamento pelo txid (polling).
     * GET /pix/status/{txid}
     */
    public function status(string $txid)
    {
        $pagamento = PixPayment::where('txid', $txid)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($pagamento->status === 'pendente') {
            try {
                if (str_starts_with($pagamento->txid, 'ip_')) {
                    // InfinitePay — confirma via payment_check.
                    $this->sincronizarInfinitePay($pagamento);
                } else {
                    // MercadoPago (legado, para pagamentos criados antes da troca).
                    $dados = $this->mp->consultarPagamento($txid);

                    match ($dados['status'] ?? '') {
                        'approved' => $this->confirmarPago($pagamento, $dados),
                        'cancelled',
                        'rejected' => $pagamento->update(['status' => 'cancelado']),
                        default    => $pagamento->isExpirado() ? $pagamento->update(['status' => 'expirado']) : null,
                    };
                }

                $pagamento->refresh();
            } catch (\Exception $e) {
                Log::warning('Erro ao consultar pagamento', ['txid' => $txid, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'txid'      => $pagamento->txid,
            'status'    => $pagamento->status,
            'valor'     => $pagamento->valor,
            'pago_em'   => $pagamento->pago_em,
            'expiracao' => $pagamento->expiracao,
        ]);
    }

    /**
     * Sincroniza um pagamento InfinitePay: persiste slug/nsu (vindos do webhook)
     * e confirma via payment_check. Anti-golpe: o `amount` (centavos) tem que
     * bater com o valor da cobrança. Reutilizado pelo webhook e pelo status.
     */
    public function sincronizarInfinitePay(PixPayment $pagamento, string $transactionNsu = '', string $invoiceSlug = ''): void
    {
        $dirty = [];
        if ($transactionNsu !== '' && (string) $pagamento->infinitepay_transaction !== $transactionNsu) {
            $dirty['infinitepay_transaction'] = $transactionNsu;
        }
        if ($invoiceSlug !== '' && (string) $pagamento->infinitepay_slug !== $invoiceSlug) {
            $dirty['infinitepay_slug'] = $invoiceSlug;
        }
        if ($dirty) {
            $pagamento->update($dirty);
            $pagamento->refresh();
        }

        if ($pagamento->status !== 'pendente') {
            return; // idempotência: já resolvido
        }

        $res = $this->ip->consultarStatus($pagamento);
        if (isset($res['erro']) || empty($res['paid'])) {
            if ($pagamento->isExpirado()) {
                $pagamento->update(['status' => 'expirado']);
            }
            return;
        }

        // Anti-golpe: amount (valor pedido, centavos) deve bater com a cobrança.
        if (isset($res['amount']) && $res['amount'] !== null
            && abs(((int) $res['amount'] / 100) - (float) $pagamento->valor) > 0.01) {
            Log::error('[InfinitePay] divergência de valor', [
                'pix' => $pagamento->id, 'valor' => $pagamento->valor, 'amount' => $res['amount'],
            ]);
            return;
        }

        // Método + parcelas definem o líquido (taxa só no cartão 1-6x).
        $captureMethod = $res['capture_method'] ?? null;
        $installments  = (int) ($res['installments'] ?? 1);
        $valorLiquido  = $this->calcularValorLiquido((float) $pagamento->valor, $captureMethod, $installments);

        $this->confirmarPago($pagamento, $res, [
            'capture_method' => $captureMethod,
            'installments'   => $installments,
            'valor_liquido'  => $valorLiquido,
        ]);
        Log::info('InfinitePay aprovado', [
            'txid' => $pagamento->txid, 'valor' => $pagamento->valor, 'liquido' => $valorLiquido,
            'metodo' => $captureMethod, 'parcelas' => $installments,
        ]);
    }

    /**
     * Webhook InfinitePay. ROTA PÚBLICA com token EMBEDDED no path
     * (a URL com token foi enviada no criarLink). Sem CSRF (bootstrap/app.php).
     * O body serve só de gatilho + trazer slug/nsu — a confiança está no payment_check.
     * POST /infinitepay/webhook/{token}
     */
    public function webhookInfinitePay(Request $request, string $token)
    {
        $esperado = (string) config('services.infinitepay.webhook_token');
        if ($esperado !== '' && !hash_equals($esperado, $token)) {
            return response()->json([], 403);
        }

        $orderNsu       = (string) ($request->input('order_nsu') ?? '');
        $transactionNsu = (string) ($request->input('transaction_nsu') ?? '');
        $invoiceSlug    = (string) ($request->input('invoice_slug') ?? '');

        // Responde 200 rápido; a confirmação pesada vem abaixo. Em erro ainda 200
        // pra InfinitePay não retentar exaustivamente.
        try {
            if ($orderNsu !== '') {
                $pagamento = PixPayment::where('txid', $orderNsu)->first();
                if ($pagamento) {
                    $this->sincronizarInfinitePay($pagamento, $transactionNsu, $invoiceSlug);
                } else {
                    Log::warning('[InfinitePay] webhook: pagamento não encontrado', ['order_nsu' => $orderNsu]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[InfinitePay] webhook: erro ao sincronizar', ['order_nsu' => $orderNsu, 'msg' => $e->getMessage()]);
        }

        return response()->json([], 200);
    }

    /**
     * Landing após o redirect da InfinitePay (o usuário pagou e voltou).
     * GET /pix/retorno/{pagamento}?ref={txid}
     * A confirmação real vem pelo webhook; aqui só redireciona pro painel.
     */
    public function retorno(int $pagamento)
    {
        $p = PixPayment::findOrFail($pagamento);
        // Tenta confirmar na hora (caso o webhook ainda não tenha chegado).
        if ($p->status === 'pendente' && str_starts_with($p->txid, 'ip_')) {
            try { $this->sincronizarInfinitePay($p); } catch (\Throwable) {}
        }
        return redirect()->to('/?pagamento=' . $p->id);
    }

    /**
     * Webhook recebido pelo Mercado Pago quando um pagamento muda de status.
     * POST /pix/webhook
     *
     * Esta rota é PÚBLICA (sem auth, sem CSRF).
     * Cadastrar a URL no painel MP: Suas integrações → Webhooks → URL de produção
     */
    public function webhook(Request $request)
    {
        $payloadRaw = $request->getContent();
        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');

        if (!$this->mp->validarWebhook($payloadRaw, $xSignature, $xRequestId)) {
            Log::warning('Webhook PIX com assinatura inválida', ['ip' => $request->ip()]);
            return response('Unauthorized', 401);
        }

        $data = json_decode($payloadRaw, true);

        // MP envia type=payment para notificações de pagamento
        if (($data['type'] ?? '') !== 'payment') {
            return response('ok', 200);
        }

        $paymentId = (string) ($data['data']['id'] ?? '');

        if (!$paymentId) return response('ok', 200);

        $pagamento = PixPayment::where('txid', $paymentId)->first();

        if (!$pagamento || $pagamento->status !== 'pendente') {
            return response('ok', 200);
        }

        // Confirma o status real na API (não confia cegamente no webhook)
        try {
            $dados = $this->mp->consultarPagamento($paymentId);

            if (($dados['status'] ?? '') === 'approved') {
                $btcPrice = null;
                try { $btcPrice = app(BinanceController::class)->getPrecoBTC(); } catch (\Throwable) {}

                $pagamento->update([
                    'status'          => 'pago',
                    'pago_em'         => now(),
                    'btc_price'       => $btcPrice,
                    'payload_webhook' => $data,
                ]);

                Log::info('PIX aprovado via webhook', ['txid' => $paymentId, 'valor' => $pagamento->valor]);

                $pagamento->loadMissing('user');
                WhatsappController::notificarDeposito(
                    (float) $pagamento->valor,
                    $pagamento->user?->name ?? 'Desconhecido'
                );
            }
        } catch (\Exception $e) {
            Log::error('Erro ao confirmar pagamento via webhook', ['error' => $e->getMessage()]);
            return response('error', 500);
        }

        return response('ok', 200);
    }
}
