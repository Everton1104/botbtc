<?php

namespace App\Http\Controllers;

use App\Models\PixPayment;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PixController extends Controller
{
    public function __construct(private MercadoPagoService $mp) {}

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
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao criar cobrança PIX', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Não foi possível gerar o PIX. Tente novamente.'], 500);
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
                $dados = $this->mp->consultarPagamento($txid);

                match ($dados['status'] ?? '') {
                    'approved' => $pagamento->update(['status' => 'pago',      'pago_em' => now()]),
                    'cancelled',
                    'rejected' => $pagamento->update(['status' => 'cancelado']),
                    default    => $pagamento->isExpirado() ? $pagamento->update(['status' => 'expirado']) : null,
                };

                $pagamento->refresh();
            } catch (\Exception $e) {
                Log::warning('Erro ao consultar pagamento MP', ['txid' => $txid, 'error' => $e->getMessage()]);
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
                $pagamento->update([
                    'status'          => 'pago',
                    'pago_em'         => now(),
                    'payload_webhook' => $data,
                ]);

                Log::info('PIX aprovado via webhook', ['txid' => $paymentId, 'valor' => $pagamento->valor]);

                // -------------------------------------------------------
                // AQUI você adiciona a lógica após pagamento confirmado:
                // Ex: $pagamento->user->incrementSaldo($pagamento->valor);
                // Ex: event(new PagamentoConfirmado($pagamento));
                // -------------------------------------------------------
            }
        } catch (\Exception $e) {
            Log::error('Erro ao confirmar pagamento via webhook', ['error' => $e->getMessage()]);
            return response('error', 500);
        }

        return response('ok', 200);
    }
}
