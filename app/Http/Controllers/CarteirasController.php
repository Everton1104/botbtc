<?php

namespace App\Http\Controllers;

use App\Models\WatchWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Aba "Carteiras" — watch-only (zpub) vinculadas ao usuário.
 *
 * O servidor NÃO faz criptografia: as derivações de endereço acontecem no
 * navegador (a zpub é pública) e chegam prontas no cadastro. Aqui é só
 * guardar, consultar o mempool.space e retransmitir transações assinadas —
 * quem assina é o arquivo offline, nunca este servidor.
 */
class CarteirasController extends Controller
{
    private const MEMPOOL = 'https://mempool.space/api';

    public function index()
    {
        return view('carteiras');
    }

    /** Cadastra a carteira watch-only (endereços derivados no cliente) */
    public function importar(Request $req)
    {
        $dados = $req->validate([
            'rotulo'    => 'required|string|max:60',
            'zpub'      => ['required', 'string', 'regex:/^zpub[a-km-zA-HJ-NP-Z1-9]{100,110}$/'],
            'enderecos' => 'required|array',
            'enderecos.receber' => 'required|array|min:1|max:200',
            'enderecos.troco'   => 'required|array|min:1|max:50',
        ]);

        $jaTem = WatchWallet::where('user_id', $req->user()->id)
            ->where('zpub', $dados['zpub'])->exists();
        if ($jaTem) {
            return response()->json(['mensagem' => 'Esta zpub já está cadastrada.'], 422);
        }

        $w = WatchWallet::create([
            'user_id'   => $req->user()->id,
            'rotulo'    => $dados['rotulo'],
            'zpub'      => $dados['zpub'],
            'enderecos' => $dados['enderecos'],
        ]);

        return response()->json(['id' => $w->id, 'rotulo' => $w->rotulo]);
    }

