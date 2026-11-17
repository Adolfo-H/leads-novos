<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ExportControl</title>
    <style type="text/css">
        /* EC_CAMP_MAIL_COMPACT_V2: somente melhorias de responsividade; estilos críticos inline. */
        @media only screen and (max-width: 640px) {
            .ec-mail-card { width: 100% !important; max-width: 100% !important; }
            .ec-mail-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .ec-mail-stack { display: block !important; width: 100% !important; max-width: 100% !important; }
            .ec-mail-signature { width: 175px !important; max-width: 100% !important; height: auto !important; }
            .ec-mail-signature-text { padding-top: 12px !important; padding-left: 0 !important; }
        }
    </style>
</head>
@php
    // Preserva os parágrafos editados pelo comercial, sem executar HTML inserido no editor.
    $source = trim((string) ($messageBody ?? ''));
    $blocks = preg_split('/\R\s*\R/u', $source) ?: [];
    $blocks = array_values(array_filter(array_map('trim', $blocks), static fn (string $value): bool => $value !== ''));
    $whatsappUrl = 'https://wa.me/5544991026597?text='.rawurlencode(
        'Olá, Adolfo! Recebi seu e-mail da ExportControl e gostaria de conversar sobre exportações.'
    );
@endphp
<body style="margin:0;padding:0;width:100%;background-color:#edf3f4;color:#173c43;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#edf3f4">
        Acompanhamento e comprovação fiscal das exportações — ExportControl.
    </div>
    <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" bgcolor="#edf3f4" style="width:100%;border-collapse:collapse;background-color:#edf3f4">
        <tr>
            <td align="center" style="padding:18px 9px">
                <!--[if mso]><table role="presentation" width="620" border="0" cellspacing="0" cellpadding="0"><tr><td><![endif]-->
                <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="620" class="ec-mail-card" bgcolor="#ffffff" style="width:100%;max-width:620px;background-color:#ffffff;border-collapse:separate;border-spacing:0;border:1px solid #d9e5e8;border-radius:11px;overflow:hidden">
                    <tr>
                        <td class="ec-mail-pad" bgcolor="#153a43" style="background-color:#153a43;padding:13px 25px;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:800;letter-spacing:0.4px;line-height:1.2">
                            <span style="color:#f5ffff">EXPORT</span><span style="color:#39c8c0">CONTROL</span>
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#07535c" style="background-color:#07535c;padding:0;border:0">
                            <img src="{{ $message->embed(public_path('images/exportcontrol-campanhas-banner.jpg')) }}" alt="ExportControl — soluções para controle das exportações" width="620" border="0" style="width:100%;max-width:620px;height:auto;display:block;border:0;outline:0;text-decoration:none">
                        </td>
                    </tr>
                    <tr>
                        <td class="ec-mail-pad" bgcolor="#ffffff" style="padding:23px 28px 10px;background-color:#ffffff;color:#173c43;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55">
                            @foreach ($blocks as $index => $block)
                                @php
                                    $isQuestion = $index === count($blocks) - 1 && str_contains($block, '?');
                                    $safeBlock = nl2br(e($block));
                                @endphp
                                @if ($isQuestion)
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:10px 0 13px">
                                        <tr>
                                            <td width="3" bgcolor="#16a89e" style="width:3px;background-color:#16a89e;font-size:1px;line-height:1px">&nbsp;</td>
                                            <td bgcolor="#f2faf8" style="padding:11px 13px;color:#123b40;background-color:#f2faf8;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;font-weight:600">{!! $safeBlock !!}</td>
                                        </tr>
                                    </table>
                                @else
                                    <p style="margin:0 0 14px;color:#173c43;font-size:15px;font-weight:{{ $index === 0 ? '600' : '400' }};font-family:Arial,Helvetica,sans-serif;line-height:1.55">{!! $safeBlock !!}</p>
                                @endif
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td class="ec-mail-pad" bgcolor="#ffffff" style="padding:5px 28px 21px;background-color:#ffffff">
                            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="width:100%;border-collapse:collapse;border-top:1px solid #d8e7e8">
                                <tr>
                                    <td class="ec-mail-stack" width="34%" valign="middle" style="padding-top:16px;width:34%;vertical-align:middle">
                                        <img class="ec-mail-signature" src="{{ $message->embed(public_path('images/exportcontrol-assinatura-adolfo.jpg')) }}" alt="Assinatura de Adolfo Heerdt — ExportControl" width="175" border="0" style="display:block;width:175px;max-width:100%;height:auto;border:0;border-radius:6px">
                                    </td>
                                    <td class="ec-mail-stack ec-mail-signature-text" width="66%" valign="middle" style="width:66%;padding:16px 0 0 13px;vertical-align:middle;font-family:Arial,Helvetica,sans-serif;overflow-wrap:anywhere">
                                        <p style="margin:0 0 3px;font-size:16px;font-weight:700;line-height:1.25;color:#173c43">Adolfo Heerdt</p>
                                        <p style="margin:0 0 7px;font-size:11px;line-height:1.3;color:#298780;font-weight:700">ASSISTENTE COMERCIAL · EXPORTCONTROL</p>
                                        <p style="margin:0 0 5px;font-size:12px;line-height:1.35"><a href="mailto:adolfo@exportcontrol.com.br" style="color:#36565c;text-decoration:none">adolfo@exportcontrol.com.br</a></p>
                                        <p style="margin:0 0 7px;font-size:12px;line-height:1.35;color:#36565c">(44) 99102-6597</p>
                                        <p style="margin:0;font-size:12px;line-height:1.5"><a href="{{ $whatsappUrl }}" style="color:#087c70;text-decoration:underline;font-weight:700">Conversar pelo WhatsApp</a></p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if ($unsubscribeUrl)
                        <tr>
                            <td class="ec-mail-pad" bgcolor="#f4f8f8" style="padding:12px 28px;background-color:#f4f8f8;color:#617b80;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5">
                                Se não desejar novos e-mails comerciais da ExportControl, <a href="{{ $unsubscribeUrl }}" style="color:#087c70;text-decoration:underline">solicite o descadastro</a>.
                            </td>
                        </tr>
                    @endif
                    @if ($isTest)
                        <tr>
                            <td bgcolor="#e3f5f2" style="padding:10px 28px;background-color:#e3f5f2;color:#246c62;font-family:Arial,Helvetica,sans-serif;font-size:11px">Mensagem de teste — nenhum contato da campanha recebeu este e-mail.</td>
                        </tr>
                    @endif
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
