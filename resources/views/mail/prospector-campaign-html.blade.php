<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f3f7fa;font-family:Arial,Helvetica,sans-serif;color:#153249">
    <table role="presentation" cellspacing="0" cellpadding="0" style="max-width:640px;width:100%;margin:auto;background:#ffffff;border:1px solid #d9e5ed;border-radius:10px">
        <tr><td style="padding:22px 28px;border-bottom:3px solid #26c7bc"><strong style="font-size:18px;color:#073457">EXPORTCONTROL</strong></td></tr>
        <tr><td style="padding:28px;font-size:15px;line-height:1.7;overflow-wrap:anywhere">{!! nl2br(e($messageBody)) !!}</td></tr>
        @if ($unsubscribeUrl)
            <tr><td style="padding:18px 28px;border-top:1px solid #e4edf2;font-size:12px;line-height:1.6;color:#60798a">Você recebeu esta mensagem por um contato profissional. Se não desejar novos e-mails comerciais da ExportControl, <a href="{{ $unsubscribeUrl }}" style="color:#087f82;text-decoration:underline">clique para solicitar o descadastro</a>.</td></tr>
        @endif
        @if ($isTest)
            <tr><td style="padding:12px 28px;background:#effafa;color:#08675e;font-size:12px">Mensagem de teste — nenhum contato de campanha recebeu este e-mail.</td></tr>
        @endif
    </table>
</body>
</html>
