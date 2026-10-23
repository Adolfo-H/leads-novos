<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Personaliza SOMENTE a apresentação do e-mail de recuperação.
 * O token, o endereço de recuperação, a expiração e a validação
 * continuam sendo implementados pelo próprio Laravel/Fortify.
 */
final class ExportControlResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $broker = (string) config('auth.defaults.passwords', 'users');
        $minutes = (int) config('auth.passwords.'.$broker.'.expire', 60);

        return (new MailMessage)
            ->subject('Redefinição de senha | ExportControl Prospector')
            ->view(
                [
                    'html' => 'mail.auth.reset-password-html',
                    'text' => 'mail.auth.reset-password-text',
                ],
                [
                    'resetUrl' => $this->resetUrl($notifiable),
                    'expiresInMinutes' => $minutes,
                    'recipientName' => (string) data_get($notifiable, 'name', ''),
                ],
            );
    }
}
