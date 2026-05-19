<?php

namespace App\Services;

use App\Models\PixPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PagBankService
{
    private string $token;
    private string $baseUrl;
    private string $pixKey;

    public function __construct()
    {
        $this->token   = config('services.pagbank.token');
        $this->baseUrl = rtrim(config('services.pagbank.base_url'), '/');
        $this->pixKey  = config('services.pagbank.pix_key');
    }

    /**
     * Cria uma cobrança PIX e salva no banco.
     *
     * @param  int|null $userId       ID do usuário (null para cobranças anônimas)
     * @param  float    $valor        Valor em BRL (ex: 29.90)
     * @param  string   $descricao    Descrição visível no comprovante
     * @param  int      $expiracaoSeg Tempo até expirar em segundos (padrão: 1h)
     */
    public function criarCobranca(
        ?int $userId,
        float $valor,
        string $descricao = 'Pagamento',
        int $expiracaoSeg = 3600
    ): PixPayment {
        // txid é gerado por você — 26 a 35 caracteres alfanuméricos
        $txid = Str::upper(Str::random(26));

        $body = [
            'calendario'     => ['expiracao' => $expiracaoSeg],
            'valor'          => ['original' => number_format($valor, 2, '.', '')],
            'chave'          => $this->pixKey,
            'solicitacaoPagador' => $descricao,
        ];

        $response = Http::withToken($this->token)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'BotBTC/1.0',
            ])
            ->put("{$this->baseUrl}/instant-payments/cob/{$txid}", $body);

        if ($response->failed()) {
            Log::error('PagBank criarCobranca falhou', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $response->throw();
        }

        $data = $response->json();

        // O QR code pode vir direto na resposta ou em endpoint separado
        $qrCode      = $data['imagemQrcode']  ?? $this->buscarQrCode($txid);
        $copiaECola  = $data['pixCopiaECola'] ?? null;

        return PixPayment::create([
            'user_id'      => $userId,
            'txid'         => $txid,
            'valor'        => $valor,
            'descricao'    => $descricao,
            'status'       => 'pendente',
            'qr_code'      => $qrCode,
            'copia_e_cola' => $copiaECola,
            'expiracao'    => now()->addSeconds($expiracaoSeg),
        ]);
    }

    /**
     * Busca a imagem QR code em base64 de uma cobrança existente.
     * Endpoint separado do PagBank: GET /instant-payments/cob/{txid}/qrcode
     */
    public function buscarQrCode(string $txid): ?string
    {
        $response = Http::withToken($this->token)
            ->get("{$this->baseUrl}/instant-payments/cob/{$txid}/qrcode");

        if ($response->failed()) {
            Log::warning('PagBank buscarQrCode falhou', ['txid' => $txid, 'status' => $response->status()]);
            return null;
        }

        return $response->json('imagemQrcode');
    }

    /**
     * Consulta o status atual de uma cobrança diretamente na API.
     * Útil para polling quando webhook não está configurado.
     *
     * Retorna array com campo 'status': ATIVA | CONCLUIDA | REMOVIDA_PELO_USUARIO_RECEBEDOR | REMOVIDA_PELO_PSP
     */
    public function consultarCobranca(string $txid): array
    {
        $response = Http::withToken($this->token)
            ->get("{$this->baseUrl}/instant-payments/cob/{$txid}");

        $response->throw();

        return $response->json();
    }

    /**
     * Valida a assinatura HMAC do webhook enviado pelo PagBank.
     * O PagBank envia o header: x-webhook-signature: <hash>
     *
     * IMPORTANTE: sempre valide antes de processar qualquer webhook.
     */
    public function validarWebhook(string $payloadRaw, string $assinaturaHeader): bool
    {
        $secret   = config('services.pagbank.webhook_secret');

        // Se webhook_secret não estiver configurado, loga aviso mas deixa passar (desenvolvimento)
        if (empty($secret)) {
            Log::warning('PagBank webhook_secret não configurado — validação ignorada');
            return true;
        }

        $esperado = hash_hmac('sha256', $payloadRaw, $secret);
        return hash_equals($esperado, $assinaturaHeader);
    }
}
