<x-layouts::auth :title="'Ativar acesso'">
    @if($record === null)
        <div class="ec24-head"><span class="ec24-overline">CONVITE</span><h2>Este link não está disponível</h2><p>O convite expirou, já foi utilizado ou foi revogado. Solicite outro convite ao responsável.</p></div>
        <a class="ec24-primary" href="{{ route('access.request.create') }}">Voltar à solicitação de acesso <span>→</span></a>
    @else
        <div class="ec24-head"><span class="ec24-overline">ACESSO APROVADO</span><h2>Ative sua conta</h2><p>Olá, {{ $record->name }}! Defina sua senha para acessar o Prospector com <strong>{{ $record->email }}</strong>.</p></div>
        <form method="POST" action="{{ route('access.invitation.activate', ['token' => $token]) }}" class="ec24-form">
            @csrf
            <label for="password">Nova senha</label>
            <input name="password" id="password" type="password" required minlength="8" autocomplete="new-password" />
            @error('password')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
            <label for="password_confirmation">Confirme a senha</label>
            <input name="password_confirmation" id="password_confirmation" type="password" required minlength="8" autocomplete="new-password" />
            @error('invitation')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
            <button type="submit" class="ec24-primary">Ativar minha conta <span>→</span></button>
        </form>
        <p class="ec24-info-box">Convite individual, de uso único. Após ativar sua conta, entre com e-mail e senha.</p>
    @endif
</x-layouts::auth>
