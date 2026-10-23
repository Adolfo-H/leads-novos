<?php

namespace App\Support;

final class AuthStatusText
{
    public static function translate(mixed $status): string
    {
        if (! is_string($status)) {
            return '';
        }

        return match (trim($status)) {
            'passwords.sent',
            'We have emailed your password reset link.' => 'Enviamos um link de redefinição de senha para o e-mail informado. Confira sua caixa de entrada e a pasta de spam.',

            'passwords.reset',
            'Your password has been reset.' => 'Sua senha foi redefinida com sucesso. Entre com a nova senha.',

            'passwords.throttled',
            'Please wait before retrying.' => 'Aguarde um momento antes de solicitar outro link.',

            'passwords.token',
            'This password reset token is invalid.' => 'Este link de redefinição é inválido ou expirou. Solicite um novo.',

            'passwords.user',
            "We can't find a user with that email address." => 'Não encontramos uma conta com esse e-mail.',

            default => $status,
        };
    }
}
