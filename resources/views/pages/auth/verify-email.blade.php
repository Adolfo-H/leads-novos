<x-layouts::auth :title="'Verificar e-mail'">
    <div class="ec24-head"><span class="ec24-overline">CONFIRMAÇÃO DE IDENTIDADE</span><h2>Confirme seu e-mail</h2><p>Enviamos um link de verificação para o endereço cadastrado. Abra sua caixa de entrada para continuar.</p></div>
    @if(session('status') === 'verification-link-sent')<div class="ec24-notice ec24-notice-success" role="status">Enviamos um novo link de confirmação.</div>@endif
    <form method="POST" action="{{ route('verification.send') }}" class="ec24-form">@csrf<button type="submit" class="ec24-primary">Reenviar e-mail de confirmação <span>→</span></button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="ec24-secondary" data-test="logout-button">Sair da conta</button></form>
</x-layouts::auth>
