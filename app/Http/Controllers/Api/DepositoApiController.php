<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DepositoService;
use App\Services\SaqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Depósitos PIX no app mobile — a MESMA regra do site. Criar cobrança e
 * consultar status são os próprios métodos do PixController (as rotas
 * /api/deposito/criar e /status apontam pra lá, autenticando por token
 * Sanctum); o que vive aqui é a tela (uma chamada só) e as ações do
 * admin (registrar no bot, estornar) — sempre finas sobre o DepositoService.
 */
class DepositoApiController extends Controller
{
    public function __construct(
        private DepositoService $depositos,
        private SaqueService $saques,
    ) {}

    /**
     * GET /api/deposito/tela — tudo que a tela de depósitos precisa:
     * cobrança pendente (retomada do QR) e histórico do usuário; e, só
     * para o admin (id 1), os depósitos confirmados de todos, a lista de
     * investidores (com cotas) e a pausa do bot (o depósito manual pausa
     * até o admin transferir pra Binance e liberar).
     */
    public function tela(Request $request): JsonResponse
    {
        $user = $request->user();

        $resposta = array_merge(
            ['eh_admin' => $user->id === 1],
            $this->depositos->tela($user->id),
        );

        if ($user->id === 1) {
            $resposta['admin_depositos'] = $this->depositos->adminDepositos();
            $resposta['investidores']     = $this->depositos->investidores();
            $resposta['pausa']            = $this->saques->statusPausa();
        }

        return response()->json($resposta);
    }

    /**
     * POST /api/deposito/registrar/{id} — SÓ ADMIN. Credita as cotas e
     * marca o PIX como registrado numa única transaction (no site são
     * dois passos separados — aqui não há janela de duplo crédito).
     */
    public function registrar(Request $request, int $id): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->depositos->registrarDeposito($id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }

    /** POST /api/deposito/estornar/{id} — SÓ ADMIN: estorna via MercadoPago. */
    public function estornar(Request $request, int $id): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $r = $this->depositos->estornar($id);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 500));
    }

    /**
     * POST /api/deposito/manual — SÓ ADMIN. Aporte direto sem gateway:
     * escolhe o investidor e o valor; cotas creditadas na hora, registro
     * criado já pago + registrado (aparece nos históricos como "Manual").
     */
    public function manual(Request $request): JsonResponse
    {
        if ($request->user()->id !== 1) {
            return response()->json(['mensagem' => 'Acesso negado.'], 403);
        }

        $userId = (int) $request->input('user_id');
        $valor  = (float) str_replace(',', '.', (string) $request->input('valor'));

        if ($userId <= 0) {
            return response()->json(['mensagem' => 'Escolha o investidor.'], 422);
        }
        if ($valor <= 0) {
            return response()->json(['mensagem' => 'Informe um valor válido.'], 422);
        }

        $r = $this->depositos->depositoManual($userId, $valor);

        return response()->json(['mensagem' => $r['mensagem']], $r['ok'] ? 200 : ($r['code'] ?? 422));
    }
}
