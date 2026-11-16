{{-- V66 — progresso REAL da operação HubSpot. O estado vem do Volt, nunca de dados demonstrativos. --}}
@if (($e53Opportunity['visible'] ?? false) || $this->showHubSpotOpportunityProgress)
    <article class="e53-panel e53-mini" data-ec-hubspot-opportunity="v66"
        @if ($e53Opportunity['active']) wire:poll.2s="refreshHubSpotOpportunity" @endif>
        <h2>Integração HubSpot</h2>
        <div class="e53-fields-grid">
            <div class="e53-field">
                <span>Situação da operação</span>
                <strong>{{ $e53Opportunity['label'] }}</strong>
                <p>{{ $e53Opportunity['message'] }}</p>
            </div>
            @if ($e53Opportunity['owner_email'])
                <div class="e53-field"><span>Iniciada por</span><strong>{{ $e53Opportunity['owner_email'] }}</strong></div>
            @endif
        </div>
        {{-- Links externos reais. URLs construídas pelo Volt usando IDs já persistidos e portal configurado. --}}
        @if ($this->hubSpotCompanyUrl() || $this->hubSpotDealUrl())
            <div class="e53-hero-actions" aria-label="Abrir registros no HubSpot">
                @if ($this->hubSpotCompanyUrl())
                    <a href="{{ $this->hubSpotCompanyUrl() }}" target="_blank"
                        rel="noopener noreferrer" class="e53-button e53-button--outline">Abrir empresa</a>
                @endif
                @if ($this->hubSpotDealUrl())
                    <a href="{{ $this->hubSpotDealUrl() }}" target="_blank"
                        rel="noopener noreferrer" class="e53-button e53-button--outline">Abrir negócio</a>
                @endif
            </div>
        @endif

        @if ($e53Opportunity['error'])
            <p role="alert" class="e53-notice e53-notice--amber">{{ $e53Opportunity['error'] }}</p>
        @endif
        @error('hubSpotOpportunity')
            <p role="alert" class="e53-notice e53-notice--amber">{{ $message }}</p>
        @enderror

        @if ($this->showHubSpotOpportunityProgress)
            <section aria-label="Andamento da criação no HubSpot" data-testid="hubspot-progress-v66">
                <h3>Criando oportunidade no HubSpot</h3>
                <strong>{{ (int) $e53Opportunity['progress'] }}%</strong>
                <progress max="100" value="{{ (int) $e53Opportunity['progress'] }}" style="display:block;width:100%;accent-color:#078578"></progress>
                @if ($e53Opportunity['progress_message'])
                    <p role="status">{{ $e53Opportunity['progress_message'] }}</p>
                @endif
                <ol class="e53-fields-grid" aria-label="Etapas concluídas">
                    @foreach ([
                        5 => 'Enviado para fila',
                        15 => 'Responsável validado',
                        30 => 'Empresa criada',
                        50 => 'Contatos sincronizados',
                        70 => 'Negócio criado',
                        85 => 'Tarefa criada',
                        95 => 'Atualizando CRM',
                        100 => 'Concluir',
                    ] as $step => $description)
                        @if ((int) $e53Opportunity['progress'] >= $step)
                            <li>{{ $description }}</li>
                        @endif
                    @endforeach
                </ol>
                @unless($e53Opportunity['active'])
                    <button type="button" class="e53-button e53-button--outline" wire:click="closeHubSpotOpportunityProgress">Concluir acompanhamento</button>
                @endunless
            </section>
        @endif

        @can('createHubSpotOpportunity', $company)
            @if ($e53Opportunity['can_queue'])
                <button type="button" class="e53-button e53-button--primary"
                    wire:click="createHubSpotOpportunity" wire:loading.attr="disabled"
                    wire:target="createHubSpotOpportunity">
                    {{ in_array($e53Opportunity['status'], ['failed', 'partial'], true) ? 'Tentar novamente' : 'Criar oportunidade no HubSpot' }}
                </button>
            @endif
        @endcan
    </article>
@endif
