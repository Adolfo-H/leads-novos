{{-- E08 · Empresa & grupo. As contagens e unidades são reais. --}}
<section class="e53-screen" x-show="dossierTab === 'company'" x-cloak aria-label="Empresa e grupo">
    <div class="e53-metrics e53-metrics--four e53-metrics--group">
        <div class="e53-metric"><span>Estabelecimentos</span><strong>{{ $e53Op['total'] ?? 0 }}</strong></div>
        <div class="e53-metric"><span>Ativos</span><strong>{{ $e53Op['active'] ?? 0 }}</strong></div>
        <div class="e53-metric"><span>Estados</span><strong>{{ $e53Op['active_states_count'] ?? 0 }}</strong></div>
        <div class="e53-metric"><span>Municípios</span><strong>{{ $e53Op['active_municipalities_count'] ?? 0 }}</strong></div>
    </div>

    <article class="e53-panel e53-fields-panel">
        <h2>Identificação da empresa</h2>
        <div class="e53-fields-grid">
            <div class="e53-field"><span>Raiz CNPJ</span><strong>{{ $company->cnpj_root }}</strong></div>
            <div class="e53-field"><span>Razão social</span><strong>{{ $company->corporate_name }}</strong></div>
            <div class="e53-field"><span>Capital social</span><strong>{{ $this->formatMoney($company->share_capital) }}</strong></div>
            <div class="e53-field"><span>Porte</span><strong>{{ trim(($company->size_code ?: '').' '.($company->size_description ?: '')) ?: 'Não informado' }}</strong></div>
            <div class="e53-field"><span>Natureza jurídica</span><strong>{{ trim(($company->legal_nature_code ?: '').' '.($company->legal_nature_description ?: '')) ?: 'Não informada' }}</strong></div>
            <div class="e53-field"><span>Fonte / atualização</span><strong>{{ $company->source ?: 'Base local' }} · {{ $company->source_updated_at?->format('d/m/Y') ?: 'Data não informada' }}</strong></div>
        </div>
    </article>

    <article class="e53-panel e53-table-panel">
        <div class="e53-panel-heading"><h2>Estabelecimentos</h2><span class="e53-count-badge">{{ $company->establishments->count() }}</span></div>
        <div class="e53-table-wrap"><table class="e53-table"><thead><tr><th>Unidade</th><th>CNPJ</th><th>Localização</th><th>Situação</th><th>Canais</th></tr></thead><tbody>
            @forelse ($company->establishments->take(20) as $unit)
                <tr>
                    <td><button class="e53-table-link" type="button" x-on:click="e53SelectedUnit={{ (int) $unit->id }}">{{ $unit->type === 'matrix' ? 'Matriz' : 'Filial' }} · {{ $unit->fantasy_name ?: $unit->municipality_name ?: 'Unidade '.($unit->order_number ?: '') }}</button></td>
                    <td>{{ \App\Support\Cnpj::format($unit->cnpj) }}</td>
                    <td>{{ $unit->municipality_name ?: 'Não informado' }}@if($unit->state) · {{ $unit->state }}@endif</td>
                    <td>{{ $unit->registration_status ?: 'Não informada' }}</td>
                    <td>{{ collect([$unit->email ? 'E-mail' : null,($unit->phone_1 || $unit->phone_2) ? 'Telefone' : null])->filter()->implode(' · ') ?: 'Não informado' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="e53-empty">Ainda não existem estabelecimentos cadastrados.</td></tr>
            @endforelse
        </tbody></table></div>
        <p class="e53-caption">Selecione uma unidade para abrir seus dados cadastrais detalhados.
        @if ($company->establishments->count() > 20) Exibindo as primeiras 20 de {{ $company->establishments->count() }} unidades; a relação completa permanece em Funcionalidades avançadas. @endif</p>
    </article>

    <h2 class="e53-section-title">Canais do grupo</h2>
    <div class="e53-grid-2">
        <article class="e53-panel e53-mini"><span class="e53-chip e53-chip--green">{{ $e53EmailItems->count() }} e-mail(s) único(s)</span>
            <h3>E-mails cadastrais</h3>
            @forelse ($e53EmailItems->take(4) as $email)
                <p class="e53-line">{{ $email['value'] ?? '—' }}</p>
            @empty <p>Não há e-mails cadastrais na base local.</p> @endforelse
            <button class="e53-text-link" type="button" x-on:click="showAdvancedDossier=true; $nextTick(()=>document.querySelector('.e53-legacy')?.scrollIntoView({behavior:'smooth'}))">Ver detalhes <span aria-hidden="true">→</span></button>
        </article>
        <article class="e53-panel e53-mini"><span class="e53-chip e53-chip--green">Origem cadastral</span>
            <h3>Telefones cadastrados</h3>
            @forelse ($e53PhoneItems->take(4) as $phone)
                <p class="e53-line">{{ $phone['value'] ?? $phone['raw'] ?? '—' }}</p>
            @empty <p>Não há telefones cadastrados na base local.</p> @endforelse
            <button class="e53-text-link" type="button" x-on:click="showAdvancedDossier=true; $nextTick(()=>document.querySelector('.e53-legacy')?.scrollIntoView({behavior:'smooth'}))">Ver detalhes <span aria-hidden="true">→</span></button>
        </article>
    </div>

    <article class="e53-panel e53-table-panel">
        <h2>CNAEs consolidados do grupo</h2>
        <div class="e53-table-wrap"><table class="e53-table"><thead><tr><th>Código / atividade</th><th>Unidades</th><th>Ativas</th><th>Principal</th><th>Localização</th></tr></thead><tbody>
            @forelse ($e53Cnaes as $item)
                <tr><td><strong>{{ $item['code'] }} · {{ $item['description'] ?: 'Sem descrição' }}</strong></td>
                    <td>{{ $item['units_count'] ?? count($item['units'] ?? []) }}</td>
                    <td>{{ $item['active_units_count'] ?? count($item['active_units'] ?? []) }}</td>
                    <td>{{ $item['primary_units_count'] ?? count($item['primary_units'] ?? []) }}</td>
                    <td>{{ collect($item['locations'] ?? [])->take(2)->implode(' · ') ?: '—' }}</td>
                </tr>
            @empty <tr><td colspan="5" class="e53-empty">Nenhum CNAE vinculado ao grupo.</td></tr> @endforelse
        </tbody></table></div>
    </article>
</section>
