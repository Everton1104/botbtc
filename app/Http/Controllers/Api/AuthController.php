<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
