<x-layouts::auth :title="'Autenticação em duas etapas'">
    <div class="ec24-head"><span class="ec24-overline">SEGURANÇA</span><h2>Digite seu código de segurança</h2><p>Informe o código gerado pelo aplicativo autenticador para concluir seu login.</p></div>
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="ec24-form">
        @csrf
        <label for="code">Código de 6 dígitos</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" autofocus required />
        @error('code')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <button type="submit" class="ec24-primary">Verificar código <span>→</span></button>
    </form>
    <details class="ec24-recovery">
        <summary>Usar um código de recuperação</summary>
        <p>Caso não consiga acessar seu autenticador, informe um dos códigos de recuperação salvos anteriormente.</p>
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="ec24-form">@csrf
            <label for="recovery_code">Código de recuperação</label>
            <input id="recovery_code" name="recovery_code" type="text" autocomplete="one-time-code" required />
            @error('recovery_code')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="ec24-primary">Continuar com recuperação <span>→</span></button>
        </form>
    </details>
</x-layouts::auth>
