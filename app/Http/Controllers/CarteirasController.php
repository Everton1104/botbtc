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

    /** Saldos, UTXOs e histórico de todas as carteiras do usuário (mempool ao vivo) */
    public function dados(Request $req)
    {
        $carteiras = WatchWallet::where('user_id', $req->user()->id)
            ->orderByDesc('id')->get();

        return response()->json($carteiras->map(function ($w) {
            return $this->situacao($w);
        })->values());
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

    private function situacao(WatchWallet $w): array
    {
        $receber = collect($w->enderecos['receber'] ?? []);
        $troco   = collect($w->enderecos['troco'] ?? []);

        $todas = $receber->map(fn ($e) => [$e, 'receber'])
            ->merge($troco->map(fn ($e) => [$e, 'troco']));

        // ── 1ª leva, TODA em paralelo (pool): estatísticas de cada endereço ──
        $resps = [];
        foreach (array_chunk($todas->all(), 25) as $lote) {
            $resps += Http::pool(function ($pool) use ($lote) {
                foreach ($lote as [$e]) {
                    $pool->as($e['endereco'])->timeout(10)->get(self::MEMPOOL . "/address/{$e['endereco']}");
                }
            });
        }

        $saldo = 0;
        $pendente = 0;
        $comMovimento = [];
        foreach ($todas as [$e, $cadeia]) {
            $r = $resps[$e['endereco']] ?? null;
            if (!$r || method_exists($r, 'ok') && !$r->ok()) continue;
            $info = $r->json();
            if (!is_array($info)) continue;

            $stats = $info['address']['chain_stats'] ?? [];
            $memp = $info['address']['mempool_stats'] ?? [];
            $saldo += (int) ($stats['funded_txo_sum'] ?? 0) - (int) ($stats['spent_txo_sum'] ?? 0);
            $pendente += (int) ($memp['funded_txo_sum'] ?? 0) - (int) ($memp['spent_txo_sum'] ?? 0);
            if ((int) ($stats['tx_count'] ?? 0) > 0) $comMovimento[] = [$e, $cadeia];
        }

        // altura atual da chain — pra contar confirmações
        try { $tip = (int) Http::timeout(8)->get(self::MEMPOOL . '/blocks/tip/height')->body(); }
        catch (\Throwable) { $tip = 0; }

        // ── 2ª leva, em paralelo: UTXO + histórico SÓ dos endereços com movimento ──
        $utxos = [];
        $txs   = [];
        $vistos = [];
        foreach (array_chunk($comMovimento, 25) as $lote) {
            $resps2 = Http::pool(function ($pool) use ($lote) {
                foreach ($lote as [$e]) {
                    $pool->as('u' . $e['endereco'])->timeout(10)->get(self::MEMPOOL . "/address/{$e['endereco']}/utxo");
                    $pool->as('t' . $e['endereco'])->timeout(10)->get(self::MEMPOOL . "/address/{$e['endereco']}/txs");
                }
            });

            foreach ($lote as [$e, $cadeia]) {
                $ru = $resps2['u' . $e['endereco']] ?? null;
                if ($ru && $ru->ok()) {
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

                $rt = $resps2['t' . $e['endereco']] ?? null;
                if ($rt && $rt->ok()) {
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
                            'delta'  => $entrada - $saida,
                            'conf'   => $conf,
                            'confirmacoes' => ($conf && $tip && $alt) ? $tip - $alt + 1 : 0,
                        ];
                    }
                }
            }
        }

        usort($txs, fn ($a, $b) => strcmp($b['quando'], $a['quando']));

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
