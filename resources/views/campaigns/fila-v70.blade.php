{{-- CAMPANHAS_FILA_V70: os dados vêm somente dos métodos existentes no componente Livewire. --}}
<div class="ecm6-page ecm7-page ec21-page ec22-page ec70-queue" data-ec21-screen="queue" data-campaign-queue="M10-V70" wire:poll.60s>
    <header class="ec70-queue-header">
        <div>
            <span class="ec70-eyebrow">CAMPANHAS / ENVIO</span>
            <h1>Envios e fila</h1>
            <p>Acompanhe seus e-mails e simule a capacidade de envio de forma clara.</p>
        </div>
        <div class="ec70-header-actions">
            <span class="ec70-queue-status {{ $this->health['safe_mode'] ? 'is-safe' : 'is-active' }}">
                <span aria-hidden="true"></span>{{ $this->health['safe_mode'] ? 'Envios comerciais bloqueados' : 'Envios habilitados' }}
            </span>
            <button type="button" class="ec70-btn ec70-btn-secondary" wire:click="refreshQueue" wire:loading.attr="disabled" wire:target="refreshQueue">
                <span aria-hidden="true">↻</span> Atualizar
            </button>
        </div>
    </header>

    @include('campaigns.module-nav')

    <div class="ec70-section-heading">
        <div>
            <h2>Visão geral dos envios</h2>
            <p>Situações reais dos registros no Prospector. Atualização periódica enquanto a página estiver aberta.</p>
        </div>
    </div>
    <section class="ec70-queue-metrics" aria-label="Situação dos e-mails comerciais">
        @foreach ([
            ['Aguardando', 'pending', 'Aguardando liberação'],
            ['Reservados', 'reserved', 'Prontos para a fila'],
            ['Em processamento', 'sending', 'Tentativas iniciadas'],
            ['Aceitos pelo SMTP', 'sent', 'O SMTP aceitou a mensagem'],
            ['Exigem atenção', 'uncertain', 'Resultado ainda não confirmado'],
        ] as [$caption, $key, $description])
            <div class="ec70-kpi {{ $key === 'uncertain' ? 'is-caution' : '' }}" wire:key="ec70-queue-stat-{{ $key }}">
                <span>{{ $caption }}</span>
                <strong>{{ number_format((int) ($this->health[$key] ?? 0), 0, ',', '.') }}</strong>
                <small>{{ $description }}</small>
            </div>
        @endforeach
    </section>

    <div class="ec70-queue-layout">
        <section class="ec70-card ec70-simulator" aria-labelledby="ec70-simulator-title">
            <header class="ec70-card-header">
                <div>
                    <span class="ec70-eyebrow">SIMULAÇÃO · NENHUM ENVIO REAL</span>
                    <h2 id="ec70-simulator-title">Quanto tempo vai levar?</h2>
                    <p>Simule uma quantidade de contatos usando os limites atualmente configurados.</p>
                </div>
                <span class="ec70-tag">Sem envio</span>
            </header>
            <div class="ec70-card-content">
                <div class="ec70-field-label">Escolha uma quantidade</div>
                <div class="ec70-presets" role="group" aria-label="Volumes sugeridos">
                    @foreach ([10, 50, 100, 500] as $size)
                        <button type="button" class="ec70-preset {{ $scenario === $size ? 'is-active' : '' }}" wire:click="selectScenario({{ $size }})" aria-pressed="{{ $scenario === $size ? 'true' : 'false' }}">{{ number_format($size, 0, ',', '.') }}</button>
                    @endforeach
                </div>
                <div class="ec70-custom-row">
                    <label class="ec70-custom-field">
                        <span>Ou informe sua quantidade</span>
                        <input type="number" min="1" max="100000" step="1" wire:model="customScenario" inputmode="numeric" aria-label="Quantidade personalizada de contatos">
                    </label>
                    <button type="button" class="ec70-btn ec70-btn-primary" wire:click="simulateCustom" wire:loading.attr="disabled" wire:target="simulateCustom">Simular quantidade</button>
                </div>
                @error('customScenario')<p class="ec70-validation-error" role="alert">{{ $message }}</p>@enderror
                <p class="ec70-form-hint">Simulação atual: <strong>{{ number_format((int) $scenario, 0, ',', '.') }} contatos</strong>. Não cria campanhas nem reserva mensagens.</p>

                <div class="ec70-sim-results">
                    <div><span>Na primeira hora</span><strong>{{ number_format((int) $this->simulation['first_hour'], 0, ',', '.') }}</strong><small>Mensagens simuladas</small></div>
                    <div><span>Nas primeiras 24 horas</span><strong>{{ number_format((int) $this->simulation['first_24_hours'], 0, ',', '.') }}</strong><small>Janela móvel</small></div>
                    <div><span>Em até sete dias</span><strong>{{ number_format((int) $this->simulation['within_seven_days'], 0, ',', '.') }}</strong><small>De {{ number_format((int) $scenario, 0, ',', '.') }}</small></div>
                </div>
                @php
                    $ec70SimProgress = min(100, max(0, (int) round($this->simulation['within_seven_days'] * 100 / max(1, $scenario))));
                @endphp
                <div class="ec70-progress-label"><span>Progresso estimado em sete dias</span><strong>{{ $ec70SimProgress }}%</strong></div>
                <div class="ec70-progress" role="progressbar" aria-label="Progresso simulado em sete dias" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $ec70SimProgress }}">
                    <span style="width:{{ $ec70SimProgress }}%"></span>
                </div>
                <p class="ec70-note">A previsão não considera possíveis rejeições do SMTP ou atrasos dos servidores. Aceitação SMTP não comprova entrega ao destinatário.</p>
            </div>
        </section>

        <aside class="ec70-aside">
            <section class="ec70-card" aria-labelledby="ec70-rhythm-title">
                <header class="ec70-card-header"><div><h2 id="ec70-rhythm-title">Limites configurados</h2><p>Os valores escolhidos para o ritmo de envio.</p></div></header>
                <dl class="ec70-rate-list">
                    <div><dt>Por hora</dt><dd>{{ number_format((int) $this->effective['hour'], 0, ',', '.') }}</dd></div>
                    <div><dt>Por 24 horas</dt><dd>{{ number_format((int) $this->effective['day'], 0, ',', '.') }}</dd></div>
                    <div><dt>Por minuto</dt><dd>{{ number_format((int) $this->effective['tick'], 0, ',', '.') }}</dd></div>
                </dl>
                <div class="ec70-card-footer">
                    <a class="ec70-btn ec70-btn-primary" href="{{ route('campaigns.settings') }}" wire:navigate>Ajustar meus limites →</a>
                </div>
            </section>
            <section class="ec70-card" aria-labelledby="ec70-attention-title">
                <header class="ec70-card-header"><div><h2 id="ec70-attention-title">Precisa de atenção</h2><p>Itens que merecem acompanhamento.</p></div></header>
                <dl class="ec70-rate-list ec70-attention-list">
                    <div><dt>Reservas antigas</dt><dd>{{ number_format((int) $this->health['stale_reserved'], 0, ',', '.') }}</dd></div>
                    <div><dt>Envios sem confirmação</dt><dd>{{ number_format((int) $this->health['stale_sending'], 0, ',', '.') }}</dd></div>
                    <div><dt>Descadastros e bloqueios</dt><dd>{{ number_format((int) $this->health['suppressed'], 0, ',', '.') }}</dd></div>
                    <div><dt>Cancelados</dt><dd>{{ number_format((int) $this->health['canceled'], 0, ',', '.') }}</dd></div>
                </dl>
                <p class="ec70-note">Mensagens com resultado incerto não são reenviadas automaticamente para evitar duplicidade.</p>
            </section>
        </aside>
    </div>

    <section class="ec70-card ec70-bounces" aria-labelledby="ec70-bounces-title">
        <header class="ec70-card-header">
            <div><h2 id="ec70-bounces-title">E-mails devolvidos</h2><p>O que foi recusado ou devolvido pelo servidor, sem confundir com entrega confirmada.</p></div>
        </header>
        <div class="ec70-bounce-stats">
            <div><span>Endereço inválido</span><strong>{{ number_format((int) $this->bounceSummary['invalid'], 0, ',', '.') }}</strong></div>
            <div><span>Falhas temporárias</span><strong>{{ number_format((int) $this->bounceSummary['temporary'], 0, ',', '.') }}</strong></div>
            <div><span>Outras rejeições</span><strong>{{ number_format((int) $this->bounceSummary['permanent_other'], 0, ',', '.') }}</strong></div>
        </div>
        @if ($this->recentBounces->isNotEmpty())
            <details class="ec70-bounce-details">
                <summary>Consultar as últimas {{ $this->recentBounces->count() }} devoluções <span aria-hidden="true">⌄</span></summary>
                <div class="ec70-bounce-scroll">
                    <table class="ec70-bounce-table">
                        <thead><tr><th>Campanha</th><th>Situação</th><th>Data</th></tr></thead>
                        <tbody>
                            @foreach ($this->recentBounces as $bounce)
                                <tr wire:key="ec70-bounce-{{ $bounce->id }}">
                                    <td>{{ $bounce->campaign?->name ?? 'Campanha não identificada' }}</td>
                                    <td>{{ match ((string) $bounce->classification) { 'invalid' => 'Endereço inválido', 'temporary' => 'Falha temporária', default => 'Outra rejeição' } }}</td>
                                    <td>{{ $bounce->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </section>
</div>
