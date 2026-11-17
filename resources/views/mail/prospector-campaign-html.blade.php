<!DOCTYPE html>
<html lang="pt-BR" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="supported-color-schemes" content="dark light">
    <title>ExportControl</title>
    <style type="text/css">
        /* Estrutura principal em tabelas e estilos inline para Outlook. */
        @media only screen and (max-width: 700px) {
            .ec-email-card { width: 100% !important; }
            .ec-email-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .ec-email-stack { display: block !important; width: 100% !important; max-width: 100% !important; }
            .ec-email-feature { padding-bottom: 8px !important; }
            .ec-email-signature-image { width: 100% !important; max-width: 100% !important; height: auto !important; }
            .ec-email-signature-copy { padding-top: 12px !important; padding-left: 0 !important; }
            .ec-email-contact-column { padding: 14px 0 0 !important; }
            .ec-email-contact-link { width: auto !important; max-width: 320px !important; }
            .ec-email-brand-tagline { display: none !important; }
            .ec-benefit-col { display: block !important; width: 100% !important; padding: 0 0 8px !important; }
            .ec-benefits-head { padding-top: 12px !important; }
        }
    </style>
</head>
@php
    // O assunto e todo o texto comercial são definidos no editor de campanhas.
    // O texto comercial permanece editável; o quadro de benefícios abaixo destaca recursos da plataforma.
    // Quebras de linha vazias separam parágrafos; linhas começadas por - / • / 1.
    // tornam-se destaques visuais. Nenhum HTML fornecido pelo usuário é executado.
    $source = trim((string) ($messageBody ?? ''));
    $lines = preg_split('/\r\n|\r|\n/u', $source) ?: [];
    $blocks = [];
    $buffer = [];
    $kind = null;
    $flush = static function () use (&$blocks, &$buffer, &$kind): void {
        if ($buffer !== [] && $kind !== null) {
            $blocks[] = ['kind' => $kind, 'lines' => $buffer];
        }
        $buffer = [];
        $kind = null;
    };
    foreach ($lines as $line) {
        if (trim($line) === '') {
            $flush();
            continue;
        }
        $isBullet = preg_match('/^\s*(?:[-*•]|\d{1,2}[.)])\s+(.+)$/u', $line, $matches) === 1;
        $currentKind = $isBullet ? 'list' : 'paragraph';
        if ($kind !== null && $kind !== $currentKind) {
            $flush();
        }
        $kind = $currentKind;
        $buffer[] = $isBullet ? trim($matches[1]) : trim($line);
    }
    $flush();

    $safeText = static function (string $text): string {
        $escaped = e($text);
        return preg_replace(
            '/\*\*(.+?)\*\*/u',
            '<strong style="font-weight:700;color:#ffffff">$1</strong>',
            $escaped
        ) ?? $escaped;
    };
    // O botão abre somente o número comercial informado pelo gestor.
    // A resposta direta por e-mail permanece disponível no cliente do destinatário.
    $whatsappUrl = 'https://wa.me/5544991026597?text='.rawurlencode(
        'Olá, Adolfo! Recebi seu e-mail da ExportControl e gostaria de conversar sobre as exportações.'
    );
