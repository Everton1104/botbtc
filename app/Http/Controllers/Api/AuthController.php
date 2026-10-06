<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Autenticação do app mobile (token Sanctum).
 *
 * O site usa sessão (cookie); o app não consegue manter essa sessão de forma
 * prática, então ele recebe um token pessoal que envia no header
 * `Authorization: Bearer <token>` em todas as chamadas.
 */
class AuthController extends Controller
{
    /**
     * POST /api/login — email + senha → token + dados do usuário.
     */
    public function login(Request $request): JsonResponse
    {
        $credenciais = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ], [
            'email.required'    => 'Informe o e-mail.',
            'email.email'       => 'E-mail inválido.',
            'password.required' => 'Informe a senha.',
        ]);

        $user = User::where('email', $credenciais['email'])->first();

        if (! $user || ! Hash::check($credenciais['password'], $user->password)) {
            // Mensagem genérica de propósito: não revelar se o e-mail existe.
            return response()->json(['message' => 'E-mail ou senha incorretos.'], 401);
        }

        $token = $user->createToken($credenciais['device_name'] ?? 'mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->dadosUsuario($user),
        ]);
    }

    /**
     * POST /api/esqueci-senha — dispara o MESMO e-mail de redefinição do site.
     *
     * Reutiliza o Password broker padrão (token em `password_reset_tokens` +
     * link para `/reset-password/{token}` do site): o usuário troca a senha
     * no navegador, pela página que o site já tem, e volta a logar no app.
     */
    public function esqueciSenha(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Informe o e-mail.',
            'email.email'    => 'E-mail inválido.',
        ]);

        $status = null;

        try {
            $status = Password::sendResetLink($dados);
        } catch (TransportException $e) {
            // SMTP fora do ar / recusando o envio: o app mostra a mensagem
            // em vez de um "Server Error" seco. O motivo fica no log.
            Log::error('Falha ao enviar e-mail de reset: '.$e->getMessage());

            return response()->json([
                'message' => 'Não foi possível enviar o e-mail agora. Tente novamente em instantes.',
            ], 503);
        }

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'mensagem' => 'Link enviado! Abra o e-mail, toque no link e cadastre a nova senha — depois entre no app com ela.',
            ]);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'message' => 'Um link já foi enviado há pouco. Aguarde um minuto antes de pedir outro.',
            ], 429);
        }

        // INVALID_USER — mesma revelação do site (validação padrão do broker).
        return response()->json([
            'message' => 'Não encontramos uma conta com esse e-mail.',
        ], 422);
    }

    /**
     * GET /api/me — dados do dono do token (valida se o token ainda é válido).
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->dadosUsuario($request->user()),
        ]);
    }

    /**
     * POST /api/logout — revoga só o token usado na chamada.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /**
     * POST /api/dispositivo-token — o app entrega o token FCM do celular.
     *
     * O token é o "endereço" do aparelho no Firebase: sem ele gravado aqui,
     * o Laravel não tem pra quem mandar push quando o bot operar. Ele muda
     * quando o app é reinstalado ou o Firebase rotaciona — por isso o upsert
     * (updateOrCreate), que insere na primeira vez e atualiza nas demais.
     */
    public function registrarDispositivo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token'      => ['required', 'string', 'max:500'],
            'dispositivo' => ['nullable', 'string', 'max:100'],
        ]);

        FcmToken::updateOrCreate(
            ['token' => $dados['token']],
            [
                'user_id'    => $request->user()->id,
                'dispositivo' => $dados['dispositivo'] ?? 'mobile',
            ],
        );

        return response()->json(['message' => 'Dispositivo registrado.']);
    }

    /**
     * Payload comum do usuário nas respostas da API.
     * `whatsapp_verificado` vem primeiro do que o número em si: o app usa
     * esse flag para decidir se mostra o aviso de verificação pendente.
     */
    private function dadosUsuario(User $user): array
    {
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'whatsapp'            => $user->whatsapp,
            'whatsapp_verificado' => $user->whatsappVerificado(),
        ];
    }
}