    public function destruir(Request $req, int $id)
    {
        WatchWallet::where('user_id', $req->user()->id)->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** Estende a lista de endereços vigiados (derivados no cliente, via zpub) */
    public function atualizarEnderecos(Request $req, int $id)
    {
        $w = WatchWallet::where('user_id', $req->user()->id)->findOrFail($id);

        $dados = $req->validate([
            'receber'            => 'required|array|min:1|max:200',
            'receber.*.i'        => 'required|integer|min:0',
            'receber.*.endereco' => ['required', 'string', 'regex:/^bc1q[a-z0-9]{30,60}$/'],
        ]);

        $end = $w->enderecos;
        $end['receber'] = array_values($dados['receber']);
        $w->enderecos = $end;
        $w->save();

        return response()->json(['ok' => true, 'total' => count($end['receber'])]);
    }

    /**
     * Saldos, UTXOs e histórico — do CACHE (instantâneo). O scheduler
     * (carteiras:atualizar) mantém o snapshot fresco em segundo plano;
     * ?refresh=1 força a atualização antes de responder.
     */
    public function dados(Request $req)
    {
        $carteiras = WatchWallet::where('user_id', $req->user()->id)
            ->orderByDesc('id')->get();

        if ($req->boolean('refresh')) {
            foreach ($carteiras as $w) $this->atualizarUma($w);
        }

        return response()->json($carteiras->map(function ($w) {
            $estado = $w->estado;
            if (!$estado) {
                // sem cache ainda: gera agora (primeira vez) e guarda
                $estado = $this->atualizarUma($w);
            }
            $estado['idade_seg'] = $w->atualizado_em
                ? abs(now()->diffInSeconds($w->atualizado_em))
                : null;
            return $estado;
        })->values());
    }

    /** Consulta o mempool e grava o snapshot no banco (usado pelo scheduler). */
    public function atualizarUma(WatchWallet $w): array
    {
        $estado = $this->situacao($w);
        $w->estado = $estado;
        $w->atualizado_em = now();
        $w->save();
        return $estado;
    }

    /** Cotação BTC/BRL ao vivo (Binance — a mesma fonte do bot) */
    public function cotacao()
    {
        try {
            $preco = Http::timeout(8)
                ->get('https://api.binance.com/api/v3/ticker/price?symbol=BTCBRL')
                ->json()['price'] ?? 0;
            return response()->json(['btc_brl' => (float) $preco]);
        } catch (\Throwable) {
            return response()->json(['btc_brl' => 0]);
        }
    }

    /** Taxas atuais do mempool (sat/vB) — pra sugerir e estimar tempo */
    public function taxas()
    {
        $padrao = ['rapida' => 8, 'meiaHora' => 6, 'hora' => 4, 'economica' => 2];
        try {
            $j = Http::timeout(8)->get(self::MEMPOOL . '/v1/fees/recommended')->json();
            return response()->json([
                'rapida'    => (int) ($j['fastestFee']   ?? $padrao['rapida']),
                'meiaHora'  => (int) ($j['halfHourFee']  ?? $padrao['meiaHora']),
                'hora'      => (int) ($j['hourFee']      ?? $padrao['hora']),
                'economica' => (int) ($j['economyFee']   ?? $padrao['economica']),
            ]);
        } catch (\Throwable) {
            return response()->json($padrao);
        }
    }

    /** Transmite uma transação JÁ ASSINADA (hex) pra mempool */    public function enviar(Request $req)
    {
        $hex = trim((string) $req->input('hex', ''));
        if (!preg_match('/^[0-9a-fA-F]+$/', $hex) || strlen($hex) < 100) {
            return response()->json(['mensagem' => 'Hex inválido — cole a transação assinada completa.'], 422);
        }

        try {
            $r = Http::asText()
                ->withHeaders(['Content-Type' => 'text/plain'])
                ->timeout(20)
                ->post(self::MEMPOOL . '/tx', strtolower($hex));
        } catch (\Throwable $e) {
            return response()->json(['mensagem' => 'Mempool indisponível agora: ' . $e->getMessage()], 502);
        }

        if ($r->status() === 200) {
            return response()->json(['txid' => trim($r->body())]);
        }

        return response()->json(['mensagem' => 'Mempool recusou: ' . mb_substr(trim($r->body()), 0, 300)], $r->status());
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * GET com retry: o mempool.space responde 429 (rate limit) quando o
     * painel dispara várias consultas — 3 tentativas com backoff antes de
     * desistir do endereço.
     */
    private function getComRetry(string $url): ?\Illuminate\Http\Client\Response
    {
        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            try {
                $r = Http::timeout(10)->get($url);
                if ($r->ok()) return $r;
                if ($r->status() === 429) {
                    usleep((1 + $tentativa) * 700000); // 0,7s / 1,4s / 2,1s
                    continue;
                }
                return $r;
            } catch (\Throwable) {
                usleep(500000);
            }
        }
        return null;
    }

    private function situacao(WatchWallet $w): array
    {
        $receber = collect($w->enderecos['receber'] ?? []);
        $troco   = collect($w->enderecos['troco'] ?? []);

        $todas = $receber->map(fn ($e) => [$e, 'receber'])
            ->merge($troco->map(fn ($e) => [$e, 'troco']));

        // ── 1ª leva: estatísticas de cada endereço ──
        // Lotes PARALELOS de 5 (rápido) com pausa entre eles; quem der 429
        // refaz individual com backoff — equilibra velocidade × rate limit.
        $saldo = 0;
        $pendente = 0;
        $comMovimento = [];
        foreach (array_chunk($todas->all(), 5) as $lote) {
            $resps = Http::pool(function ($pool) use ($lote) {
                foreach ($lote as [$e]) {
                    $pool->as($e['endereco'])->timeout(10)->get(self::MEMPOOL . "/address/{$e['endereco']}");
                }
            });
            foreach ($lote as [$e, $cadeia]) {
                $r = $resps[$e['endereco']] ?? null;
                // no pool, conexão falha = a entrada vem como ConnectionException
                if (!$r instanceof \Illuminate\Http\Client\Response || !$r->ok()) {
                    $r = $this->getComRetry(self::MEMPOOL . "/address/{$e['endereco']}");
                }
                if (!$r || !$r->ok()) continue;
                $info = $r->json();
                if (!is_array($info)) continue;

                // o mempool devolve chain_stats/mempool_stats na RAIZ do JSON
                $stats = $info['chain_stats'] ?? ($info['address']['chain_stats'] ?? []);
                $memp = $info['mempool_stats'] ?? ($info['address']['mempool_stats'] ?? []);
                $saldo += (int) ($stats['funded_txo_sum'] ?? 0) - (int) ($stats['spent_txo_sum'] ?? 0);
                $pendente += (int) ($memp['funded_txo_sum'] ?? 0) - (int) ($memp['spent_txo_sum'] ?? 0);
                if ((int) ($stats['tx_count'] ?? 0) > 0) $comMovimento[] = [$e, $cadeia];
            }
            usleep(200000);
        }

        // altura atual da chain — pra contar confirmações
        try { $tip = (int) Http::timeout(8)->get(self::MEMPOOL . '/blocks/tip/height')->body(); }
        catch (\Throwable) { $tip = 0; }

        // ── 2ª leva: UTXO + histórico SÓ dos endereços com movimento ──
        $utxos = [];
        $txs   = [];
        $vistos = [];
        foreach ($comMovimento as [$e, $cadeia]) {
            $ru = $this->getComRetry(self::MEMPOOL . "/address/{$e['endereco']}/utxo");
            if ($ru) {
                foreach ((array) $ru->json() as $u) {
                    $utxos[] = [
                        'txid'    => $u['txid'],
                        'vout'    => (int) $u['vout'],
                        'sats'    => (int) $u['value'],
                        'endereco' => $e['endereco'],
                        'i'       => $e['i'],
                        'cadeia'  => $cadeia,
                        'confirmado' => (int) ($u['status']['confirmed'] ?? 0) === 1,
                    ];
                }
            }

            $rt = $this->getComRetry(self::MEMPOOL . "/address/{$e['endereco']}/txs");
            if ($rt) {
                foreach ((array) $rt->json() as $tx) {
                    if (isset($vistos[$tx['txid']])) continue;
                    $vistos[$tx['txid']] = true;
                    $entrada = 0; $saida = 0;
                    foreach ($tx['vin'] ?? [] as $in) {
                        if (($in['prevout']['scriptpubkey_address'] ?? null) === $e['endereco']) $saida += (int) $in['prevout']['value'];
                    }
                    foreach ($tx['vout'] ?? [] as $out) {
                        if (($out['scriptpubkey_address'] ?? null) === $e['endereco']) $entrada += (int) $out['value'];
                    }
                    $conf = (int) ($tx['status']['confirmed'] ?? 0) === 1;
                    $alt = (int) ($tx['status']['block_height'] ?? 0);
                    $txs[] = [
                        'txid'   => $tx['txid'],
                        'quando' => $conf ? date('d/m/Y H:i', (int) $tx['status']['block_time']) : 'na mempool',
                        // timestamp pra ORDENAR — comparar a string "d/m/Y"
                        // quebrava entre meses ("31/10" > "25/11" como texto)
                        'ts'     => $conf ? (int) $tx['status']['block_time'] : PHP_INT_MAX,
                        'delta'  => $entrada - $saida,
                        'conf'   => $conf,
                        'confirmacoes' => ($conf && $tip && $alt) ? $tip - $alt + 1 : 0,
                    ];
                }
            }
        }

        usort($txs, fn ($a, $b) => $b['ts'] <=> $a['ts']);

        return [
            'id'      => $w->id,
            'rotulo'  => $w->rotulo,
            'zpub'    => $w->zpub,
            'receber' => array_values($receber->all()),
            'saldo'   => $saldo,
            'saldo_pendente' => $pendente,
            'utxos'   => $utxos,
            'txs'     => array_slice($txs, 0, 12),
            'n_receber' => $receber->count(),
        ];
    }
}
