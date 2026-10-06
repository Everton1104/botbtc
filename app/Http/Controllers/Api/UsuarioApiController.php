<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsuarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contas de usuário no app mobile — casca fina sobre o UsuarioService
 * (a regra dos guards vive lá). Hoje: remoção de contas sem cotas.
 */
class UsuarioApiController extends Controller
{
    public function __construct(private UsuarioService $usuarios) {}

    /** POST /api/usuario/{id}/remover — SÓ ADMIN. */
    public function remover(Request $request, int $id): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->usuarios->remover($id, (int) $request->user()->id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }
}
