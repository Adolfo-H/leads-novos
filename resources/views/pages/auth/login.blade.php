<x-layouts::auth :title="'Entrar'">
    @if (session('access_activated'))
        <div role="status" class="ec24-notice ec24-notice-success">{{ session('access_activated') }}</div>
    @endif
    @if (session('status'))
        <div role="status" class="ec24-notice ec24-notice-success">{{ \App\Support\AuthStatusText::translate(session('status')) }}</div>
    @endif
    <div class="ec24-head"><span class="ec24-overline">ACESSO</span><h2>Entrar</h2><p>Acesse o ExportControl Prospector e continue seu trabalho.</p></div>
    <form method="POST" action="{{ route('login.store') }}" class="ec24-form">
        @csrf
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" placeholder="adolfo@exportcontrol.com.br" required autofocus autocomplete="username" value="{{ old('email') }}" aria-describedby="login-email-error" />
        @error('email')<p class="ec24-error" id="login-email-error" role="alert">{{ $message === 'These credentials do not match our records.' ? 'E-mail ou senha incorretos. Verifique os dados e tente novamente.' : $message }}</p>@enderror
        <label for="password">Senha</label>
        <div class="ec24-password-field">
            <input id="password" name="password" type="password" placeholder="Sua senha" required autocomplete="current-password" />
            <button type="button" class="ec24-eye" aria-controls="password" aria-label="Mostrar senha" onclick="const i=document.getElementById('password');const v=i.type==='password';i.type=v?'text':'password';this.setAttribute('aria-label',v?'Ocultar senha':'Mostrar senha')">◉</button>
        </div>
        @error('password')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <div class="ec24-between"><label class="ec24-checkbox"><input type="checkbox" name="remember" value="1" @checked(old('remember')) /> Lembrar de mim</label><a href="{{ route('password.request') }}">Esqueci minha senha</a></div>
        <button type="submit" class="ec24-primary" data-test="login-button">Entrar <span aria-hidden="true">→</span></button>
    </form>
    <div class="ec24-divider"><span>ou</span></div>
    <a class="ec24-secondary" href="{{ route('access.request.create') }}">Solicitar acesso à plataforma</a>
    <div class="ec24-secure"><span aria-hidden="true">♧</span> Login seguro. A criação de contas depende de aprovação do responsável.</div>
</x-layouts::auth>
