{{-- E08 · Empresa & grupo. As contagens e unidades são reais. --}}
<section class="e53-screen" x-show="dossierTab === 'company'" x-cloak aria-label="Empresa e grupo">
    <div class="e53-metrics e53-metrics--four e53-metrics--group"
        data-operational-total="{{ $e53Op['total'] ?? 0 }}"
        data-operational-active="{{ $e53Op['active'] ?? 0 }}"
        data-operational-states="{{ $e53Op['active_states_count'] ?? 0 }}"
        data-operational-cities="{{ $e53Op['active_municipalities_count'] ?? 0 }}">
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
        @if ($company->establishments->count() > 20) Exibindo as primeiras 20 de {{ $company->establishments->count() }} unidades nesta visão resumida. @endif</p>
    </article>

    {{-- DOSSIE_UI_HUBSPOT_V591: canais visíveis, expansíveis e sem barra de rolagem interna --}}
    <h2 class="e53-section-title">Canais do grupo</h2>
    <div class="e53-grid-2 e591-channel-grid">
        <article class="e53-panel e53-mini e591-channel-card e591-channel-card--email" x-data="{showAll: false}">
            <div class="e591-channel-heading">
                <div class="e591-channel-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg></div>
                <div><h3>E-mails cadastrais</h3><p>Endereços únicos de todas as unidades cadastradas</p></div>
                <span class="e591-channel-count">{{ $e53EmailItems->count() }} e-mail(s) único(s)</span>
            </div>
            <div class="e591-channel-list">
                @forelse ($e53EmailItems->take(4) as $email)
                    <a class="e591-channel-item" href="mailto:{{ $email['value'] ?? '' }}" title="Enviar e-mail">{{ $email['value'] ?? '—' }}</a>
                @empty
                    <p class="e591-channel-empty">Não há e-mails cadastrais disponíveis.</p>
                @endforelse
                @if ($e53EmailItems->count() > 4)
                    <div class="e591-channel-extra e53-contact-extra" x-show="showAll" x-cloak>
                        @foreach ($e53EmailItems->slice(4) as $email)
                            <a class="e591-channel-item" href="mailto:{{ $email['value'] ?? '' }}" title="Enviar e-mail">{{ $email['value'] ?? '—' }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
            @if ($e53EmailItems->count() > 4)
                <button class="e591-channel-toggle" type="button" x-on:click="showAll = !showAll" x-bind:aria-showAll="showAll ? 'true' : 'false'">
                    <span x-text="showAll ? 'Mostrar menos' : 'Ver todos ({{ $e53EmailItems->count() }})'">Ver todos ({{ $e53EmailItems->count() }})</span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" x-bind:style="showAll ? 'transform:rotate(180deg)' : ''"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            @endif
        </article>
        <article class="e53-panel e53-mini e591-channel-card e591-channel-card--phone" x-data="{showAll: false}">
            <div class="e591-channel-heading">
                <div class="e591-channel-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.7 19.7 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 1.8L7 10.2a16 16 0 0 0 6.8 6.8l1.7-2a2 2 0 0 1 1.8-.6l3 .5a2 2 0 0 1 1.7 2Z"/></svg></div>
                <div><h3>Telefones cadastrados</h3><p>Contatos telefônicos consolidados das unidades</p></div>
                <span class="e591-channel-count">{{ $e53PhoneItems->count() }} telefone(s) único(s)</span>
            </div>
            <div class="e591-channel-list">
                @forelse ($e53PhoneItems->take(4) as $phone)
                    @if (filled($phone['href'] ?? null))
                        <a class="e591-channel-item" href="{{ $phone['href'] }}" title="Ligar para o número">{{ $phone['value'] ?? $phone['raw'] ?? '—' }}</a>
                    @else
                        <span class="e591-channel-item">{{ $phone['value'] ?? $phone['raw'] ?? '—' }}</span>
                    @endif
                @empty
                    <p class="e591-channel-empty">Não há telefones cadastrais disponíveis.</p>
                @endforelse
                @if ($e53PhoneItems->count() > 4)
                    <div class="e591-channel-extra e53-contact-extra" x-show="showAll" x-cloak>
                        @foreach ($e53PhoneItems->slice(4) as $phone)
                            @if (filled($phone['href'] ?? null))
                                <a class="e591-channel-item" href="{{ $phone['href'] }}" title="Ligar para o número">{{ $phone['value'] ?? $phone['raw'] ?? '—' }}</a>
                            @else
                                <span class="e591-channel-item">{{ $phone['value'] ?? $phone['raw'] ?? '—' }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
            @if ($e53PhoneItems->count() > 4)
                <button class="e591-channel-toggle" type="button" x-on:click="showAll = !showAll" x-bind:aria-showAll="showAll ? 'true' : 'false'">
                    <span x-text="showAll ? 'Mostrar menos' : 'Ver todos ({{ $e53PhoneItems->count() }})'">Ver todos ({{ $e53PhoneItems->count() }})</span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" x-bind:style="showAll ? 'transform:rotate(180deg)' : ''"><path d="m6 9 6 6 6-6"/></svg>
                </button>
            @endif
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
