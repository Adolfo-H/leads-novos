{{-- CAMPANHAS_FIGMA_V71_M11: layout de homologação documental, sem alterar envio nem política. --}}
<div class="ecp8 ec71-page ec71-production" data-campaign-figma="M11-V71">
    <header class="ec71-hero" id="inicio">
        <span class="ec71-eyebrow">CAMPANHAS <span class="ec71-head-chip">Documental</span></span>
        <h1>Homologação de envios</h1>
        <p>Registre os limites informados pelo provedor e revise as condições para operar campanhas comerciais. Salvar este formulário não dispara mensagens.</p>
        <div class="ec71-actions">
            <button type="submit" form="ec71-readiness-form" class="ec71-btn ec71-btn-primary">Salvar evidências</button>
            <a href="{{ route('campaigns.queue') }}" class="ec71-btn ec71-btn-outline" wire:navigate>Ver fila e devoluções</a>
            <a href="{{ route('campaigns.index') }}" class="ec71-btn ec71-btn-outline" wire:navigate>Voltar às campanhas</a>
        </div>
    </header>

    @include('campaigns.module-nav')

    @if (session('campaign_release_saved'))
        <div class="ec71-notice ec71-notice-success" role="status">{{ session('campaign_release_saved') }}</div>
    @endif
    @if ($errors->any())
        <div class="ec71-notice ec71-notice-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="ec71-kpis" aria-label="Indicadores de homologação">
        <article class="ec71-kpi">
            <span>Limite efetivo / hora</span>
            <strong>{{ number_format((int) $report['limits']['hour'], 0, ',', '.') }}</strong>
            <small>{{ $report['limits']['source'] === 'prospector' ? 'Política do Prospector' : 'Configuração do ambiente' }}</small>
        </article>
        <article class="ec71-kpi">
            <span>Limite efetivo / minuto</span>
            <strong>{{ number_format((int) $report['limits']['tick'], 0, ',', '.') }}</strong>
            <small>Ritmo de processamento</small>
        </article>
        <article class="ec71-kpi">
            <span>Cota efetiva / 24h</span>
            <strong>{{ number_format((int) $report['limits']['day'], 0, ',', '.') }}</strong>
            <small>Janela móvel de 24 horas</small>
        </article>
        <article class="ec71-kpi">
            <span>APP_DEBUG</span>
            <strong class="{{ $report['debug'] ? 'ec71-text-warning' : '' }}">{{ $report['debug'] ? 'Ativo' : 'Desligado' }}</strong>
            <small>{{ $report['debug'] ? 'Desative em produção' : 'Ambiente sem debug' }}</small>
        </article>
    </section>

    <div class="ec71-notice {{ $report['live'] ? 'ec71-notice-success' : 'ec71-notice-warning' }}" role="status">
        <span class="ec71-notice-icon" aria-hidden="true">{{ $report['live'] ? '✓' : '!' }}</span>
        <div>
            <strong>{{ $report['live'] ? 'Envio comercial habilitado' : 'Envio comercial bloqueado' }}</strong>
            <p>{{ $report['live'] ? 'Os envios continuam sujeitos às cotas, ao descadastro e aos controles configurados.' : 'Este formulário registra evidências; a liberação do envio é feita separadamente.' }}</p>
        </div>
    </div>

    @if ($report['live'])
        <div class="ec71-notice ec71-notice-warning" role="note"><div><strong>Registro documental durante envios ativos</strong><p>Para modificar uma homologação já registrada, é necessário pausar o envio comercial primeiro. O sistema mantém essa proteção existente.</p></div></div>
    @endif

    <form id="ec71-readiness-form" method="POST" action="{{ route('campaigns.production.save') }}" class="ec71-form">
        @csrf
        <section class="ec71-card" aria-labelledby="ec71-capacity-title">
            <div class="ec71-card-header">
                <h2 id="ec71-capacity-title">Capacidade e autorização</h2>
                <p>Use apenas os limites confirmados pelo administrador do SMTP.</p>
            </div>
            <div class="ec71-grid-two">
                <label class="ec71-field" for="ec71-smtp-hour">
                    <span>Quota autorizada por hora</span>
                    <input id="ec71-smtp-hour" name="smtp_per_hour" type="number" min="1"
                           max="{{ max(1, (int) config('campaign_mail.smtp_approved_per_hour', 100)) }}" required
                           value="{{ old('smtp_per_hour', $report['record']->smtp_per_hour ?? 25) }}">
                    <small>Conforme capacidade autorizada do SMTP.</small>
                </label>
                <label class="ec71-field" for="ec71-smtp-day">
                    <span>Quota autorizada por 24h</span>
                    <input id="ec71-smtp-day" name="smtp_per_day" type="number" min="1"
                           max="{{ max(1, (int) config('campaign_mail.smtp_approved_per_day', 500)) }}" required
                           value="{{ old('smtp_per_day', $report['record']->smtp_per_day ?? 25) }}">
                    <small>Comparada à política efetiva do sistema.</small>
                </label>
            </div>
            <label class="ec71-field" for="ec71-smtp-evidence">
                <span>Evidência do provedor SMTP</span>
                <textarea id="ec71-smtp-evidence" name="smtp_evidence" maxlength="500" required
                          placeholder="Ex.: protocolo de suporte e limites informados pelo provedor. Não cole senhas ou tokens.">{{ old('smtp_evidence', $report['record']->smtp_evidence ?? '') }}</textarea>
                <small>Registre a autorização sem compartilhar credenciais.</small>
            </label>
            <label class="ec71-review-option" for="ec71-smtp-confirmed">
                <span><strong>Confirmação do administrador SMTP</strong><small>Declaração do responsável, não verificação automática.</small></span>
                <input id="ec71-smtp-confirmed" type="checkbox" name="smtp_confirmed" value="1"
                       @checked(old('smtp_confirmed', $report['record']->smtp_confirmed ?? false))>
            </label>
        </section>

        <section class="ec71-card" aria-labelledby="ec71-review-title">
            <div class="ec71-card-header">
                <h2 id="ec71-review-title">Revisão operacional</h2>
                <p>Revise o acesso público, o público-alvo e os bloqueios antes de utilizar o envio real.</p>
            </div>
            <div class="ec71-grid-two">
                <label class="ec71-review-option" for="ec71-public-tested">
                    <span><strong>Descadastro externo testado</strong><small>Acesso HTTPS estável, confirmado manualmente.</small></span>
                    <input id="ec71-public-tested" type="checkbox" name="public_tested" value="1"
                           @checked(old('public_tested', $report['record']->public_tested ?? false))>
                </label>
                <label class="ec71-review-option" for="ec71-audience-reviewed">
                    <span><strong>Base legal e supressão revisadas</strong><small>Elegibilidade dos destinatários verificada.</small></span>
                    <input id="ec71-audience-reviewed" type="checkbox" name="audience_reviewed" value="1"
                           @checked(old('audience_reviewed', $report['record']->audience_reviewed ?? false))>
                </label>
                <label class="ec71-review-option" for="ec71-bounce-reviewed">
                    <span><strong>Devoluções e falhas revisadas</strong><small>Sem reenvios automáticos de tentativas incertas.</small></span>
                    <input id="ec71-bounce-reviewed" type="checkbox" name="bounce_reviewed" value="1"
                           @checked(old('bounce_reviewed', $report['record']->bounce_reviewed ?? false))>
                </label>
                <div class="ec71-field ec71-readonly-field">
                    <span>URL pública HTTPS</span>
                    <div class="ec71-readonly-value">{{ $report['public_url'] ?? 'Não configurada' }}</div>
                    <small>Endereço real utilizado pelo descadastro.</small>
                </div>
            </div>
            <div class="ec71-inline-stats">
                <span>Quota declarada compatível: <strong>{{ $report['quota_compatible'] ? 'Sim' : 'Não / pendente' }}</strong></span>
                <span>Devoluções registradas: <strong>{{ number_format((int) $report['bounces'], 0, ',', '.') }}</strong></span>
                <span>Endereços inválidos: <strong>{{ number_format((int) $report['hard_bounces'], 0, ',', '.') }}</strong></span>
            </div>
        </section>
        <div class="ec71-form-footer">
            <div><strong>{{ $report['status'] }}</strong><p>O registro documental não altera a configuração de envio.</p></div>
            <button type="submit" class="ec71-btn ec71-btn-primary">Salvar evidências</button>
        </div>
    </form>
    <footer class="ec71-page-foot">ExportControl Prospector · Informações registradas no ambiente local</footer>
</div>
