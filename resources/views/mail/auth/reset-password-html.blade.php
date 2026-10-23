<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Redefinição de senha | ExportControl Prospector</title>
</head>
<body style="margin:0;padding:0;background:#f2f6f9;color:#0b2840;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;font-size:1px;color:#f2f6f9;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">
        Recebemos um pedido para redefinir a sua senha de acesso ao ExportControl Prospector.
    </div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#f2f6f9;">
        <tr>
            <td align="center" style="padding:38px 14px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="border-collapse:collapse;max-width:600px;width:100%;background:#ffffff;">
                    <tr>
                        <td style="background:#061f34;padding:28px 34px;border-bottom:4px solid #0faeab;">
                            <div style="font-size:13px;font-weight:700;letter-spacing:1.5px;color:#34d1cc;">EXPORTCONTROL</div>
                            <div style="font-size:22px;font-weight:700;letter-spacing:.2px;color:#ffffff;padding-top:4px;">Prospector</div>
                            <div style="font-size:12px;color:#b9d2dd;padding-top:9px;">Inteligência comercial e prospecção com contexto</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px 34px 14px;">
                            <div style="font-size:12px;letter-spacing:1.2px;font-weight:700;color:#087f80;">SEGURANÇA DA CONTA</div>
                            <h1 style="color:#08263d;font-size:27px;line-height:1.25;font-weight:700;margin:14px 0 20px;">Redefinição de senha</h1>
                            <p style="margin:0 0 15px;font-size:16px;line-height:1.65;color:#173c52;">Olá@if(trim($recipientName) !== ''), {{ $recipientName }}@endif!</p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.75;color:#33566b;">Recebemos uma solicitação para redefinir a senha de acesso à sua conta no <strong>ExportControl Prospector</strong>.</p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.75;color:#33566b;">Se foi você quem solicitou, clique no botão abaixo para criar uma nova senha.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:0 34px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
                                <tr>
                                    <td bgcolor="#098b83" style="border-radius:6px;text-align:center;background:#098b83;">
                                        <a href="{{ $resetUrl }}" style="display:inline-block;padding:17px 35px;text-decoration:none;font-size:16px;font-weight:700;line-height:1.3;color:#ffffff;">Redefinir minha senha</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 34px 30px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#ecf7f6;border-left:4px solid #0faeab;">
                                <tr><td style="padding:18px 19px;font-size:14px;line-height:1.6;color:#17495a;">Este link é válido por <strong>{{ $expiresInMinutes }} minutos</strong> e pode ser utilizado uma única vez.</td></tr>
                            </table>
                            <p style="margin:25px 0 10px;font-size:14px;line-height:1.7;color:#33566b;">Se você <strong>não solicitou</strong> essa alteração, ignore esta mensagem. Sua senha atual permanecerá válida.</p>
                            <p style="margin:18px 0 7px;font-size:12px;line-height:1.6;color:#6d8494;">Se o botão não funcionar, copie e cole o endereço abaixo no navegador:</p>
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#078b86;word-wrap:break-word;overflow-wrap:anywhere;"><a href="{{ $resetUrl }}" style="color:#078b86;text-decoration:underline;word-break:break-all;">{{ $resetUrl }}</a></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f0f5f7;border-top:1px solid #dce7ec;padding:22px 34px;">
                            <p style="margin:0;color:#5e7889;font-size:12px;line-height:1.6;">ExportControl Prospector · Mensagem automática de segurança.</p>
                            <p style="margin:6px 0 0;color:#5e7889;font-size:12px;line-height:1.6;">Não responda a este e-mail para alterar sua senha. Use somente o link de recuperação acima.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
