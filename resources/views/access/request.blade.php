<x-layouts::auth :title="'Solicitar acesso'">
    <div class="ec24-head"><span class="ec24-overline">PRIMEIRO ACESSO</span><h2>Solicitar acesso</h2><p>Preencha os dados para solicitar acesso ao ExportControl Prospector. A conta não será criada antes da aprovação.</p></div>
    @if(session('access_message'))<div role="status" class="ec24-notice ec24-notice-success">{{ session('access_message') }}</div>@endif
    <form method="POST" action="{{ route('access.request.store') }}" class="ec24-form">
        @csrf
        <div class="ec24-honeypot" aria-hidden="true"><label for="website">Deixe vazio</label><input id="website" name="website" tabindex="-1" autocomplete="off" /></div>
        <label for="name">Nome completo *</label>
        <input name="name" id="name" type="text" required maxlength="160" value="{{ old('name') }}" autocomplete="name" />
        @error('name')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <label for="email">E-mail corporativo *</label>
        <input name="email" id="email" type="email" required maxlength="254" value="{{ old('email') }}" autocomplete="email" />
        @error('email')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <label for="company">Empresa *</label>
        <input name="company" id="company" type="text" required maxlength="180" value="{{ old('company') }}" autocomplete="organization" />
        @error('company')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <div class="ec24-two-col"><div><label for="phone">Telefone</label><input name="phone" id="phone" type="tel" maxlength="40" value="{{ old('phone') }}" autocomplete="tel" />@error('phone')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror</div><div><label for="job_title">Cargo / função</label><input name="job_title" id="job_title" type="text" maxlength="120" value="{{ old('job_title') }}" autocomplete="organization-title" />@error('job_title')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror</div></div>
        <label class="ec24-checkbox ec24-consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent')) /><span>Autorizo o uso destes dados exclusivamente para analisar minha solicitação de acesso.</span></label>
        @error('consent')<p class="ec24-error" role="alert">{{ $message }}</p>@enderror
        <button type="submit" class="ec24-primary">Enviar solicitação <span>→</span></button>
    </form>
    <div class="ec24-divider"><span>ou</span></div>
    <a class="ec24-secondary" href="{{ route('login') }}">Já tenho conta · Entrar</a>
    <p class="ec24-info-box">A solicitação ficará registrada para análise do responsável. O sistema tentará avisar <strong>adolfo@exportcontrol.com.br</strong> por e-mail. O recebimento depende do SMTP configurado. Caso aprovada, você receberá um convite individual para definir sua senha.</p>
</x-layouts::auth>
