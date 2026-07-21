<?php

namespace App\Services;

use App\Models\PixPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * InfinitePay — Checkout Integrado (Link de Pagamento).
 * api.checkout.infinitepay.io. Sem credenciais: o lojista é identificado pelo
 * HANDLE (InfiniteTag). O webhook da InfinitePay NÃO é assinado, então a
 * confirmação de pagamento SEMPRE passa por consultarStatus() (payment_check) —
 * nunca confiamos no body do webhook para aprovar.
 *
 * Portado do projeto anavertuan, adaptado ao modelo PixPayment do botbtc.
 */
class InfinitePayService
{
    private const API = 'https://api.checkout.infinitepay.io';

    private function handle(): ?string
    {
        $h = config('services.infinitepay.handle');
        return $h ? trim(ltrim((string) $h, '$')) : null;
    }

    /**
     * Cria um link de pagamento (redirect) para o PIX/depósito.
     *
     * @return array{erro?:int,msg?:string,url?:string,slug?:string}
     */
    public function criarLink(PixPayment $pagamento): array
    {
        if (!$handle = $this->handle()) {
            return ['erro' => 1, 'msg' => 'INFINITEPAY_HANDLE não configurado'];
        }

        $payload = [
            'handle'    => $handle,
            'items'     => [
                [
                    'quantity'    => 1,
                    'price'       => (int) round((float) $pagamento->valor * 100), // centavos
                    'description' => mb_substr((string) $pagamento->descricao, 0, 100) ?: 'Depósito BotBTC',
                ],
            ],
            // order_nsu ancora o webhook + payment_check ao pagamento (usa o txid).
            'order_nsu' => (string) $pagamento->txid,
            // redirect_url valida pelo `ref` (= txid): a InfinitePay ADICIONA query
            // params ao redirecionar de volta, o que invalidaria uma URL assinada.
            'redirect_url' => route('pix.retorno', ['pagamento' => $pagamento->id]) . '?ref=' . urlencode((string) $pagamento->txid),
            'webhook_url'  => route('infinitepay.webhook', ['token' => (string) config('services.infinitepay.webhook_token')]),
        ];

        try {
            $resp = Http::asJson()->timeout(20)->post(self::API . '/links', $payload);
        } catch (\Throwable $e) {
            Log::error('[InfinitePay] criarLink exceção', ['pix' => $pagamento->id, 'msg' => $e->getMessage()]);
            return ['erro' => 1, 'msg' => 'Falha ao conectar à InfinitePay.'];
        }

        if ($resp->status() >= 400) {
            Log::warning('[InfinitePay] criarLink erro', [
                'pix' => $pagamento->id, 'status' => $resp->status(), 'body' => $resp->json(),
            ]);
            return ['erro' => 1, 'msg' => 'Não foi possível gerar o link de pagamento.'];
        }

        $data = $resp->json() ?? [];
        $url  = $data['url'] ?? $data['link'] ?? $data['checkout_url'] ?? null;
        $slug = $data['slug'] ?? $data['invoice_slug'] ?? null;
        if (!$url) {
            Log::warning('[InfinitePay] criarLink sem url na resposta', ['pix' => $pagamento->id, 'body' => $data]);
            return ['erro' => 1, 'msg' => 'Resposta inválida da InfinitePay.'];
        }

        return ['url' => (string) $url, 'slug' => (string) ($slug ?? '')];
    }

    /**
     * Consulta o status real do pagamento (payment_check). O `slug`/`transaction_nsu`
     * vêm apenas do webhook, então são persistidos no PixPayment e lidos daqui.
     *
     * @return array{erro?:int,msg?:string,paid?:bool,amount?:?int,paid_amount?:?int}
     */
    public function consultarStatus(PixPayment $pagamento): array
    {
        if (!$handle = $this->handle()) {
            return ['erro' => 1, 'msg' => 'INFINITEPAY_HANDLE não configurado'];
        }

        $payload = [
            'handle'    => $handle,
            'order_nsu' => (string) $pagamento->txid,
        ];
        if ($pagamento->infinitepay_slug) {
            $payload['slug'] = (string) $pagamento->infinitepay_slug;
        }
        if ($pagamento->infinitepay_transaction) {
            $payload['transaction_nsu'] = (string) $pagamento->infinitepay_transaction;
        }

        try {
            $resp = Http::asJson()->timeout(20)->post(self::API . '/payment_check', $payload);
        } catch (\Throwable $e) {
            Log::error('[InfinitePay] consultarStatus exceção', ['pix' => $pagamento->id, 'msg' => $e->getMessage()]);
            return ['erro' => 1, 'msg' => 'Falha ao consultar a InfinitePay.'];
        }

        $data = $resp->json() ?? [];
        if ($resp->status() >= 400 || empty($data['paid'])) {
            Log::info('[InfinitePay] payment_check sem pagamento', [
                'pix' => $pagamento->id, 'http' => $resp->status(), 'resp' => $data,
            ]);
        }
        if ($resp->status() >= 400) {
            return ['erro' => 1, 'msg' => 'Não foi possível confirmar o pagamento.'];
        }

        return [
            'paid'           => (bool) ($data['paid'] ?? false),
            'amount'         => isset($data['amount']) ? (int) $data['amount'] : null,
            'paid_amount'    => isset($data['paid_amount']) ? (int) $data['paid_amount'] : null,
            'capture_method' => isset($data['capture_method']) ? (string) $data['capture_method'] : null, // credit_card | pix
            'installments'   => (int) ($data['installments'] ?? 1),
        ];
    }
}
