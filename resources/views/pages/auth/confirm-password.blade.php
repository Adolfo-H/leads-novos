<x-layouts::auth :title="'Confirme sua senha'">
    <div class="ec24-head"><span class="ec24-overline">SEGURANÇA</span><h2>Confirme que é você</h2><p>Esta área contém informações protegidas. Confirme sua senha para continuar.</p></div>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="ec24-form">
        @csrf
        <label for="password">Senha atual</label>
        <input id="password" name="password" type="password" required autocomplete="current-password" autofocus />
        @error('password')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <button type="submit" class="ec24-primary" data-test="confirm-password-button">Confirmar senha <span>→</span></button>
    </form>
</x-layouts::auth>
