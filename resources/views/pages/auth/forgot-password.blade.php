<x-layouts::auth :title="'Esqueci minha senha'">
    <div class="ec24-head"><span class="ec24-overline">RECUPERAÇÃO</span><h2>Esqueci minha senha</h2><p>Informe seu e-mail cadastrado para receber o link de redefinição.</p></div>
    @if(session('status'))<div class="ec24-notice ec24-notice-success" role="status">{{ \App\Support\AuthStatusText::translate(session('status')) }}</div>@endif
    <form method="POST" action="{{ route('password.email') }}" class="ec24-form">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autofocus autocomplete="email" placeholder="seu.nome@empresa.com" value="{{ old('email') }}" />
        @error('email')<p role="alert" class="ec24-error">{{ $message }}</p>@enderror
        <button class="ec24-primary" type="submit" data-test="email-password-reset-link-button">Enviar link <span>→</span></button>
    </form>
    <div class="ec24-divider"><span>ou</span></div>
    <a class="ec24-link-centered" href="{{ route('login') }}">← Voltar para login</a>
    <p class="ec24-info-box">O link de redefinição tem prazo limitado. Caso expire, você poderá solicitar outro.</p>
</x-layouts::auth>
