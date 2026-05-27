<?php

namespace App\Http\Controllers;

use App\Models\BotState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BinanceController extends Controller
{
    private const SYMBOL = 'BTCBRL';

    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey    = config('services.binance.key');
        $this->secretKey = config('services.binance.secret');
        $this->baseUrl   = 'https://api.binance.com';
    }

    // ============================================================
    // HELPERS INTERNOS
    // ============================================================

    private function assinar(string $queryString): string
    {
        return hash_hmac('sha256', $queryString, $this->secretKey);
    }

    private function timestamp(): int
    {
        return now()->getTimestampMs();
    }

    // GET / DELETE — parâmetros e assinatura na URL
    private function enviar(string $endpoint, string $params, string $method = 'GET'): ?array
    {
        $url      = "{$this->baseUrl}{$endpoint}?{$params}";
        $headers  = ['X-MBX-APIKEY' => $this->apiKey];

        $response = match ($method) {
            'DELETE' => Http::withHeaders($headers)->delete($url),
            default  => Http::withHeaders($headers)->get($url),
        };

        if (!$response->successful()) {
            Log::warning("BinanceController [{$method} {$endpoint}]: HTTP {$response->status()} — {$response->body()}");
        }

        return $response->json();
    }

    // POST — parâmetros assinados no corpo (application/x-www-form-urlencoded)
    private function enviarPost(string $endpoint, array $params): array
    {
        $params['signature'] = $this->assinar(http_build_query($params));

        $response = Http::withHeaders(['X-MBX-APIKEY' => $this->apiKey])
            ->asForm()
            ->post("{$this->baseUrl}{$endpoint}", $params);

        if (!$response->successful()) {
            Log::warning("BinanceController [POST {$endpoint}]: HTTP {$response->status()} — {$response->body()}");
        }

        return $response->json() ?? [];
    }

    // ============================================================
    // ORDENS LIMIT (BUY / SELL)
    // ============================================================

    private function ordemLimit(string $side, float $preco, float $quantidade): array
    {
        return $this->enviarPost('/api/v3/order', [
            'symbol'      => self::SYMBOL,
            'side'        => $side,
            'type'        => 'LIMIT',
            'timeInForce' => 'GTC',
            'price'       => number_format($preco, 2, '.', ''),
            'quantity'    => number_format($quantidade, 5, '.', ''),
            'timestamp'   => $this->timestamp(),
        ]);
    }

    public function buyLimit(float $preco, float $quantidade): array
    {
        return $this->ordemLimit('BUY', $preco, $quantidade);
    }

    public function sellLimit(float $preco, float $quantidade): array
    {
        return $this->ordemLimit('SELL', $preco, $quantidade);
    }

    // ============================================================
    // CANCELAR ORDEM
    // ============================================================

    public function cancelarOrdem(string $symbol, string $orderId): ?array
    {
        $params = http_build_query([
            'symbol'    => $symbol,
            'orderId'   => $orderId,
            'timestamp' => $this->timestamp(),
        ]);

        return $this->enviar('/api/v3/order', "{$params}&signature={$this->assinar($params)}", 'DELETE');
    }

    // ============================================================
    // CONSULTAR ORDEM
    // ============================================================

    public function getOrder(string $symbol, string $orderId): ?array
    {
        $params = http_build_query([
            'symbol'    => $symbol,
            'orderId'   => $orderId,
            'timestamp' => $this->timestamp(),
        ]);

        return $this->enviar('/api/v3/order', "{$params}&signature={$this->assinar($params)}");
    }

    // ============================================================
    // LISTAR ORDENS ABERTAS
    // ============================================================

    public function getOpenOrders(string $symbol = self::SYMBOL): ?array
    {
        $params = http_build_query([
            'symbol'    => $symbol,
            'timestamp' => $this->timestamp(),
        ]);

        return $this->enviar('/api/v3/openOrders', "{$params}&signature={$this->assinar($params)}");
    }

    // ============================================================
    // SALDOS DA CONTA
    // ============================================================

    public function getSaldos(): ?array
    {
        $params = http_build_query(['timestamp' => $this->timestamp()]);

        return $this->enviar('/api/v3/account', "{$params}&signature={$this->assinar($params)}");
    }

    // ============================================================
    // PREÇO ATUAL
    // ============================================================

    public function getPrecoBTC(): float
    {
        $response = Http::get("{$this->baseUrl}/api/v3/ticker/price?symbol=" . self::SYMBOL);
        return (float) ($response->json()['price'] ?? 0.0);
    }

    public function getPrecos(): array
    {
        $btc = Http::get("{$this->baseUrl}/api/v3/ticker/price?symbol=BTCBRL")->json()['price'] ?? 0;
        $bnb = Http::get("{$this->baseUrl}/api/v3/ticker/price?symbol=BNBBRL")->json()['price'] ?? 0;

        return ['BTCBRL' => (float) $btc, 'BNBBRL' => (float) $bnb];
    }

    // ============================================================
    // COMPRAR BNB A MERCADO (gasta exatamente $valorBRL em BRL)
    // ============================================================

    public function comprarBNBMercado(float $valorBRL): array
    {
        return $this->enviarPost('/api/v3/order', [
            'symbol'        => 'BNBBRL',
            'side'          => 'BUY',
            'type'          => 'MARKET',
            'quoteOrderQty' => number_format($valorBRL, 2, '.', ''),
            'timestamp'     => $this->timestamp(),
        ]);
    }

    // ============================================================
    // VENDER BTC A MERCADO (recebe BRL)
    // ============================================================

    public function sellMarketBTC(float $quantidade): array
    {
        return $this->enviarPost('/api/v3/order', [
            'symbol'    => self::SYMBOL,
            'side'      => 'SELL',
            'type'      => 'MARKET',
            'quantity'  => number_format($quantidade, 5, '.', ''),
            'timestamp' => $this->timestamp(),
        ]);
    }

    // ============================================================
    // KLINES (velas — endpoint público, sem autenticação)
    // ============================================================

    public function getKlines(string $symbol, string $interval, int $limit): array
    {
        $response = Http::get("{$this->baseUrl}/api/v3/klines?symbol={$symbol}&interval={$interval}&limit={$limit}");
        return $response->json() ?? [];
    }

    // ============================================================
    // CONFIGURAÇÃO DO BOT (usado pelo frontend autenticado)
    // ============================================================

    public function getConf(): ?BotState
    {
        return BotState::where('id_user', Auth::user()->id)->first();
    }
}
