<x-layouts::auth :title="'Criar nova senha'">
    <div class="ec24-head"><span class="ec24-overline">RECUPERAÇÃO</span><h2>Crie uma nova senha</h2><p>Defina uma senha segura para recuperar o acesso à sua conta.</p></div>
    <form method="POST" action="{{ route('password.update') }}" class="ec24-form">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autocomplete="username" value="{{ old('email', request('email')) }}" />
        @error('email')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <label for="password">Nova senha</label>
        <input id="password" name="password" type="password" required autocomplete="new-password" minlength="8" />
        @error('password')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <label for="password_confirmation">Confirme a nova senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="8" />
        <button type="submit" class="ec24-primary" data-test="reset-password-button">Atualizar senha <span>→</span></button>
    </form>
</x-layouts::auth>