@endphp
<body style="margin:0;padding:0;width:100%;background-color:#edf2f3;color:#edf7f9;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%">
    <div style="display:none;font-size:1px;line-height:1px;color:#edf2f3;max-height:0;max-width:0;opacity:0;overflow:hidden">
        ExportControl — soluções para controle fiscal das exportações.
    </div>
    <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" bgcolor="#edf2f3" style="width:100%;border-collapse:collapse;background-color:#edf2f3">
        <tr>
            <td align="center" style="padding:14px 8px">
                <!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="860"><tr><td><![endif]-->
                <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="860" class="ec-email-card" bgcolor="#182328" style="width:100%;max-width:860px;border-collapse:separate;border-spacing:0;background-color:#182328;border:1px solid #bed1d4;border-radius:13px;overflow:hidden">
                    <tr>
                        <td class="ec-email-pad" bgcolor="#14242a" style="padding:10px 32px;background-color:#14242a;border-bottom:1px solid #2b6569">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse">
                                <tr>
                                    <td valign="middle" style="font-family:Arial,Helvetica,sans-serif;font-size:17px;font-weight:800;letter-spacing:0.6px;line-height:1.25;white-space:nowrap">
                                        <span style="color:#f1f8fa">EXPORT</span><span style="color:#39c8c0">CONTROL</span>
                                    </td>
                                    <td valign="middle" align="right" class="ec-email-brand-tagline" style="font-family:Arial,Helvetica,sans-serif;color:#a9c1c7;font-size:9px;letter-spacing:1px;line-height:1.5">
                                        SOLUÇÕES PARA EXPORTAÇÕES
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" bgcolor="#07535c" style="margin:0;padding:0;border:0;background-color:#07535c">
                            <img src="{{ $message->embed(public_path('images/exportcontrol-campanhas-banner.jpg')) }}" alt="ExportControl: comprovação fiscal das exportações com segurança, agilidade e controle" border="0" width="860" style="display:block;width:100%;max-width:860px;height:auto;margin:0;padding:0;border:0;outline:0;text-decoration:none;color:#ffffff;font-size:14px">
                        </td>
                    </tr>
                    <tr>
                        <td class="ec-email-pad" bgcolor="#182328" style="background-color:#182328;padding:17px 32px 10px;color:#eff8f8;font-family:Arial,Helvetica,sans-serif">
                            @foreach ($blocks as $index => $block)
                                @if ($block['kind'] === 'list')
                                    <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="width:100%;border-collapse:collapse;margin:6px 0 10px">
                                        @foreach (array_chunk($block['lines'], 2) as $pair)
                                            <tr>
                                                @foreach ($pair as $bulletLine)
                                                    <td class="ec-email-stack ec-email-feature" width="{{ (int) (100 / count($pair)) }}%" colspan="{{ count($pair) === 1 ? 2 : 1 }}" valign="top" style="width:{{ (int) (100 / count($pair)) }}%;padding:0 5px 6px 0;vertical-align:top">
                                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#27434a" style="border-collapse:separate;border-spacing:0;width:100%;background-color:#27434a;border:1px solid #36696c;border-radius:8px">
                                                            <tr>
                                                                <td width="24" valign="top" style="width:24px;padding:9px 0 8px 11px;color:#41d8c9;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:1.25">&#10003;</td>
                                                                <td valign="top" style="padding:9px 10px 8px 3px;color:#eaf5f5;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.4">{!! $safeText($bulletLine) !!}</td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </table>
                                @else
                                    @php
                                        $firstLine = (string) ($block['lines'][0] ?? '');
                                        $greeting = $index === 0 && preg_match('/^(olá|bom dia|boa tarde|boa noite)\b/iu', $firstLine) === 1;
                                        // Linhas consecutivas são o mesmo parágrafo e se ajustam
                                        // automaticamente à largura da tela, sem espaços gigantes.
                                        $text = implode(' ', array_map($safeText, $block['lines']));
                                        $closingQuestion = ! $greeting && $index >= max(0, count($blocks) - 4)
                                            && str_contains(implode(' ', $block['lines']), '?');
                                    @endphp
                                    @if ($closingQuestion)
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:1px 0 7px">
                                            <tr>
                                                <td width="3" bgcolor="#36cbbb" style="width:3px;background-color:#36cbbb;font-size:1px;line-height:1px">&nbsp;</td>
                                                <td style="padding:4px 0 4px 13px;font-family:Arial,Helvetica,sans-serif;color:#f0f7f8;font-size:14px;line-height:1.45">{!! $text !!}</td>
                                            </tr>
                                        </table>
                                    @else
                                        <p style="margin:0 0 {{ $greeting ? '9px' : '7px' }};padding:0;color:{{ $greeting ? '#ffffff' : '#dbe8ec' }};font-family:Arial,Helvetica,sans-serif;font-size:{{ $greeting ? '17px' : '14px' }};font-weight:{{ $greeting ? '700' : '400' }};line-height:{{ $greeting ? '1.35' : '1.46' }}">{!! $text !!}</p>
                                    @endif
                                @endif
                            @endforeach
                            {{-- Benefícios institucionais após o conteúdo comercial editável.
                                 Tabelas e estilos inline para Outlook; sem imagens/ícones externos. --}}
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#192b30" style="width:100%;margin:13px 0 8px;border-collapse:separate;border-spacing:0;background-color:#192b30;border:1px solid #345d61;border-radius:10px">
                                <tr>
                                    <td style="padding:14px 11px 12px">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse">
                                            <tr>
                                                <td class="ec-email-stack ec-benefit-col" width="33.33%" valign="top" style="width:33.33%;padding:0 5px;vertical-align:top">
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#203338" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#203338;border:1px solid #355960;border-radius:7px">
                                                        <tr><td style="padding:12px 11px 11px;color:#d9edef;font-family:Arial,Helvetica,sans-serif;line-height:1.4">
                                                            <span style="display:inline-block;color:#70e4d8;font-size:10px;letter-spacing:1px;font-weight:700">01 / CONFERÊNCIA</span>
                                                            <div style="padding-top:6px;color:#ffffff;font-size:13px;line-height:1.25;font-weight:700">Documentos integrados</div>
                                                            <div style="padding-top:5px;color:#c7dbdf;font-size:12px;line-height:1.45">NF-e, DU-Es e averbações no mesmo acompanhamento.</div>
                                                        </td></tr>
                                                    </table>
                                                </td>
                                                <td class="ec-email-stack ec-benefit-col" width="33.33%" valign="top" style="width:33.33%;padding:0 5px;vertical-align:top">
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#203338" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#203338;border:1px solid #355960;border-radius:7px">
                                                        <tr><td style="padding:12px 11px 11px;color:#d9edef;font-family:Arial,Helvetica,sans-serif;line-height:1.4">
                                                            <span style="display:inline-block;color:#70e4d8;font-size:10px;letter-spacing:1px;font-weight:700">02 / ACOMPANHAMENTO</span>
                                                            <div style="padding-top:6px;color:#ffffff;font-size:13px;line-height:1.25;font-weight:700">Saldos e prazos</div>
                                                            <div style="padding-top:5px;color:#c7dbdf;font-size:12px;line-height:1.45">Quantidades exportadas e pendências com mais visibilidade.</div>
                                                        </td></tr>
                                                    </table>
                                                </td>
                                                <td class="ec-email-stack ec-benefit-col" width="33.33%" valign="top" style="width:33.33%;padding:0 5px;vertical-align:top">
                                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#203338" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#203338;border:1px solid #355960;border-radius:7px">
                                                        <tr><td style="padding:12px 11px 11px;color:#d9edef;font-family:Arial,Helvetica,sans-serif;line-height:1.4">
                                                            <span style="display:inline-block;color:#70e4d8;font-size:10px;letter-spacing:1px;font-weight:700">03 / RASTREABILIDADE</span>
                                                            <div style="padding-top:6px;color:#ffffff;font-size:13px;line-height:1.25;font-weight:700">Menos retrabalho</div>
                                                            <div style="padding-top:5px;color:#c7dbdf;font-size:12px;line-height:1.45">Informações centralizadas para a comprovação fiscal.</div>
                                                        </td></tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#182328" style="height:1px;background-color:#345e62;font-size:1px;line-height:1px" height="1">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="ec-email-pad" bgcolor="#182328" style="background-color:#182328;padding:14px 32px 16px">
                            {{-- Assinatura e contato: o cartão inteiro à direita abre o WhatsApp.
                                 Três colunas em desktop e empilhamento em telas pequenas. --}}
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:collapse;table-layout:fixed">
                                <tr>
                                    <td class="ec-email-stack" width="40%" valign="middle" style="width:40%;vertical-align:middle">
                                        <img class="ec-email-signature-image" src="{{ $message->embed(public_path('images/exportcontrol-assinatura-adolfo.jpg')) }}" alt="Cartão de assinatura ExportControl com foto de Adolfo Heerdt" width="308" border="0" style="display:block;width:308px;max-width:97%;height:auto;border:0;outline:0;text-decoration:none;border-radius:7px">
                                    </td>
                                    <td class="ec-email-stack ec-email-signature-copy" width="34%" valign="middle" style="width:34%;vertical-align:middle;padding-left:10px;padding-right:7px;font-family:Arial,Helvetica,sans-serif;overflow-wrap:anywhere">
                                        <p style="margin:0 0 3px;color:#ffffff;font-size:19px;font-weight:700;line-height:1.25">Adolfo Heerdt</p>
                                        <p style="margin:0 0 9px;color:#65e1d6;font-size:10px;font-weight:700;letter-spacing:0.7px;line-height:1.4">ASSISTENTE COMERCIAL</p>
                                        <p style="margin:0 0 4px;font-size:12px;line-height:1.4"><a href="mailto:adolfo@exportcontrol.com.br" style="color:#edf7f8;text-decoration:none">adolfo@exportcontrol.com.br</a></p>
                                        <p style="margin:0 0 4px;font-size:12px;line-height:1.4"><a href="tel:+5544991026597" style="color:#edf7f8;text-decoration:none">(44) 99102-6597</a></p>
                                        <p style="margin:0;font-size:12px;line-height:1.4"><a href="https://exportcontrol.com.br" style="color:#74ebdc;text-decoration:underline">exportcontrol.com.br</a></p>
                                    </td>
                                    <td class="ec-email-stack ec-email-contact-column" width="26%" valign="middle" style="width:26%;vertical-align:middle;padding-left:7px;font-family:Arial,Helvetica,sans-serif">
                                        <a class="ec-email-contact-link" href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Entre em contato conosco pelo WhatsApp" style="display:block;text-align:center;text-decoration:none;background:linear-gradient(180deg,#0c7468 0%,#085f55 100%);border:1px solid #42d9c9;border-radius:12px;padding:16px 12px;color:#ffffff;font-family:Arial,Helvetica,sans-serif;box-shadow:0 8px 18px rgba(0,0,0,0.18)">
                                            <span style="display:block;color:#e8fffb;font-size:12px;line-height:1.35;font-weight:700;letter-spacing:0.2px">Entre em contato conosco</span>
                                            <span style="display:block;padding-top:11px;background-color:#0aa18f;border-radius:9px;color:#ffffff;font-size:15px;line-height:1.25;font-weight:700;padding-bottom:11px">Conversar no WhatsApp</span>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @if ($unsubscribeUrl)
                        <tr>
                            <td class="ec-email-pad" bgcolor="#e8f1f2" style="background-color:#e8f1f2;padding:11px 23px;color:#47616a;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:1.5">
                                Você recebeu uma mensagem comercial da ExportControl. Caso não deseje novos e-mails, <a href="{{ $unsubscribeUrl }}" style="color:#006e68;text-decoration:underline;font-weight:700">solicite o descadastro</a>.
                            </td>
                        </tr>
                    @endif
                    @if ($isTest)
                        <tr>
                            <td class="ec-email-pad" bgcolor="#d8f0eb" style="background-color:#d8f0eb;padding:9px 23px;color:#125e59;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:1.4">
                                Mensagem de teste — nenhum contato da campanha recebeu este e-mail.
                            </td>
                        </tr>
                    @endif
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
