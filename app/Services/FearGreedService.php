<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Índice de Medo e Ganância (Fear & Greed) — Alternative.me.
 * Fonte pública (sem auth). Valor 0-100: 0 = medo extremo, 100 = ganância extrema.
 * Cache de 1h (a API atualiza ~a cada hora). Usado pelo bot (analisarTendencia)
 * e pelo painel.
 */
class FearGreedService
{
    private const API = 'https://api.alternative.me/fng';
    private const TTL = 3600;

    /**
     * @return array{value:int, classification:string, timestamp:?int}
     */
    public function atual(): array
    {
        return Cache::remember('fng:atual', self::TTL, function () {
            try {
                $resp = Http::timeout(10)->connectTimeout(5)->get(self::API . '/?limit=1');
                $data = $resp->json()['data'][0] ?? null;
                if ($data && isset($data['value'])) {
                    return [
                        'value'          => (int) $data['value'],
                        'classification' => (string) ($data['value_classification'] ?? ''),
                        'timestamp'      => isset($data['timestamp']) ? (int) $data['timestamp'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('FearGreed: falha ao buscar índice — ' . $e->getMessage());
            }
            return ['value' => 50, 'classification' => 'Neutral', 'timestamp' => null];
        });
    }
}
