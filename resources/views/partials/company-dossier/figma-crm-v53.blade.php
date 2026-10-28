{{-- E06 · Relacionamento no HubSpot. Somente vínculos fiscais confiáveis. --}}
<section class="e53-screen" x-show="dossierTab === 'commercial' && dossierView === 'crm'" x-cloak aria-label="Relacionamento no HubSpot">
    @php
        $e53PrimaryHub = $e53HubCompanies->first();
        $e53CrmReprospecting = $this->crmReprospecting;
        $e53Opportunity = $this->hubSpotManualOpportunityState;
    @endphp
    <article class="e53-panel e53-fields-panel">
        <h2>Identidade e origem do vínculo</h2>
        <div class="e53-fields-grid">
            <div class="e53-field"><span>Situação comercial</span><strong>{{ $e53Status }}</strong></div>
            <div class="e53-field"><span>Lifecycle HubSpot</span><strong>{{ $e53PrimaryHub?->lifecycle_stage ?: $e53Crm?->lifecycle_stage ?: 'Não informado' }}</strong></div>
            <div class="e53-field"><span>Empresa CRM</span><strong>{{ $e53PrimaryHub?->name ?: $e53Crm?->external_name ?: 'Sem vínculo confirmado' }}</strong></div>
            <div class="e53-field"><span>Domínio</span><strong>{{ $e53PrimaryHub?->domain ?: $e53Crm?->external_domain ?: 'Não informado' }}</strong></div>
            <div class="e53-field"><span>Como foi encontrada</span><strong>{{ match($e53PrimaryHub?->match_source ?: $e53Crm?->matched_by) {
                        'manual_verified', 'manual' => 'Vinculação manual confirmada',
                        'cnpj_exact', 'explicit_cnpj' => 'CNPJ confirmado',
                        'imported' => 'Importação HubSpot',
                        null, '' => 'Não informado',
                        default => \Illuminate\Support\Str::headline((string) ($e53PrimaryHub?->match_source ?: $e53Crm?->matched_by)),
                    } }}</strong></div>
            <div class="e53-field"><span>Última verificação</span><strong>{{ $e53Crm?->checked_at?->format('d/m/Y H:i') ?: 'Não informada' }}</strong></div>
        </div>
    </article>

    @include('partials.company-dossier.hubspot-opportunity-v66')

    <article class="e53-panel e53-table-panel">
        <h2>Negócios da empresa</h2>
        <div class="e53-table-wrap"><table class="e53-table">
            <thead><tr><th>Nome</th><th>Etapa</th><th>Estado</th><th>Última atividade</th></tr></thead>
            <tbody>
                @forelse ($e53Deals->take(20) as $deal)
                    <tr>
                        <td><strong>{{ $deal->name ?: $deal->hubspot_id }}</strong></td>
                        <td>{{ $deal->stage_label ?: 'Não informada' }}</td>
                        <td>{{ $deal->is_closed ? ($deal->is_closed_won ? 'Fechado ganho' : 'Encerrado') : 'Em andamento' }}</td>
                        <td>{{ $deal->last_activity_at?->format('d/m/Y H:i') ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="e53-empty" colspan="4">Nenhum negócio relacionado à empresa no espelho local.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        <p class="e53-caption">Negócios vinculados comercialmente, sem assumir que empresas secundárias compartilham o CNPJ.</p>
        @if ($e53Deals->count()>20)<p class="e53-caption">Exibindo os 20 primeiros negócios de {{ $e53Deals->count() }}.</p>@endif
    </article>

    <h2 class="e53-section-title">Reprospecção e histórico</h2>
    <div class="e53-grid-2">
        <article class="e53-panel e53-mini">
            <span class="e53-chip {{ ($e53CrmReprospecting['eligible'] ?? false) ? 'e53-chip--green' : 'e53-chip--amber' }}">
                {{ ($e53CrmReprospecting['eligible'] ?? false) ? 'Elegível' : 'Análise necessária' }}
            </span>
            <h3>{{ ($e53CrmReprospecting['eligible'] ?? false) ? 'Retomada liberada' : 'Verificação de retomada' }}</h3>
            <p>{{ $e53CrmReprospecting['message'] ?? 'A elegibilidade depende da atividade comercial, do histórico e das regras atuais do HubSpot.' }}</p>
            @if (!empty($e53CrmReprospecting['next_allowed_at']))<p>Próxima janela: {{ $this->formatReprospectingDate($e53CrmReprospecting['next_allowed_at']) }}</p>@endif
        </article>
        <article class="e53-panel e53-mini"><span class="e53-chip e53-chip--green">Histórico preservado</span><h3>Último contato</h3>
            <p>{{ $e53LastActivity?->format('d/m/Y H:i') ?: 'Sem data confiável' }} · fonte: espelho local HubSpot/Prospector</p>
            <button class="e53-text-link" x-on:click="dossierView='history'" type="button">Abrir histórico <span aria-hidden="true">→</span></button>
        </article>
    </div>

    <div class="e53-notice e53-notice--amber">
        <strong>Criação externa explícita</strong>
        <p>Esta ação cria ou reaproveita entidades e associações no HubSpot conforme a elegibilidade vigente. O resultado só é confirmado após o processamento.</p>
        <p>Estado atual: {{ $e53Opportunity['message'] ?? 'Aguardando solicitação.' }}</p>
    </div>
    @if ($e53HubCompanies->isNotEmpty())
    <article class="e53-panel e53-table-panel">
        <h2>Contatos vinculados no HubSpot</h2>
        <div class="e53-table-wrap"><table class="e53-table"><thead><tr><th>Contato</th><th>Cargo</th><th>E-mail</th><th>Telefone</th></tr></thead><tbody>
            @forelse ($e53HubCompanies->flatMap(fn($h)=>$h->contacts)->unique('hubspot_id')->take(30) as $contact)
                <tr><td><strong>{{ trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: 'Contato sem nome' }}</strong></td><td>{{ $contact->job_title ?: '—' }}</td><td>{{ $contact->email ?: '—' }}</td><td>{{ $contact->mobile_phone ?: $contact->phone ?: '—' }}</td></tr>
            @empty <tr><td colspan="4" class="e53-empty">Nenhum contato associado no espelho local.</td></tr>
            @endforelse
        </tbody></table></div>
    </article>
    @endif
</section>
