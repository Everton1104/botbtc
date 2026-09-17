<?php

namespace App\Services;

use App\Models\FcmToken;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envio de notificações push via Firebase Cloud Messaging (FCM), API HTTP v1.
 *
 * Como o Firebase confia no Laravel:
 *   1. No console do Firebase geramos uma "conta de serviço" com chave JSON
 *      (arquivo guardado no servidor, FORA do git: storage/app/firebase-*.json).
 *   2. Esta classe assina um JWT RS256 com a chave privada da conta e troca
 *      por um token de acesso OAuth no Google (válido ~1h — cacheado 50min).
 *   3. Com o token de acesso, chama POST /v1/projects/{id}/messages:send
 *      uma vez por celular cadastrado em fcm_tokens.
 *
 * Regra de ouro: notificação é APELIDO — se o Firebase estiver lento, caiu
 * ou não estiver configurado, nada pode quebrar o ciclo do bot nem o saque.
 * Por isso todo caminho termina em try/catch + Log::warning.
 */
class FcmService
{
    /** Prioridade alta: acorda o aparelho mesmo em Doze (trade não pode esperar). */
    private const CANAL = 'botbtc_aviso';

    /**
     * Envia uma notificação para TODOS os celulares cadastrados.
     * (App de uso pessoal: na prática há um aparelho — o do Everton.)
     *
     * @return bool true se ao menos um envio foi aceito pelo Firebase
     */
    public static function enviar(string $titulo, string $corpo, array $dados = []): bool
    {
        try {
            $tokens = FcmToken::pluck('token');
            if ($tokens->isEmpty()) {
                return false; // ninguém instalhou o app ainda — silêncio
            }

            $accessToken = self::tokenDeAcesso();
            if ($accessToken === null) {
                return false; // Firebase não configurado neste servidor — segue sem push
            }

            $projectId = self::credenciais()['project_id'];
            $algumOk = false;

            foreach ($tokens as $token) {
                // Monta a mensagem. O campo 'data' SÓ entra quando tem conteúdo:
                // em PHP, [] vazio vira LISTA ([] no JSON), e o Firebase exige
                // MAPA ({}) — foi um HTTP 400 "Cannot bind a list to map" que
                // ensinou isto do jeito difícil.
                $mensagem = [
                    'token'        => $token,
                    'notification' => ['title' => $titulo, 'body' => $corpo],
                    // channel_id aponta pro canal criado no app (cor/IMPORTANCE_HIGH);
                    // color pinta o círculo do ícone na notificação.
                    'android' => [
                        'priority'     => 'high',
                        'notification' => ['channel_id' => self::CANAL, 'color' => '#f0b90b'],
                    ],
                ];
                if ($dados !== []) {
                    $mensagem['data'] = $dados; // valores SEMPRE string (exigência do FCM)
                }

                $resp = Http::timeout(5)->withToken($accessToken)->post(
                    "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                    ['message' => $mensagem],
                );

                // 404/410 = token não existe mais no Google (app desinstalado,
                // dados limpos). Limpar a row evita pagar envio pra defunto.
                if ($resp->status() === 404 || $resp->status() === 410) {
                    FcmToken::where('token', $token)->delete();
                } elseif ($resp->failed()) {
                    Log::warning("FcmService: envio falhou (HTTP {$resp->status()}): {$resp->body()}");
                } else {
                    $algumOk = true;
                }
            }

            return $algumOk;
        } catch (\Throwable $e) {
            Log::warning('FcmService: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Push de ORDEM executada — UM por ordem, não um por fill. O BotExecutor
     * espera a ordem fechar (FILLED, ou cancelada com o que executou) e chama
     * aqui já com a soma das frações: qty total, valor total em BRL e preço
     * médio ponderado (valor ÷ qty).
     *
     * @param string $lado      BUY / SELL (todos os fills de uma ordem têm o mesmo)
     * @param float  $qty       quantidade total executada (moeda base)
     * @param float  $quote     valor total executado em BRL
     * @param string $symbol    par da Binance (ex.: BTCBRL)
     * @param int    $execucoes quantas frações a ordem levou até fechar
     * @param bool   $cancelada true = ordem cancelada antes de executar inteira
     */
    public static function notificarOrdemExecutada(
        string $lado,
        float $qty,
        float $quote,
        string $symbol,
        int $execucoes,
        bool $cancelada = false,
    ): void {
        $compra = $lado === 'BUY';
        // BTCBRL → BTC: no corpo da notificação o par completo só polui.
        $base   = str_ends_with($symbol, 'BRL') ? substr($symbol, 0, -3) : $symbol;
        $qtyFmt = rtrim(rtrim(number_format($qty, 8, ',', '.'), '0'), ',');
        $media  = $qty > 0 ? $quote / $qty : 0.0;

        $titulo = $compra ? '🟢 Compra executada' : '🔴 Venda executada';
        if ($cancelada) {
            $titulo = $compra ? '🟠 Compra parcial (cancelada)' : '🟠 Venda parcial (cancelada)';
        }

        $corpo = "{$qtyFmt} {$base} por R$ " . number_format($quote, 2, ',', '.')
            . ' · média R$ ' . number_format($media, 2, ',', '.');
        if ($execucoes > 1) {
            $corpo .= " · {$execucoes} execuções";
        }

        self::enviar($titulo, $corpo, ['tipo' => 'trade', 'lado' => $lado]);
    }

    /** Push de saque solicitado — espelha o WhatsApp que o admin já recebe. */
    public static function notificarSaque(float $valorBruto, string $nome): void
    {
        self::enviar(
            '💸 Saque solicitado',
            'R$ ' . number_format($valorBruto, 2, ',', '.') . " por {$nome} — aguardando sua aprovação",
            ['tipo' => 'saque'],
        );
    }

    /**
     * Push de depósito confirmado — espelha o WhatsApp btc_deposito.
     * Existe pra que o WhatsApp possa ser desligado sem perder o aviso.
     */
    public static function notificarDeposito(float $valor, string $nome): void
    {
        self::enviar(
            '💰 Depósito confirmado',
            'R$ ' . number_format($valor, 2, ',', '.') . " por {$nome}",
            ['tipo' => 'deposito'],
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // Autenticação com o Google (OAuth2 via JWT assinado com a conta de serviço)
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Lê o JSON da conta de serviço (uma vez por request; o arquivo é pequeno).
     * Retorna null se não existir — Firebase ainda não configurado.
     */
    private static function credenciais(): ?array
    {
        $caminho = config('services.fcm.credentials');

        if (! $caminho || ! is_file($caminho)) {
            return null;
        }

        return json_decode(file_get_contents($caminho), true) ?: null;
    }

    /**
     * Token de acesso OAuth pro escopo do FCM, cacheado 50 min (o Google dá 1h).
     * Sem cache, cada trade geraria uma ida extra ao Google.
     */
    private static function tokenDeAcesso(): ?string
    {
        $credenciais = self::credenciais();
        if ($credenciais === null) {
            return null;
        }

        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () use ($credenciais) {
            // JWT RS256: assinado com a private_key da conta de serviço.
            $agora = time();
            $jwt = JWT::encode([
                'iss'   => $credenciais['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $agora,
                'exp'   => $agora + 3600,
            ], $credenciais['private_key'], 'RS256');

            // Troca o JWT pelo token de acesso (fluxo "jwt-bearer" do OAuth2).
            $resp = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if ($resp->failed()) {
                Log::warning('FcmService: falha ao obter token de acesso: ' . $resp->body());
                return null;
            }

            return $resp->json('access_token');
        });
    }
}
