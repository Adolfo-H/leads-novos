<x-layouts::app :title="'Homologação de E-mails'">
<style>
.ecp8{max-width:1290px;margin:0 auto;padding:24px 18px;color:#d9edf9}.ecp8 a{color:#53e0d0}.ecp8 h1{font-size:clamp(26px,3vw,36px);font-weight:750;margin:10px 0}.ecp8 h2{font-weight:700;font-size:18px;margin:0 0 14px}.ecp8 p{color:#aec5d3;line-height:1.5}.ecp8 .eyebrow{letter-spacing:.13em;font-size:11px;font-weight:700;color:#51cfc6}.ecp8 .grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:22px 0}.ecp8 .card{background:#092943;border:1px solid #215071;border-radius:16px;padding:20px;min-width:0}.ecp8 .card small{color:#a6bfd0}.ecp8 .value{font-weight:780;font-size:28px;line-height:1.5}.ecp8 .two{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:16px}.ecp8 label{display:block;font-size:13px;font-weight:650;margin:12px 0 6px}.ecp8 input:not([type=checkbox]),.ecp8 textarea{width:100%;background:#051d33;border:1px solid #315775;border-radius:9px;padding:12px;color:white}.ecp8 textarea{min-height:88px}.ecp8 input[type=checkbox]{margin-right:8px;accent-color:#30d1bc}.ecp8 .check{font-weight:400;margin:12px 0}.ecp8 .primary{background:#35d4c6;color:#022a36;border:0;border-radius:10px;padding:13px 17px;font-weight:800;margin-top:10px;cursor:pointer}.ecp8 .ok{color:#6fedc6}.ecp8 .warn{color:#ffd28b}.ecp8 .item{padding:11px 0;border-bottom:1px solid #224660}.ecp8 .muted{font-size:12px;color:#a5bccc}.ecp8 .alert{border:1px solid #36627b;background:#103b55;padding:12px 16px;border-radius:10px;margin-top:16px}.ecp8 .error{color:#ffb3b3}@media(max-width:820px){.ecp8 .two{grid-template-columns:1fr}.ecp8 .grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.ecp8 .grid{grid-template-columns:1fr}}
</style>
<div class="ecp8">
  <span class="eyebrow">PROSPECTOR / CAMPANHAS / SEGURANÇA</span>
  <h1>Homologação de envios</h1>
  <p>Registre as evidências exigidas para operação comercial. Esta tela <strong>não envia mensagens, não altera o ritmo ativo e não libera o envio comercial</strong>.</p>
  @include('campaigns.module-nav')
  @if(session('campaign_release_saved'))<div class="alert ok" role="status">{{ session('campaign_release_saved') }}</div>@endif
  @if($errors->any())<div class="alert error" role="alert">{{ $errors->first() }}</div>@endif
  <div class="grid">
    <div class="card"><small>Envios comerciais</small><div class="value {{ $report['live'] ? 'warn' : 'ok' }}">{{ $report['live'] ? 'ATIVOS' : 'BLOQUEADOS' }}</div><p>Chave CAMPAIGN_MAIL_LIVE_ENABLED</p></div>
    <div class="card"><small>URL pública</small><div class="value {{ $report['public_url'] ? 'ok' : 'warn' }}">{{ $report['public_url'] ? 'HTTPS' : 'PENDENTE' }}</div><p style="overflow-wrap:anywhere">{{ $report['public_url'] ?? 'Configure acesso seguro e estável.' }}</p></div>
    <div class="card"><small>Bounces / endereços inválidos</small><div class="value">{{ $report['bounces'] }} / {{ $report['hard_bounces'] }}</div><p>Relatórios DSN efetivamente registrados.</p></div>
  </div>
  <div class="two">
    <section class="card">
      <h2>Checklist de homologação</h2>
      <p class="muted">O preenchimento registra uma declaração do responsável. Não confirma automaticamente quota, entregabilidade nem disponibilidade 24/7.</p>
      <form method="POST" action="{{ route('campaigns.production.save') }}">
        @csrf
        <div class="grid" style="grid-template-columns:1fr 1fr;margin:0">
          <div><label for="smtp_hour">Quota autorizada por hora</label><input id="smtp_hour" name="smtp_per_hour" type="number" min="1" max="100" required value="{{ old('smtp_per_hour', $report['record']->smtp_per_hour ?? 25) }}"></div>
          <div><label for="smtp_day">Quota autorizada por 24h</label><input id="smtp_day" name="smtp_per_day" type="number" min="1" max="500" required value="{{ old('smtp_per_day', $report['record']->smtp_per_day ?? 25) }}"></div>
        </div>
        <label for="evidence">Evidência/autorização do provedor SMTP</label>
        <textarea id="evidence" name="smtp_evidence" maxlength="500" required placeholder="Ex.: chamado com administrador e limites informados. Não cole senhas nem tokens.">{{ old('smtp_evidence', $report['record']->smtp_evidence ?? '') }}</textarea>
        @foreach(['smtp_confirmed'=>'Obtive confirmação explícita do administrador do SMTP','public_tested'=>'Testei o descadastro externo e verifiquei acesso estável','audience_reviewed'=>'Revisei base legal, segmentação e lista de supressão','bounce_reviewed'=>'Revisei bounces e falhas sem reenvio automático'] as $key => $label)
          <label class="check"><input type="checkbox" name="{{ $key }}" value="1" {{ old($key, $report['record']->$key ?? false) ? 'checked' : '' }}>{{ $label }}</label>
        @endforeach
        <button class="primary" type="submit">Salvar evidências (sem ativar envios)</button>
      </form>
    </section>
    <aside class="card">
      <h2>Controles efetivos</h2>
      <div class="item">Limite por hora: <strong>{{ $report['limits']['hour'] }}</strong></div>
      <div class="item">Limite por 24h: <strong>{{ $report['limits']['day'] }}</strong></div>
      <div class="item">Limite por minuto: <strong>{{ $report['limits']['tick'] }}</strong></div>
      <div class="item">Origem: <strong>{{ $report['limits']['source'] }}</strong></div>
      <div class="item">Quota declarada compatível: <strong class="{{ $report['quota_compatible'] ? 'ok' : 'warn' }}">{{ $report['quota_compatible'] ? 'Sim' : 'Não / não confirmada' }}</strong></div>
      <div class="item">APP_DEBUG: <strong class="{{ $report['debug'] ? 'warn' : 'ok' }}">{{ $report['debug'] ? 'Ativo — corrigir' : 'Desativado' }}</strong></div>
      <div class="alert"><strong>{{ $report['status'] }}</strong><p>Mesmo com checklist completo, só autorize disparos em ambiente operacional estável, com público legítimo e limites SMTP comprovados. Esta tela não modifica o envio.</p></div>
      <p><a href="{{ route('campaigns.queue') }}">Ver fila e devoluções →</a></p>
    </aside>
  </div>
</div>
</x-layouts::app>
