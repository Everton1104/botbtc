<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class BinanceController extends Controller
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey    = env('BINANCE_API_KEY');
        $this->secretKey = env('BINANCE_SECRET_KEY');
        $this->baseUrl   = "https://api.binance.com";
    }

    // ============================================================
    // ASSINAR REQUISIÇÕES
    // ============================================================

    private function assinar(string $queryString)
    {
        return hash_hmac('sha256', $queryString, $this->secretKey);
    }

    // ============================================================
    // ENVIAR REQUISIÇÃO
    // ============================================================

    private function enviar(string $endpoint, string $params, string $method = "POST")
    {
        $url = $this->baseUrl . $endpoint . "?" . $params;

        $headers = [
            "X-MBX-APIKEY" => $this->apiKey
        ];

        if ($method === "GET") {
            $response = Http::withHeaders($headers)->get($url);
        } elseif ($method === "DELETE") {
            $response = Http::withHeaders($headers)->delete($url);
        } else {
            $response = Http::withHeaders($headers)->post($url);
        }

        return $response->json();
    }

    // ============================================================
    // ORDEM LIMIT BUY
    // ============================================================

    public function buyLimit(float $preco, float $quantidade)
    {
        $symbol = "BTCBRL";
        $timestamp = now()->getTimestampMs();

        // Formatar corretamente
        $preco = number_format($preco, 2, '.', '');
        $quantidade = number_format($quantidade, 5, '.', '');

        $params = http_build_query([
            'symbol'      => $symbol,
            'side'        => 'BUY',
            'type'        => 'LIMIT',
            'timeInForce' => 'GTC',
            'price'       => $preco,
            'quantity'    => $quantidade,
            'timestamp'   => $timestamp
        ]);

        $signature = $this->assinar($params);

        // Enviar parâmetros no corpo, não na URL
        $url = $this->baseUrl . "/api/v3/order";

        return Http::withHeaders([
            "X-MBX-APIKEY" => $this->apiKey
        ])->asForm()->post($url, [
            'symbol'      => $symbol,
            'side'        => 'BUY',
            'type'        => 'LIMIT',
            'timeInForce' => 'GTC',
            'price'       => $preco,
            'quantity'    => $quantidade,
            'timestamp'   => $timestamp,
            'signature'   => $signature
        ])->json();
    }


    // ============================================================
    // ORDEM LIMIT SELL
    // ============================================================

    public function sellLimit(float $preco, float $quantidade)
    {
        $symbol = "BTCBRL";
        $timestamp = now()->getTimestampMs();

        // Formatar corretamente
        $preco = number_format($preco, 2, '.', '');
        $quantidade = number_format($quantidade, 5, '.', '');

        $params = http_build_query([
            'symbol'      => $symbol,
            'side'        => 'SELL',
            'type'        => 'LIMIT',
            'timeInForce' => 'GTC',
            'price'       => $preco,
            'quantity'    => $quantidade,
            'timestamp'   => $timestamp
        ]);

        $signature = $this->assinar($params);

        // Enviar no corpo, não na URL
        $url = $this->baseUrl . "/api/v3/order";

        return Http::withHeaders([
            "X-MBX-APIKEY" => $this->apiKey
        ])->asForm()->post($url, [
            'symbol'      => $symbol,
            'side'        => 'SELL',
            'type'        => 'LIMIT',
            'timeInForce' => 'GTC',
            'price'       => $preco,
            'quantity'    => $quantidade,
            'timestamp'   => $timestamp,
            'signature'   => $signature
        ])->json();
    }


    // ============================================================
    // CANCELAR ORDEM
    // ============================================================

    public function cancelarOrdem(string $symbol, string $orderId)
    {
        $timestamp = now()->getTimestampMs();

        $params = http_build_query([
            'symbol'    => $symbol,
            'orderId'   => $orderId,
            'timestamp' => $timestamp
        ]);

        $signature = $this->assinar($params);

        return $this->enviar(
            "/api/v3/order",
            "{$params}&signature={$signature}",
            "DELETE"
        );
    }

    // ============================================================
    // CONSULTAR ORDEM
    // ============================================================

    public function getOrder(string $symbol, string $orderId)
    {
        $timestamp = now()->getTimestampMs();

        $params = http_build_query([
            'symbol'    => $symbol,
            'orderId'   => $orderId,
            'timestamp' => $timestamp
        ]);

        $signature = $this->assinar($params);

        return $this->enviar(
            "/api/v3/order",
            "{$params}&signature={$signature}",
            "GET"
        );
    }

    // ============================================================
    // LISTAR ORDENS ABERTAS
    // ============================================================

    public function getOpenOrders(string $symbol = "BTCBRL")
    {
        $timestamp = now()->getTimestampMs();

        $params = http_build_query([
            'symbol'    => $symbol,
            'timestamp' => $timestamp
        ]);

        $signature = $this->assinar($params);

        return $this->enviar(
            "/api/v3/openOrders",
            "{$params}&signature={$signature}",
            "GET"
        );
    }

    // ============================================================
    // SALDOS DA CONTA
    // ============================================================

    public function getSaldos()
    {
        $timestamp = now()->getTimestampMs();

        $params = http_build_query([
            'timestamp' => $timestamp
        ]);

        $signature = $this->assinar($params);

        return $this->enviar(
            "/api/v3/account",
            "{$params}&signature={$signature}",
            "GET"
        );
    }

    // ============================================================
    // PREÇO ATUAL DO BTC
    // ============================================================

    public function getPrecoBTC()
    {
        $url = $this->baseUrl . "/api/v3/ticker/price?symbol=BTCBRL";

        $response = Http::get($url);

        return (float) $response->json()['price'];
    }
}
