<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail de recuperação de senha em português.
 *
 * Substitui a notification padrão do framework (que chega toda em inglês),
 * mantendo o MESMO link do Password broker — o fluxo do site não muda, só
 * a língua. Usada pelo app e pelo site (o broker chama
 * sendPasswordResetNotification nos dois caminhos).
 */
class ResetPassword extends Notification
{
    use Queueable;

    public function __construct(
        /** Token de reset — o mesmo gravado em password_reset_tokens. */
        public readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $config = 'auth.passwords.'.config('auth.defaults.passwords').'.expire';
        $expiraEmMinutos = (int) config($config, 60);

        // Mesma URL que a notification padrão montaria (rota password.reset
        // do site) — a página de troca de senha abre no navegador.
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]));

        return (new MailMessage)
            ->subject('Recuperação de senha — '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Recebemos um pedido de troca de senha para a sua conta.')
            ->action('Trocar senha', $url)
            ->line("Este link é válido por {$expiraEmMinutos} minutos.")
            ->line('Se não foi você quem pediu, pode ignorar este e-mail — a sua senha atual continua a mesma.')
            ->salutation('Atenciosamente, '.config('app.name'));
    }
}
