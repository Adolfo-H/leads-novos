<?php

use App\Models\User;
use App\Models\EmailCampaignBounce;
use App\Services\Campaigns\CampaignQueueHealth;
use App\Services\Campaigns\CampaignQueueSimulator;
use App\Services\Campaigns\CampaignSendPolicy;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $scenario = 500;

    public function mount(): void
    {
        $this->ensureManager();
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    #[Computed]
    public function health(): array
    {
        $this->ensureManager();
        return app(CampaignQueueHealth::class)->snapshot();
    }

    #[Computed]
    public function simulation(): array
    {
        $this->ensureManager();
        return app(CampaignQueueSimulator::class)->report($this->scenario);
    }

    #[Computed]
    public function effective(): array
    {
        $this->ensureManager();
        return app(CampaignSendPolicy::class)->effective();
    }

    // EC_CAMPAIGNS_V7_BOUNCES: somente leitura; nenhum e-mail ou estado é alterado.
    #[Computed]
    public function bounceSummary(): array
    {
        $this->ensureManager();
        return [
            'invalid' => EmailCampaignBounce::query()->where('classification', 'invalid')->count(),
            'temporary' => EmailCampaignBounce::query()->where('classification', 'temporary')->count(),
            'permanent_other' => EmailCampaignBounce::query()->where('classification', 'permanent_other')->count(),
        ];
    }

    #[Computed]
    public function recentBounces(): \Illuminate\Database\Eloquent\Collection
    {
        $this->ensureManager();
        return EmailCampaignBounce::query()->with('campaign')->orderByDesc('id')->limit(15)->get();
    }

    public function selectScenario(int $scenario): void
    {
        $this->ensureManager();
        abort_unless(in_array($scenario, [10, 50, 100, 500], true), 422);
        $this->scenario = $scenario;
        unset($this->simulation);
    }

    public function refreshQueue(): void
    {
        $this->ensureManager();
        unset($this->health, $this->simulation, $this->effective, $this->bounceSummary, $this->recentBounces);
    }
};
?>
<div class="ecm6-page ecm7-page ec21-page ec21-queue" data-ec21-screen="queue" wire:poll.60s>
    <header class="ecm6-heading ecm7-page-heading">
        <div><span class="ecm6-eyebrow">RELACIONAMENTO / CAMPANHAS</span><h1>Envios e fila</h1><p>Acompanhe o processamento e simule volumes antes de configurar suas campanhas.</p></div>
        <span class="ecm7-status {{ $this->health['safe_mode'] ? '' : 'is-warning' }}"><span class="ecm7-status-dot"></span>{{ $this->health['safe_mode'] ? 'Envios comerciais bloqueados' : 'Envio comercial habilitado' }}</span>
    </header>
    @include('campaigns.module-nav')

    <div class="ecm7-section-heading">
        <div><h2>Visão da fila</h2><p>Estados reais registrados no banco e atualizados automaticamente.</p></div>
        <button type="button" class="ecm6-secondary ecm7-refresh" wire:click="refreshQueue" wire:loading.attr="disabled" wire:target="refreshQueue"><span aria-hidden="true">↻</span> Atualizar</button>
    </div>
    <section class="ecm6-metric-grid ecm7-metric-grid" aria-label="Situação dos e-mails comerciais">
        <div><span class="ecm7-metric-dot is-blue"></span><small>Pendentes</small><strong>{{ $this->health['pending'] }}</strong><span class="ecm7-metric-caption">Aguardando reserva</span></div>
        <div><span class="ecm7-metric-dot is-cyan"></span><small>Reservados</small><strong>{{ $this->health['reserved'] }}</strong><span class="ecm7-metric-caption">Na fila</span></div>
        <div><span class="ecm7-metric-dot is-blue"></span><small>Processando</small><strong>{{ $this->health['sending'] }}</strong><span class="ecm7-metric-caption">Job em execução</span></div>
        <div><span class="ecm7-metric-dot is-green"></span><small>Aceitos SMTP</small><strong>{{ $this->health['sent'] }}</strong><span class="ecm7-metric-caption">Não garante entrega</span></div>
        <div class="is-caution"><span class="ecm7-metric-dot is-amber"></span><small>Resultado incerto</small><strong>{{ $this->health['uncertain'] }}</strong><span class="ecm7-metric-caption">Exige análise manual</span></div>
    </section>

    <div class="ecm6-queue-grid ecm7-queue-grid">
        <section class="ecm6-panel ecm6-queue-simulator ecm7-simulator">
            <header>
                <div><span class="ecm6-eyebrow">SIMULAÇÃO · SEM ENVIO REAL</span><h2>Quanto tempo levaria para enviar?</h2><p>Uma projeção com os limites <strong>atualmente efetivos</strong>, sem alterar filas nem disparar e-mails.</p></div>
                <span class="ecm6-pill">Modo simulação</span>
            </header>
            <div class="ecm7-simulator-inner">
                <div class="ecm7-field-caption">1. Escolha o volume de contatos</div>
                <div class="ecm6-toolbar ecm7-scenarios" role="group" aria-label="Quantidade de contatos">
                    @foreach ([10, 50, 100, 500] as $size)
                        <button type="button" wire:click="selectScenario({{ $size }})" class="{{ $scenario === $size ? 'is-active' : '' }}" aria-pressed="{{ $scenario === $size ? 'true' : 'false' }}">{{ $size }} contatos</button>
                    @endforeach
                </div>
                <div class="ecm7-field-caption">2. Capacidade estimada</div>
                <div class="ecm6-forecast ecm6-forecast-large ecm7-forecast">
                    <div><small>Na primeira hora</small><strong>{{ $this->simulation['first_hour'] }}</strong><span>mensagens</span></div>
                    <div><small>Em até 24 horas</small><strong>{{ $this->simulation['first_24_hours'] }}</strong><span>mensagens</span></div>
                    <div><small>Em até 7 dias</small><strong>{{ $this->simulation['within_seven_days'] }}</strong><span>de {{ $scenario }} contatos</span></div>
                    <div class="ecm7-forecast-highlight"><small>Ritmo configurado</small><strong>{{ $this->simulation['hourly_limit'] }}/h</strong><span>{{ $this->simulation['per_tick_limit'] }} por minuto</span></div>
                </div>
                <div class="ecm7-simulator-progress-label"><strong>Progresso teórico em até 7 dias</strong><span>{{ number_format(min(100, round($this->simulation['within_seven_days'] * 100 / max(1, $scenario))), 0, ',', '.') }}%</span></div>
                <div class="ecm6-progress" role="progressbar" aria-label="Percentual simulado em até sete dias" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, round($this->simulation['within_seven_days'] * 100 / max(1, $scenario))) }}"><div style="width: {{ min(100, ($this->simulation['within_seven_days'] / max(1, $scenario)) * 100) }}%"></div></div>
                <div class="ecm7-simulation-context"><span class="ecm7-bullet">i</span><p>Política efetiva: <strong>{{ $this->simulation['hourly_limit'] }}/h</strong> · <strong>{{ $this->simulation['daily_limit'] }} por 24h</strong> · <strong>{{ $this->simulation['per_tick_limit'] }}/min</strong>. {{ $this->effective['window_active'] ? 'Janela diária ativa.' : 'Sem janela diária restrita.' }} Simulação não considera atrasos, concorrência ou rejeições SMTP.</p></div>
                <a class="ecm7-inline-link" href="{{ route('campaigns.settings') }}" wire:navigate>Alterar planejamento e limites →</a>
            </div>
        </section>
        <aside class="ecm6-settings-side ecm7-queue-side">
            <section class="ecm6-panel">
                <header><div><h2>Saúde do processamento</h2><p>Itens que precisam de acompanhamento.</p></div><span class="ecm6-pill">Diagnóstico</span></header>
                <div class="ecm6-keyvals">
                    <div><span>Reservas antigas</span><strong>{{ $this->health['stale_reserved'] }}</strong></div>
                    <div><span>Envios sem conclusão</span><strong>{{ $this->health['stale_sending'] }}</strong></div>
                    <div><span>Descadastros e bloqueios</span><strong>{{ $this->health['suppressed'] }}</strong></div>
                    <div><span>Cancelados</span><strong>{{ $this->health['canceled'] }}</strong></div>
                    <div><span>Fila Redis</span><strong>{{ $this->health['queue'] }}</strong></div>
                </div>
                <div class="ecm7-aside-note"><span aria-hidden="true">!</span><p>Resultados incertos não são reenviados automaticamente, evitando mensagens duplicadas.</p></div>
            </section>
            <section class="ecm6-panel ecm7-small-card"><header><h2>Gerenciar a política</h2></header><div><p>Defina o volume pretendido e a janela diária diretamente no Prospector.</p><a class="ecm6-primary ecm7-button-link" href="{{ route('campaigns.settings') }}" wire:navigate>Ir para configurações <span aria-hidden="true">→</span></a></div></section>
        </aside>
    </div>
    @include('campaigns.bounce-panel')
</div>
