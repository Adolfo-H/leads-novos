<?php

use App\Models\User;
use App\Notifications\ExportControlResetPassword;
use App\Support\AuthStatusText;
use Illuminate\Support\Facades\Notification;

it('mostra os avisos de recuperação e redefinição em português', function (): void {
    $this->withSession(['status' => 'We have emailed your password reset link.'])
        ->get(route('password.request'))
        ->assertOk()
        ->assertSee('Enviamos um link de redefinição de senha')
        ->assertDontSee('We have emailed your password reset link.');

    $this->withSession(['status' => 'Your password has been reset.'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Sua senha foi redefinida com sucesso.')
        ->assertDontSee('Your password has been reset.');

    expect(AuthStatusText::translate('passwords.sent'))
        ->toContain('Enviamos um link');

    expect(AuthStatusText::translate('passwords.reset'))
        ->toContain('Sua senha foi redefinida');
});

it('envia somente a notificação de redefinição com conteúdo ExportControl em português', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Usuário ExportControl',
    ]);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertSentTo(
        $user,
        ExportControlResetPassword::class,
        function (ExportControlResetPassword $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            expect($mail->subject)->toBe('Redefinição de senha | ExportControl Prospector');

            expect($mail->view)->toBe([
                'html' => 'mail.auth.reset-password-html',
                'text' => 'mail.auth.reset-password-text',
            ]);

            $viewData = $mail->viewData;
            $expectedMinutes = (int) config('auth.passwords.users.expire', 60);

            expect($viewData['expiresInMinutes'])->toBe($expectedMinutes);
            expect($viewData['resetUrl'])->toContain('/reset-password/');
            expect($viewData['resetUrl'])->toContain('email=');

            $html = view('mail.auth.reset-password-html', $viewData)->render();
            $plain = view('mail.auth.reset-password-text', $viewData)->render();

            expect($html)->toContain('ExportControl Prospector')
                ->toContain('Redefinir minha senha')
                ->not->toContain('Hello!');

            expect($plain)->toContain('Recebemos uma solicitação')
                ->toContain('Sua senha atual permanecerá válida.');

            return true;
        },
    );
});
