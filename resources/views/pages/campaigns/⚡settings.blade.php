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
<div class="ecm6-page ecm7-page ec21-page ec22-page ec22-settings" data-ec21-screen="settings" data-ec22-screen="settings">
    <header class="ec22-module-head"><div><span class="ec22-kicker">CAMPANHAS / CONFIGURAÇÕES</span><h1>Configurações de envio</h1><p>Configure os limites uma vez. Na campanha, você só escolhe o público, a mensagem e o prazo desejado.</p></div><span class="ec22-state {{ config('campaign_mail.live_enabled') ? 'is-live' : 'is-safe' }}">{{ config('campaign_mail.live_enabled') ? 'Envio comercial habilitado' : 'Envios comerciais bloqueados' }}</span></header>
    @include('campaigns.module-nav')
    @if (session('policy_success'))<div class="ec22-success" role="status">{{ session('policy_success') }}</div>@endif
    <div class="ec22-settings-intro"><h2>Planejamento e limites</h2><p>Defina a capacidade de envio. Você verá a comparação com os limites em uso, sem disparar nenhuma mensagem.</p></div>
    <div class="ec22-two-columns ec22-settings-grid">
        <section class="ec22-card ec22-settings-form">
            <header class="ec22-card-head"><div><h2>Quanto você pretende enviar?</h2><p>As configurações efetivas são globais e exigem aprovação do SMTP.</p></div></header>
            <form wire:submit="saveSettings">
                <div class="ec22-settings-fields">
                    <div class="ec22-input"><label for="ec22-volume">Volume total de mensagens</label><input id="ec22-volume" type="number" min="1" max="500" wire:model.live.debounce.400ms="volume" required><small>Máximo técnico atual: 500 por 24h.</small>@error('volume')<small role="alert">{{ $message }}</small>@enderror</div>
                    <div class="ec22-input"><label for="ec22-duration">Duração da janela de envio</label><select id="ec22-duration" wire:model.live="duration"><option value="1">1 hora</option><option value="2">2 horas</option><option value="4">4 horas</option><option value="6">6 horas</option><option value="8">8 horas</option><option value="12">12 horas</option><option value="16">16 horas</option><option value="24">24 horas</option></select><small>Tempo planejado, sujeito às cotas autorizadas.</small>@error('duration')<small role="alert">{{ $message }}</small>@enderror</div>
                    <div class="ec22-input"><label for="ec22-start">Horário de início da janela</label><input id="ec22-start" type="time" wire:model.live="start" required><small>Fuso horário configurado na aplicação.</small>@error('start')<small role="alert">{{ $message }}</small>@enderror</div>
                </div>
                <h3 class="ec22-subheading">Prazos rápidos</h3>
                <div class="ec22-duration-grid" role="group" aria-label="Prazos rápidos">@foreach ([1, 2, 4, 8, 12, 24] as $h)<button type="button" wire:click="$set('duration', @js((string) $h))" class="{{ (int) $duration === $h ? 'is-active' : '' }}">{{ $h }} {{ $h === 1 ? 'hora' : 'horas' }}</button>@endforeach</div>
                <div class="ec22-settings-effective"><label class="ec22-consent"><input type="checkbox" wire:model.live="activate"><span><strong>Aplicar como política efetiva do motor</strong><small>Desmarcado: salva só o plano. Esta opção não libera o envio comercial.</small></span></label>
                    @if ($activate)<label class="ec22-consent"><input type="checkbox" wire:model="capacityConfirmed">Confirmo que o administrador SMTP autorizou este ritmo de envio.</label>@endif
                    @error('capacityConfirmed')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                    @error('policy')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                </div>
                @if (config('campaign_mail.live_enabled'))<div class="ec22-warning" role="alert">É necessário pausar os disparos comerciais antes de modificar a política.</div>@endif
                <button type="submit" class="ec22-button ec22-button-primary" wire:loading.attr="disabled" wire:target="saveSettings" @disabled(config('campaign_mail.live_enabled'))>{{ $activate ? 'Salvar política autorizada' : 'Salvar planejamento' }}</button>
                <span wire:loading wire:target="saveSettings">Salvando...</span>
            </form>
        </section>
        <aside class="ec22-card ec22-settings-summary">
            <header class="ec22-card-head"><div><h2>Resumo do ritmo solicitado</h2><p>{{ $volume }} mensagem(ns) em {{ $duration }}h, a partir de {{ $start }}.</p></div></header>
            <div class="ec22-metric-grid"><div><span>Por hora (solicitado)</span><strong>{{ $this->preview['hourly_limit'] }}</strong></div><div><span>Por minuto (solicitado)</span><strong>{{ $this->preview['per_tick_limit'] }}</strong></div><div><span>Volume</span><strong>{{ number_format((int) $volume, 0, ',', '.') }}</strong></div><div><span>Início da janela</span><strong>{{ $start }}</strong></div></div>
            @if ($this->preview['possible'])<div class="ec22-success">Dentro do teto técnico do motor. Ainda é necessário confirmar a capacidade SMTP.</div>@else<div class="ec22-warning" role="status">Esse volume não cabe no prazo dentro do teto técnico. Ajuste a duração.</div>@endif
            <div class="ec22-real-limits"><h3>Limites efetivos neste momento</h3><dl><div><dt>Por hora</dt><dd>{{ $this->activePolicy['hour'] }}</dd></div><div><dt>Por minuto</dt><dd>{{ $this->activePolicy['tick'] }}</dd></div><div><dt>Em 24 horas</dt><dd>{{ $this->activePolicy['day'] }}</dd></div><div><dt>Origem</dt><dd>{{ $this->activePolicy['source'] === 'prospector' ? 'Prospector' : 'Ambiente' }}</dd></div></dl></div>
            <div class="ec22-info-note">Aceitação pelo SMTP não significa entrega na caixa de entrada. Salvar configurações não inicia nenhum envio.</div>
        </aside>
    </div>
    <section class="ec22-settings-tools"><header class="ec22-section-head"><div><h2>Ferramentas e informações</h2><p>A parte técnica fica separada da criação de campanhas.</p></div><a href="{{ route('campaigns.advanced') }}" wire:navigate>Abrir Central avançada →</a></header>
        <div class="ec22-tool-grid">
            <a href="{{ route('campaigns.queue') }}" wire:navigate><span aria-hidden="true">➤</span><strong>Envios e fila</strong><small>Estado da fila, devoluções e simulação.</small><b aria-hidden="true">↗</b></a>
            <a href="{{ route('campaigns.production.index') }}"><span aria-hidden="true">◇</span><strong>Homologação e segurança</strong><small>Liberações, cotas e descadastro.</small><b aria-hidden="true">↗</b></a>
            <a href="{{ route('campaigns.replies') }}" wire:navigate><span aria-hidden="true">↩</span><strong>Respostas e IMAP</strong><small>Converse com quem respondeu.</small><b aria-hidden="true">↗</b></a>
            <a href="{{ route('campaigns.intelligence.index') }}"><span aria-hidden="true">▥</span><strong>Inteligência comercial</strong><small>Segmentação e planejamento de follow-up.</small><b aria-hidden="true">↗</b></a>
        </div>
    </section>
</div>
