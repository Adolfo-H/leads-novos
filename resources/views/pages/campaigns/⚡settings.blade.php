<?php

use App\Models\User;
use App\Services\Campaigns\CampaignSendPolicy;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $volume = '500';
    public string $duration = '8';
    public string $start = '08:00';
    public bool $activate = false;
    public bool $capacityConfirmed = false;

    public function mount(CampaignSendPolicy $policy): void
    {
        $this->ensureManager();
        $snapshot = $policy->snapshot();
        if ($snapshot['updated_at'] !== null) {
            $this->volume = (string) $snapshot['target_volume'];
            $this->duration = (string) $snapshot['duration_hours'];
            $this->start = $snapshot['window_start'];
            $this->activate = $snapshot['active'];
        }
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    #[Computed]
    public function preview(): array
    {
        $this->ensureManager();
        return app(CampaignSendPolicy::class)->quote((int) $this->volume, (int) $this->duration);
    }

    #[Computed]
    public function activePolicy(): array
    {
        $this->ensureManager();
        return app(CampaignSendPolicy::class)->effective();
    }

    public function saveSettings(CampaignSendPolicy $policy): void
    {
        $this->ensureManager();
        $this->validate([
            'volume' => ['required', 'integer', 'min:1', 'max:500'],
            'duration' => ['required', 'integer', 'min:1', 'max:24'],
            'start' => ['required', 'date_format:H:i'],
            'activate' => ['boolean'],
            'capacityConfirmed' => ['boolean'],
        ]);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $policy->save($user, (int) $this->volume, (int) $this->duration, $this->start, $this->activate, $this->capacityConfirmed);
        unset($this->activePolicy);
        $this->capacityConfirmed = false;
        session()->flash('policy_success', $this->activate
            ? 'Política de ritmo salva. O envio comercial permanece controlado pelo modo seguro.'
            : 'Plano salvo para simulação. Os limites ativos de envio não foram alterados.');
    }
};
?>
<div class="ecm6-page ecm7-page">
    <header class="ecm6-heading ecm7-page-heading">
        <div><span class="ecm6-eyebrow">RELACIONAMENTO / CAMPANHAS</span><h1>Configurações de envio</h1><p>Planeje o volume, o ritmo e os horários sem editar o servidor a cada campanha.</p></div>
        <span class="ecm7-status {{ config('campaign_mail.live_enabled') ? 'is-warning' : '' }}"><span class="ecm7-status-dot"></span>{{ config('campaign_mail.live_enabled') ? 'Envio comercial habilitado' : 'Envios comerciais bloqueados' }}</span>
    </header>
    @include('campaigns.module-nav')

    @if (session('policy_success')) <div role="status" class="ecm6-success">{{ session('policy_success') }}</div> @endif

    <div class="ecm7-section-heading">
        <div><h2>Planejamento e limites</h2><p>Salvar um plano não envia e-mails e não habilita o disparo comercial.</p></div>
        <span class="ecm7-section-hint">Horário de Brasília (GMT−3)</span>
    </div>

    <div class="ecm6-settings-grid ecm7-settings-grid">
        <section class="ecm6-panel ecm6-settings-form ecm7-settings-form">
            <header><div><span class="ecm6-eyebrow">ETAPA 1</span><h2>Defina o ritmo desejado</h2><p>Exemplo: 500 e-mails distribuídos das 08:00 às 16:00.</p></div></header>
            <form wire:submit="saveSettings">
                <div class="ecm6-form-grid ecm7-form-grid">
                    <label><span>Volume total planejado</span><input type="number" min="1" max="500" wire:model.live.debounce.400ms="volume" required><small>Máximo configurável: 500 e-mails por 24 horas corridas.</small>@error('volume')<em role="alert">{{ $message }}</em>@enderror</label>
                    <label><span>Duração dos disparos</span><select wire:model.live="duration"><option value="4">4 horas</option><option value="6">6 horas</option><option value="8">8 horas</option><option value="10">10 horas</option><option value="12">12 horas</option><option value="16">16 horas</option><option value="24">24 horas</option></select><small>Período diário reservado para os e-mails.</small>@error('duration')<em role="alert">{{ $message }}</em>@enderror</label>
                    <label><span>Começar os disparos às</span><input type="time" wire:model.live="start" required><small>O sistema respeita o horário de Brasília.</small>@error('start')<em role="alert">{{ $message }}</em>@enderror</label>
                </div>
                <div class="ecm7-settings-divider"></div>
                <div class="ecm7-form-section-title"><span>2</span><div><strong>Escolha como usar esse planejamento</strong><p>Você pode apenas simular ou torná-lo efetivo para o motor.</p></div></div>
                <label class="ecm6-check ecm7-choice"><input type="checkbox" wire:model.live="activate"><span><strong>Usar como política efetiva de envio</strong><small>Desmarcado: salva o plano sem mudar os limites atualmente em uso.</small></span></label>
                @if ($activate)
                    <div class="ecm7-capacity-confirm">
                        <strong>Atenção à capacidade do servidor SMTP</strong>
                        <p>Ativar um ritmo mais rápido só é seguro depois de confirmar os limites autorizados para a caixa de envio.</p>
                        <label class="ecm6-check"><input type="checkbox" wire:model="capacityConfirmed"><span><strong>Confirmo que o administrador SMTP autorizou este ritmo</strong><small>Esta confirmação não faz teste de carga e não habilita o envio comercial.</small></span></label>
                    </div>
                @endif
                @error('policy') <p role="alert" class="ecm6-error">{{ $message }}</p> @enderror
                @error('capacityConfirmed') <p role="alert" class="ecm6-error">{{ $message }}</p> @enderror
                @if (config('campaign_mail.live_enabled')) <p role="alert" class="ecm6-error">O envio comercial está ativo. Desative-o antes de alterar a política.</p> @endif
                <div class="ecm6-form-actions ecm7-form-actions">
                    <button class="ecm6-primary" type="submit" wire:loading.attr="disabled" wire:target="saveSettings" @disabled(config('campaign_mail.live_enabled'))>{{ $activate ? 'Salvar e ativar política' : 'Salvar apenas planejamento' }}</button>
                    <span wire:loading wire:target="saveSettings" role="status">Salvando configuração…</span>
                    <small>O SMTP comercial continua protegido pela configuração do servidor.</small>
                </div>
            </form>
        </section>

        <aside class="ecm6-settings-side ecm7-settings-side">
            <section class="ecm6-panel ecm7-preview-panel">
                <header><div><span class="ecm6-eyebrow">SIMULAÇÃO INSTANTÂNEA</span><h2>Prévia do seu plano</h2></div><span class="ecm6-pill">Calculado</span></header>
                <div class="ecm7-preview-hero"><small>Para distribuir {{ number_format((int) $volume, 0, ',', '.') }} mensagens em {{ $duration }} horas</small><div><strong>{{ $this->preview['hourly_limit'] }}<span>/hora</span></strong><span class="ecm7-preview-icon" aria-hidden="true">↗</span></div><p>Capacidade média necessária (planejamento).</p></div>
                <div class="ecm6-forecast ecm7-preview-grid"><div><small>Total de mensagens</small><strong>{{ number_format((int) $volume, 0, ',', '.') }}</strong></div><div><small>Até por minuto</small><strong>{{ $this->preview['per_tick_limit'] }}</strong></div><div><small>Horário de início</small><strong>{{ $start }}</strong></div><div><small>Duração diária</small><strong>{{ $duration }}h</strong></div></div>
                <p class="ecm6-note">{{ $this->preview['reason'] }}</p>
                @unless ($this->preview['possible']) <p role="alert" class="ecm6-error">Essa configuração ultrapassa a capacidade permitida pelo motor.</p> @endunless
            </section>
            <section class="ecm6-panel ecm7-effective-panel"><header><h2>Limites em uso agora</h2><span class="ecm6-pill">{{ $this->activePolicy['source'] === 'prospector' ? 'Prospector' : 'Ambiente (.env)' }}</span></header><div class="ecm6-keyvals">
                <div><span>Volume por hora</span><strong>{{ $this->activePolicy['hour'] }}</strong></div>
                <div><span>Limite em 24 horas</span><strong>{{ $this->activePolicy['day'] }}</strong></div>
                <div><span>Reservas por minuto</span><strong>{{ $this->activePolicy['tick'] }}</strong></div>
                <div><span>Janela de horário</span><strong>{{ $this->activePolicy['window_active'] ? 'Ativa' : 'Sem restrição diária' }}</strong></div>
            </div></section>
            <div class="ecm7-safety-explainer"><span aria-hidden="true">✓</span><p><strong>Segurança preservada.</strong> A configuração de ritmo é separada da permissão de disparo comercial. Aceitação SMTP também não significa entrega na caixa de entrada.</p></div>
        </aside>
    </div>
</div>
