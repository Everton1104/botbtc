<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SaqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saques no app mobile — a MESMA regra do site (SaqueService), só muda a
 * autenticação: token Sanctum no header em vez de sessão com cookie.
 *
 * A tela de saques do app é montada com UMA chamada (GET /api/saque/tela),
 * no mesmo espírito do /api/painel: menos ida e vindas, um só pull pra
 * atualizar tudo. As ações (solicitar, cancelar, confirmar) são chamadas
 * próprias porque mexem no dinheiro.
 */
class SaqueApiController extends Controller
{
    public function __construct(private SaqueService $saques) {}

    /**
     * GET /api/saque/tela — tudo que a tela de saques precisa:
     * disponível, pendentes e histórico do usuário; e, só para o admin
     * (id 1), as aprovações pendentes de todos e a pausa do bot.
     */
    public function tela(Request $request): JsonResponse
    {
        $user = $request->user();

        $resposta = array_merge(
            ['eh_admin' => $user->id === 1],
            $this->saques->meus($user->id),
        );

        if ($user->id === 1) {
            $resposta['aprovacoes'] = $this->saques->pendentes();
            $resposta['pausa']      = $this->saques->statusPausa();
        }

        return response()->json($resposta);
    }

    /** POST /api/saque/solicitar — body { "valor": 100 } (vazio = sacar tudo). */
    public function solicitar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'valor' => ['nullable', 'numeric', 'min:0'],
        ], [
            'valor.numeric' => 'O valor deve ser um número.',
            'valor.min'     => 'O valor não pode ser negativo.',
        ]);

        $r = $this->saques->solicitar($request->user()->id, (float) ($dados['valor'] ?? 0));

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }

    /** DELETE /api/saque/cancelar/{id} — cancela um pendente SEU. */
    public function cancelar(Request $request, int $id): JsonResponse
    {
        $r = $this->saques->cancelar($request->user()->id, $id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }

    /**
     * POST /api/saque/confirmar/{id} — SÓ ADMIN. Igualzinho ao site: pode
     * vender BTC a mercado, cancela as ordens abertas e pausa o bot 3 min.
     */
    public function confirmar(Request $request, int $id): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->saques->confirmar($id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 500));
    }

    /** POST /api/saque/retomar — SÓ ADMIN: libera a pausa pós-confirmação. */
    public function retomar(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        return response()->json(['mensagem' => $this->saques->retomarBot()['mensagem']]);
    }
}
