<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>Preferências de comunicação | ExportControl</title>
    {{-- EC_UNSUBSCRIBE_V909: página pública sem JavaScript, fontes externas ou rastreadores. --}}
    <style>
        :root{--ec-navy:#09273e;--ec-ink:#123a50;--ec-muted:#627e90;--ec-green:#0b9c8b;--ec-line:#d9e8e9;--ec-light:#f5faf9}
        *{box-sizing:border-box}
        html{min-height:100%;background:#f1f7f7}
        body{min-height:100vh;margin:0;font-family:ui-sans-serif,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:var(--ec-ink);background:radial-gradient(850px 560px at 85% -15%,#ccece4 0,transparent 72%),radial-gradient(650px 430px at -15% 100%,#dfebf4 0,transparent 70%),#f1f7f7}
        .page{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:26px;padding:45px 20px}
        .brand{width:min(100%,580px);display:flex;align-items:center;gap:11px;letter-spacing:.06em;font-weight:800;font-size:14px;color:var(--ec-navy)}
        .brand-mark{height:37px;width:37px;display:grid;place-items:center;border:2px solid #0c817a;border-radius:12px;color:#087d76;background:#e0f6ef;font-weight:900;letter-spacing:-.12em;padding-right:2px}
        .brand strong{display:block;line-height:1.1}.brand span{font-size:10px;letter-spacing:.13em;color:#198b85;font-weight:750}
        .card{width:min(100%,580px);overflow:hidden;border:1px solid #d5e6e6;background:#fff;border-radius:20px;box-shadow:0 22px 70px #17364b14,0 2px 8px #17364b08}
        .bar{height:5px;background:linear-gradient(90deg,#006d76,#15bda5 56%,#8ad5b9)}
        .content{padding:36px 39px 35px}
        .kicker{font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:#078c7e}
        .status-icon{display:grid;place-items:center;height:52px;width:52px;margin:19px 0 19px;border-radius:16px;background:#e5f6ef;border:1px solid #c9ebdf;color:#087a70;font-size:27px;font-weight:700}
        h1{font-size:clamp(27px,4.5vw,33px);font-weight:800;line-height:1.16;letter-spacing:-.045em;color:var(--ec-navy);margin:0 0 14px}
        .intro{font-size:15px;line-height:1.75;color:#587486;margin:0 0 22px}
        .notice{display:flex;align-items:flex-start;gap:12px;border:1px solid #daebe9;background:var(--ec-light);padding:16px 17px;border-radius:12px;margin:0 0 24px}
        .notice-icon{flex:0 0 24px;height:24px;display:grid;place-items:center;border-radius:50%;background:#d8f1e7;color:#077d70;font-weight:800;font-size:14px}
        .notice strong{display:block;font-size:13px;color:#153d50;margin-bottom:3px}
        .notice p{font-size:12px;line-height:1.6;color:#648092;margin:0}
        .btn{width:100%;min-height:51px;display:inline-flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:11px;background:#008e82;color:white;font-size:14px;font-weight:750;cursor:pointer;padding:14px 20px;transition:background .15s,transform .15s}
        .btn:hover{background:#006e67;transform:translateY(-1px)}
        .btn:focus-visible{outline:3px solid #51c6b4;outline-offset:3px}
        .btn svg{height:17px;width:17px}
        .fine{color:#8499a6;font-size:11px;line-height:1.6;text-align:center;margin:16px 0 0}
        .success{border:1px solid #bfe6d7;background:#eafaef;padding:17px;border-radius:12px;font-size:14px;line-height:1.75;color:#146e5d;margin:12px 0 0}
        .success strong{display:block;font-size:16px;color:#086a5f;margin-bottom:3px}
        .bottom{border-top:1px solid #e7eeee;padding:16px 39px;background:#fcfefe;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;color:#8298a5;font-size:11px}
        .footer{font-size:11px;line-height:1.5;color:#7d94a4;text-align:center;max-width:560px}
        @media(max-width:620px){.page{padding:24px 13px;gap:19px}.content{padding:28px 23px}.bottom{padding:15px 23px}.intro{font-size:14px}}
        @media(prefers-reduced-motion:reduce){.btn{transition:none}.btn:hover{transform:none}}
    </style>
</head>
<body>
<div class="page">
    <div class="brand" aria-label="ExportControl">
        <div class="brand-mark" aria-hidden="true">EC</div>
        <div><strong>EXPORTCONTROL</strong><span>COMUNICAÇÃO COMERCIAL</span></div>
    </div>
    <main class="card">
        <div class="bar" aria-hidden="true"></div>
        <div class="content">
            <span class="kicker">Controle de comunicação</span>
            @if (session('unsubscribed'))
                <div class="status-icon" aria-hidden="true">✓</div>
                <h1>Descadastro confirmado</h1>
                <div class="success" role="status">
                    <strong>Solicitação registrada com sucesso.</strong>
                    Este endereço não receberá novos e-mails comerciais das campanhas da ExportControl.
                </div>
                <p class="fine">Não é necessário realizar nenhuma outra ação.</p>
            @else
                <div class="status-icon" aria-hidden="true">✉</div>
                <h1>Você decide quais e-mails receber.</h1>
                <p class="intro">Se não deseja continuar recebendo nossas campanhas comerciais, confirme sua solicitação abaixo. É simples, gratuito e leva apenas um clique.</p>
                <div class="notice">
                    <span class="notice-icon" aria-hidden="true">i</span>
                    <div><strong>O que muda?</strong><p>Interromperemos futuros e-mails comerciais. Seus registros fiscais e comunicações transacionais obrigatórias não serão alterados.</p></div>
                </div>
                {{-- EC_CAMPAIGNS_V7_RELATIVE_FORM: POST com token HMAC e CSRF; GET nunca descadastra. --}}
                <form method="POST" action="{{ route('campaigns.unsubscribe.store', ['token' => $token], false) }}">
                    @csrf
                    <button class="btn" type="submit">
                        Confirmar descadastro {{-- EC_V90_10_CI_COMPAT --}}
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                    </button>
                </form>
                <p class="fine">Esta página é exclusiva para gerenciar o recebimento de comunicações comerciais.</p>
            @endif
        </div>
        <div class="bottom"><span>ExportControl · Preferências de e-mail</span><span>Privacidade e transparência</span></div>
    </main>
    <footer class="footer">Se você não reconhece este contato, não é necessário fornecer nenhum dado pessoal para solicitar o descadastro.</footer>
</div>
</body>
</html>
