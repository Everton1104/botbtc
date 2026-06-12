<?php

namespace App\Http\Controllers;

use App\Models\WhatsappLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class WhatsappController extends Controller
{
    private const ADMIN = '5511997646569';

    // ── Webhook ──────────────────────────────────────────────────────────────

    public function verificarToken(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token');
        $challenge   = $request['hub_challenge'];
        $token       = $request['hub_verify_token'];

        if ($token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Token de verificação inválido', 403);
    }

    public function getMsgs(Request $request)
    {
        if (!$this->assinaturaValida($request)) {
            \Log::channel('single')->warning('[WA-WEBHOOK] assinatura X-Hub-Signature-256 inválida');
            return response()->json(['error' => 'invalid signature'], 403);
        }

        $business_phone_number_id = $request['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? 0;
        $msg                      = $request['entry'][0]['changes'][0]['value']['messages'][0] ?? '';
        $status                   = $request['entry'][0]['changes'][0]['value']['statuses'] ?? null;
        $number                   = $msg['from'] ?? 0;
        $msgTxt                   = $msg['text']['body'] ?? '';
        $msgSimNao                = $msg['interactive']['button_reply']['id'] ?? null;
        $msgLista                 = $msg['interactive']['list_reply']['id'] ?? null;

        try {
            // Atualiza status de entrega das mensagens enviadas
            if ($status) {
                return response()->json([], 200);
            }

            // ── Implemente aqui a lógica de negócio do botbtc ──
            // Exemplo:
            // $this->enviarMsg($business_phone_number_id, $number, 'Olá! Sou o bot BTC.');

            return response()->json([], 200);
        } catch (\Throwable $th) {
            $this->enviarMsg($business_phone_number_id, $number, 'Erro interno. Tente novamente.');
            return response()->json([], 200);
        }
    }

    // Confirma que o POST veio mesmo da Meta: o corpo bruto é assinado por ela
    // (HMAC-SHA256 com o App Secret) no header X-Hub-Signature-256. Sem isso,
    // qualquer um que descubra a URL poderia injetar eventos falsos.
    // Se WHATSAPP_APP_SECRET não estiver configurado, não bloqueia (fail-open),
    // para não derrubar ambientes ainda não configurados.
    private function assinaturaValida(Request $request): bool
    {
        $appSecret = config('services.whatsapp.app_secret');
        if (!$appSecret) {
            return true;
        }

        $assinatura = (string) $request->header('X-Hub-Signature-256', '');
        if (!str_starts_with($assinatura, 'sha256=')) {
            return false;
        }

        $esperado = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
        return hash_equals($esperado, $assinatura);
    }

    // ── Envio de mensagens ────────────────────────────────────────────────────

    // Cliente Graph centralizado: monta o POST para /messages com o token e o
    // phone id, evitando repetir new Client() + headers em cada método de envio.
    private static function postGraph($phoneId, array $payload): \Psr\Http\Message\ResponseInterface
    {
        $client = new \GuzzleHttp\Client();
        return $client->request('POST', "https://graph.facebook.com/v25.0/{$phoneId}/messages", [
            'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            'json'    => $payload,
        ]);
    }

    public static function enviarMsg($business_phone_number_id, $numero, $msg)
    {
        try {
            $response = self::postGraph($business_phone_number_id, [
                'messaging_product' => 'whatsapp',
                'to'                => $numero,
                'type'              => 'text',
                'text'              => ['body' => $msg],
            ]);

            $body      = json_decode($response->getBody(), true);
            $messageId = $body['messages'][0]['id'] ?? null;

            self::log($numero, Auth::id(), $msg, null, $messageId);

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $err = json_decode($e->getResponse()->getBody(), true);
            return ['erro' => 1, 'msg' => $err['error']['message'] ?? 'Erro desconhecido'];
        } catch (\Exception $e) {
            return ['erro' => 1, 'msg' => $e->getMessage()];
        }
    }

    public static function enviarMsgSimNao($business_phone_number_id, $numero, $msg, $id = 0, $title1 = 'Sim', $title2 = 'Não', $unico = false)
    {
        $btns = [
            ['type' => 'reply', 'reply' => ['id' => 'sim' . $id, 'title' => $title1]],
            ['type' => 'reply', 'reply' => ['id' => 'nao' . $id, 'title' => $title2]],
        ];

        if ($unico) {
            $btns = [['type' => 'reply', 'reply' => ['id' => 'sim' . $id, 'title' => $title1]]];
        }

        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $msg],
                'action' => ['buttons' => $btns],
            ],
        ]);

        self::log($numero, Auth::id(), $msg, null, $business_phone_number_id);
    }

    public static function enviarMsgSimNaoCancel($business_phone_number_id, $numero, $msg, $id = 0, $title1 = 'Sim', $title2 = 'Não', $title3 = 'Cancelar')
    {
        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $msg],
                'action' => [
                    'buttons' => [
                        ['type' => 'reply', 'reply' => ['id' => 'sim' . $id,    'title' => $title1]],
                        ['type' => 'reply', 'reply' => ['id' => 'nao' . $id,    'title' => $title2]],
                        ['type' => 'reply', 'reply' => ['id' => 'cancel' . $id, 'title' => $title3]],
                    ],
                ],
            ],
        ]);

        self::log($numero, Auth::id(), $msg, null, $business_phone_number_id);
    }

    public static function enviarMsgLista($business_phone_number_id, $numero, $msg, $lista = [], $secoes = null)
    {
        $sections = $secoes ?? [['rows' => $lista]];

        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'body'   => ['text' => $msg],
                'action' => [
                    'button'   => 'Selecione uma opção:',
                    'sections' => $sections,
                ],
            ],
        ]);

        self::log($numero, Auth::id(), $msg, null, $business_phone_number_id);
    }

    public static function enviarImg($business_phone_number_id, $numero, $link, $desc = 'imagem')
    {
        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'image',
            'image'             => ['link' => $link, 'caption' => $desc],
        ]);

        self::log($numero, Auth::id(), $desc, null, $business_phone_number_id);
    }

    public static function enviarAudio($business_phone_number_id, $numero, $link)
    {
        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'audio',
            'audio'             => ['link' => $link],
        ]);

        self::log($numero, Auth::id(), 'audio', null, $business_phone_number_id);
    }

    public static function enviarAudioId($business_phone_number_id, $numero, $mediaId)
    {
        try {
            self::postGraph($business_phone_number_id, [
                'messaging_product' => 'whatsapp',
                'to'                => $numero,
                'type'              => 'audio',
                'audio'             => ['id' => $mediaId],
            ]);

            self::log($numero, Auth::id(), 'audio', null, $business_phone_number_id);
            return [];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $err = json_decode($e->getResponse()->getBody(), true);
            return ['erro' => 1, 'msg' => $err['error']['message'] ?? 'Erro ao enviar áudio'];
        } catch (\Exception $e) {
            return ['erro' => 1, 'msg' => $e->getMessage()];
        }
    }

    public static function enviarVideo($business_phone_number_id, $numero, $link, $desc = 'video')
    {
        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'video',
            'video'             => ['link' => $link, 'caption' => $desc],
        ]);

        self::log($numero, Auth::id(), $desc, null, $business_phone_number_id);
    }

    public static function enviarDoc($business_phone_number_id, $numero, $link, $desc = 'arquivo')
    {
        self::postGraph($business_phone_number_id, [
            'messaging_product' => 'whatsapp',
            'to'                => $numero,
            'type'              => 'document',
            'document'          => ['link' => $link, 'caption' => $desc],
        ]);

        self::log($numero, Auth::id(), $desc, null, $business_phone_number_id);
    }

    public static function enviarCodigoVerificacao(\App\Models\User $user): void
    {
        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->whatsapp_code             = $codigo;
        $user->whatsapp_code_expires_at  = now()->addMinutes(10);
        $user->save();

        try {
            $response = self::postGraph(config('services.whatsapp.phone_id'), [
                'messaging_product' => 'whatsapp',
                'to'                => $user->whatsapp,
                'type'              => 'template',
                'template'          => [
                    'name'       => 'user_code',
                    'language'   => ['code' => 'pt_BR'],
                    'components' => [
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $codigo],
                            ],
                        ],
                        [
                            'type'       => 'button',
                            'sub_type'   => 'url',
                            'index'      => 0,
                            'parameters' => [
                                ['type' => 'text', 'text' => $codigo],
                            ],
                        ],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            \Illuminate\Support\Facades\Log::info("WhatsApp user_code enviado", ['para' => $user->whatsapp, 'resp' => $body]);
            self::log($user->whatsapp, $user->id, "Código de verificação enviado", null, config('services.whatsapp.phone_id'));
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $erro = json_decode($e->getResponse()->getBody(), true);
            \Illuminate\Support\Facades\Log::error("WhatsApp user_code ERRO", ['para' => $user->whatsapp, 'erro' => $erro]);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::error("WhatsApp user_code ERRO", ['para' => $user->whatsapp, 'msg' => $th->getMessage()]);
        }
    }

    public static function notificarSaque(float $valor, string $nomeUsuario)
    {
        self::enviarTemplateAdmin('btc_saque', [
            ['type' => 'text', 'text' => number_format($valor, 2, ',', '.')],
            ['type' => 'text', 'text' => $nomeUsuario],
        ]);
    }

    public static function notificarDeposito(float $valor, string $nomeUsuario)
    {
        self::enviarTemplateAdmin('btc_deposito', [
            ['type' => 'text', 'text' => number_format($valor, 2, ',', '.')],
            ['type' => 'text', 'text' => $nomeUsuario],
        ]);
    }

    private static function enviarTemplateAdmin(string $template, array $parametros)
    {
        try {
            self::postGraph(config('services.whatsapp.phone_id'), [
                'messaging_product' => 'whatsapp',
                'to'                => self::ADMIN,
                'type'              => 'template',
                'template'          => [
                    'name'       => $template,
                    'language'   => ['code' => 'pt_BR'],
                    'components' => [
                        ['type' => 'body', 'parameters' => $parametros],
                    ],
                ],
            ]);

            self::log(self::ADMIN, null, "Template {$template}", null, config('services.whatsapp.phone_id'));
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::error("WhatsApp {$template}: " . $th->getMessage());
        }
    }

    public static function enviarModelo($business_phone_number_id, $numero, $templateName, $language = 'pt_BR')
    {
        try {
            $response = self::postGraph($business_phone_number_id, [
                'messaging_product' => 'whatsapp',
                'to'                => $numero,
                'type'              => 'template',
                'template'          => [
                    'name'     => $templateName,
                    'language' => ['code' => $language],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['error'])) {
                return ['erro' => 1, 'msg' => $body['error']['message'] ?? 'Erro na API do WhatsApp'];
            }

            self::log($numero, Auth::id(), $templateName, null, $business_phone_number_id);
            return ['msg' => 'Modelo enviado com sucesso'];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $err = json_decode($e->getResponse()->getBody(), true);
            return ['erro' => 1, 'msg' => $err['error']['message'] ?? 'Erro desconhecido'];
        } catch (\Exception $e) {
            return ['erro' => 1, 'msg' => $e->getMessage()];
        }
    }

    // ── Upload de mídia ───────────────────────────────────────────────────────

    public static function uploadMidia($business_phone_number_id, $filePath, $mimeType)
    {
        try {
            $client   = new \GuzzleHttp\Client();
            $response = $client->request('POST', "https://graph.facebook.com/v25.0/{$business_phone_number_id}/media", [
                'headers'    => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
                'multipart'  => [
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                    ['name' => 'file',              'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
                ],
            ]);

            $body = json_decode($response->getBody(), true);
            return ['id' => $body['id'] ?? null];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $err = json_decode($e->getResponse()->getBody(), true);
            return ['erro' => 1, 'msg' => $err['error']['message'] ?? 'Erro no upload de mídia'];
        } catch (\Exception $e) {
            return ['erro' => 1, 'msg' => $e->getMessage()];
        }
    }

    // ── Download de mídias recebidas ──────────────────────────────────────────

    public static function getImage($msg)
    {
        try {
            $imgId    = $msg['image']['id'];
            $imgMime  = explode('/', $msg['image']['mime_type'])[1];
            $filename = "{$imgId}.{$imgMime}";

            $client   = new \GuzzleHttp\Client();
            $response = $client->request('GET', "https://graph.facebook.com/v25.0/{$imgId}", [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            $mediaData = json_decode($response->getBody(), true);
            $imagem    = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            Storage::disk('public')->put('whatsapp/' . $filename, $imagem->getBody());
            return $filename;
        } catch (\Throwable $th) {}
    }

    public static function getSticker($msg)
    {
        try {
            $imgId    = $msg['sticker']['id'];
            $imgMime  = explode('/', $msg['sticker']['mime_type'])[1];
            $filename = "{$imgId}.{$imgMime}";

            $client   = new \GuzzleHttp\Client();
            $response = $client->request('GET', "https://graph.facebook.com/v25.0/{$imgId}", [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            $mediaData = json_decode($response->getBody(), true);
            $imagem    = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            Storage::disk('public')->put('whatsapp/' . $filename, $imagem->getBody());
            return $filename;
        } catch (\Throwable $th) {}
    }

    public static function getAudio($msg)
    {
        try {
            $audId    = $msg['audio']['id'];
            $audMime  = explode('/', $msg['audio']['mime_type'])[1];
            $filename = "{$audId}.{$audMime}";

            $client   = new \GuzzleHttp\Client();
            $response = $client->request('GET', "https://graph.facebook.com/v25.0/{$audId}", [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            $mediaData = json_decode($response->getBody(), true);
            $audio     = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            Storage::disk('public')->put('whatsapp/' . $filename, $audio->getBody());
            return $filename;
        } catch (\Throwable $th) {}
    }

    public static function getVideo($msg)
    {
        try {
            $vidId    = $msg['video']['id'];
            $vidMime  = explode('/', $msg['video']['mime_type'])[1];
            $filename = "{$vidId}.{$vidMime}";

            $client   = new \GuzzleHttp\Client();
            $response = $client->request('GET', "https://graph.facebook.com/v25.0/{$vidId}", [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            $mediaData = json_decode($response->getBody(), true);
            $video     = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            Storage::disk('public')->put('whatsapp/' . $filename, $video->getBody());
            return $filename;
        } catch (\Throwable $th) {}
    }

    public static function getDocument($msg)
    {
        try {
            $docId    = $msg['document']['id'];
            $docMime  = explode('/', $msg['document']['mime_type'])[1];
            $filename = "{$docId}.{$docMime}";

            $client   = new \GuzzleHttp\Client();
            $response = $client->request('GET', "https://graph.facebook.com/v25.0/{$docId}", [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            $mediaData = json_decode($response->getBody(), true);
            $document  = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . config('services.whatsapp.graph_token')],
            ]);

            Storage::disk('public')->put('whatsapp/' . $filename, $document->getBody());
            return $filename;
        } catch (\Throwable $th) {}
    }

    // ── Log ───────────────────────────────────────────────────────────────────

    public static function log($number = null, $user_id = null, $msg = null, $dep_id = null, $business_phone_number_id = null)
    {
        WhatsappLog::create([
            'number'                  => $number,
            'user_id'                 => $user_id,
            'msg'                     => $msg,
            'dep_id'                  => $dep_id,
            'business_phone_number_id' => $business_phone_number_id,
        ]);
    }
}
