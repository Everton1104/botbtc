<?php

namespace App\Services;

use App\Models\BotInvestment;
use App\Models\BotWithdrawalRequest;
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
     * Remove a conta de um usuário SEM cotas, sem depósito pago e sem
     * saque pendente — nada com valor financeiro pode existir. Junto vão
     * os tokens de acesso/app, cobranças mortas (pendente/expirado) e a
     * linha zerada de investimento; os FCM tokens caem por FK cascade.
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

        if (PixPayment::where('user_id', $userId)->whereIn('status', ['pago', 'estornado'])->exists()) {
            return ['ok' => false, 'code' => 422, 'mensagem' => "{$user->name} tem depósitos no histórico — a conta não pode ser removida."];
        }

        if (BotWithdrawalRequest::where('user_id', $userId)->where('status', 'pendente')->exists()) {
            return ['ok' => false, 'code' => 422, 'mensagem' => "{$user->name} tem um saque pendente — resolva antes de remover a conta."];
        }

        DB::transaction(function () use ($user, $userId) {
            // Tokens Sanctum do app e sobras sem valor financeiro
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', $userId)
                ->delete();
            PixPayment::where('user_id', $userId)->delete();
            BotWithdrawalRequest::where('user_id', $userId)->delete();
            BotInvestment::where('user_id', $userId)->delete();

            $user->delete(); // fcm_tokens caem por FK cascade
        });

        return ['ok' => true, 'mensagem' => "Conta de {$user->name} removida."];
    }
}
