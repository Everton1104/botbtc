<?php

namespace App\Services;

use App\Models\PixPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integração com Mercado Pago PIX
 *
 * Fluxo:
 *   1. criarCobranca() → POST /v1/payments com payment_method_id=pix
 *   2. Retorna QR code base64 + copia-e-cola + id do pagamento
 *   3. Usuário paga
 *   4. MP envia webhook POST na sua URL → webhook() no controller confirma
 *
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/checkout-api/payment-methods/other-payment-methods/how-to-integrate-pix
 */
class MercadoPagoService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private string $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
    }

    /**
     * Cria um pagamento PIX no Mercado Pago e salva no banco.
     *
     * @param  int|null $userId       ID do usuário (null para cobranças anônimas)
     * @param  float    $valor        Valor em BRL (mínimo R$ 0,01)
     * @param  string   $descricao    Descrição visível no comprovante
     * @param  string   $emailPagador E-mail do pagador (obrigatório pelo MP)
     * @param  int      $expiracaoMin Tempo até expirar em minutos (padrão: 30)
     */
    public function criarCobranca(
        ?int $userId,
        float $valor,
        string $descricao = 'Pagamento',
        string $emailPagador = 'pagador@email.com',
        int $expiracaoMin = 30
    ): PixPayment {
        // Idempotency key evita cobrar duas vezes se a requisição repetir
        $idempotencyKey = Str::uuid()->toString();
        // MP exige formato: 2026-01-01T00:00:00.000-03:00 (com milissegundos e offset)
        $expiracao = now()->addMinutes($expiracaoMin)->format('Y-m-d\TH:i:s.000P');

        $body = [
            'transaction_amount' => round($valor, 2),
            'description'        => $descricao,
            'payment_method_id'  => 'pix',
            'date_of_expiration' => $expiracao,
            'payer'              => [
                'email' => $emailPagador,
            ],
        ];

        $response = Http::withToken($this->accessToken)
            ->withHeaders([
                'X-Idempotency-Key' => $idempotencyKey,
                'Accept'            => 'application/json',
            ])
            ->post(self::BASE_URL . '/v1/payments', $body);

        if ($response->failed()) {
            Log::error('MercadoPago criarCobranca falhou', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $response->throw();
        }

        $data = $response->json();

        // O MP retorna os dados do PIX dentro de point_of_interaction.transaction_data
        $pixData    = $data['point_of_interaction']['transaction_data'] ?? [];
        $qrCode     = $pixData['qr_code_base64'] ?? null; // imagem PNG em base64
        $copiaECola = $pixData['qr_code']        ?? null; // string copia e cola

        // txid = ID do pagamento no MP (número inteiro, guardamos como string)
        $txid = (string) $data['id'];

        return PixPayment::create([
            'user_id'      => $userId,
            'txid'         => $txid,
            'valor'        => $valor,
            'descricao'    => $descricao,
            'status'       => 'pendente',
            'qr_code'      => $qrCode,
            'copia_e_cola' => $copiaECola,
            'expiracao'    => now()->addMinutes($expiracaoMin),
        ]);
    }

    /**
     * Consulta o status de um pagamento diretamente na API.
     * Útil para polling (verificar se foi pago sem depender do webhook).
     *
     * Status possíveis: pending | approved | rejected | cancelled | refunded
     */
    public function consultarPagamento(string $paymentId): array
    {
        $response = Http::withToken($this->accessToken)
            ->get(self::BASE_URL . "/v1/payments/{$paymentId}");

        $response->throw();

        return $response->json();
    }

    /**
     * Estorna um pagamento PIX aprovado.
     *
     * O cliente recebe 100% de volta.
     * A taxa de 1% do Mercado Pago NÃO é devolvida ao merchant.
     *
     * Só funciona em pagamentos com status 'approved'.
     */
    public function estornar(string $paymentId): array
    {
        $response = Http::withToken($this->accessToken)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(self::BASE_URL . "/v1/payments/{$paymentId}/refunds", []);

        if ($response->failed()) {
            Log::error('MercadoPago estorno falhou', [
                'payment_id' => $paymentId,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            $response->throw();
        }

        return $response->json();
    }

    /**
     * Valida a assinatura do webhook enviado pelo Mercado Pago.
     *
     * O MP envia os headers:
     *   x-signature: ts=...,v1=<hash>
     *   x-request-id: <uuid>
     *
     * Documentação: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
     */
    public function validarWebhook(string $payloadRaw, string $xSignature, string $xRequestId): bool
    {
        $secret = config('services.mercadopago.webhook_secret');

        if (empty($secret)) {
            Log::warning('MercadoPago webhook_secret não configurado — validação ignorada');
            return true;
        }

        // Extrai ts e v1 do header x-signature
        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$k, $v] = explode('=', trim($part), 2);
            $parts[$k] = $v;
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if (!$ts || !$v1) return false;

        // Monta a string de assinatura: id={data.id}&request-id={x-request-id}&ts={ts}
        $data      = json_decode($payloadRaw, true);
        $dataId    = $data['data']['id'] ?? '';
        $manifest  = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";

        $esperado = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($esperado, $v1);
    }
}
