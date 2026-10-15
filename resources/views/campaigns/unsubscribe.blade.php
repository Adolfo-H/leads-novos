<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Cancelar e-mails comerciais | ExportControl</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;background:#061e35;color:#eaf6ff;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}.panel{max-width:480px;background:#0a2d4b;border:1px solid #235577;border-radius:14px;padding:30px}h1{font-size:23px;margin:0 0 12px}p{line-height:1.7;color:#abc8da}.action{background:#35d4c6;color:#052d37;font-weight:700;border:0;border-radius:9px;padding:13px 19px;cursor:pointer}.success{color:#67e6ce}</style>
</head>
<body>
<main class="panel">
    <small style="color:#3be0cf;letter-spacing:.11em">EXPORTCONTROL</small>
    <h1>Preferências de comunicação</h1>
    @if (session('unsubscribed'))
        <p class="success">Pedido registrado. Este endereço foi retirado dos próximos disparos comerciais.</p>
    @else
        <p>Você pode cancelar o recebimento de futuros e-mails comerciais da ExportControl. O cancelamento não altera cadastros fiscais ou comunicações transacionais obrigatórias.</p>
        <form method="POST" action="{{ route('campaigns.unsubscribe.store', ['token' => $token]) }}">
            @csrf
            <button class="action" type="submit">Confirmar descadastro</button>
        </form>
    @endif
</main>
</body>
</html>
