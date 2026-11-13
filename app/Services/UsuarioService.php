<?php

namespace App\Services;

use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;
use App\Models\FcmToken;
use App\Models\PixPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Remoção de contas (admin) — para contas sem nenhum dinheiro envolvido.
 * Os guards abaixo são a autoridade: o app só esconde o botão de quem tem
 * cotas, mas é AQUI que se decide se pode remover de verdade.
 *
 * Convenção de retorno: igual aos outros services — 'ok', 'mensagem' e
 * 'code' com o HTTP sugerido em falhas.
 */
class UsuarioService
{
    /**
     * REMOVE (soft delete) a conta de um usuário sem valor financeiro
     * vivo: sem cotas, sem saque pendente e sem depósito pago que ainda
     * não foi registrado (dinheiro recebido e não processado). A conta é
     * DESATIVADA, não apagada: usuário e histórico financeiro (depósitos,
     * saques) ficam no banco pra sempre — todas as listas e o login usam
     * Eloquent, então conta removida some delas sozinha. Tokens de
     * acesso/app e de push são revogados na hora.
     */
    public function remover(int $userId, int $adminId): array
    {
        if ($userId === $adminId) {
            return ['ok' => false, 'code' => 422, 'mensagem' => 'Você não pode remover a sua própria conta.'];
        }

        $user = User::find($userId);
        if (!$user) {
            return ['ok' => false, 'code' => 404, 'mensagem' => 'Conta não encontrada.'];
        }

        $invest = BotInvestment::where('user_id', $userId)->first();
        if ($invest && ((float) $invest->cotas > 0 || (float) $invest->investimento_inicial > 0)) {
            return ['ok' => false, 'code' => 422, 'mensagem' => "{$user->name} possui cotas — a conta não pode ser removida."];
        }

        if (PixPayment::where('user_id', $userId)->where('status', 'pago')->where('registrado', false)->exists()) {
            return ['ok' => false, 'code' => 422, 'mensagem' => "{$user->name} tem depósito pago sem registro no bot — registre ou estorne antes de remover a conta."];
        }

        if (BotWithdrawalRequest::where('user_id', $userId)->where('status', 'pendente')->exists()) {
            return ['ok' => false, 'code' => 422, 'mensagem' => "{$user->name} tem um saque pendente — resolva antes de remover a conta."];
        }

        DB::transaction(function () use ($user, $userId) {
            // Acesso morto na hora: token do app (Sanctum) e push (FCM)
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $userId)
                ->delete();
            FcmToken::where('user_id', $userId)->delete();

            // Soft delete: deleted_at setado, tudo mais preservado
            $user->delete();
        });

        return ['ok' => true, 'mensagem' => "Conta de {$user->name} removida — o histórico fica preservado no banco."];
    }
}
