<?php

use App\Models\User;
use App\Services\Campaigns\CampaignSendPolicy;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $volume = '500';

    public string $hour = '25';

    public string $tick = '1';

    public bool $activate = false;

    public bool $capacityConfirmed = false;

    public function mount(CampaignSendPolicy $policy): void
    {
        $this->ensureAccess();
        $snapshot = $policy->snapshot();
        $effective = $policy->effective();
        $this->volume = (string) ($snapshot['updated_at'] !== null ? $snapshot['target_volume'] : $effective['day']);
        $this->hour = (string) ($snapshot['updated_at'] !== null ? $snapshot['hourly_limit'] : $effective['hour']);
        $this->tick = (string) ($snapshot['updated_at'] !== null ? $snapshot['per_tick_limit'] : $effective['tick']);
        $this->activate = $snapshot['active'];
    }

    private function ensureAccess(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);
    }

    #[Computed]
    public function activePolicy(): array
    {
        $this->ensureAccess();

        return app(CampaignSendPolicy::class)->effective();
    }

    public function saveSettings(CampaignSendPolicy $policy): void
    {
        $this->ensureAccess();
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);

        $dailyMax = max(1, min(65535, (int) config('campaign_mail.per_day', 500), (int) config('campaign_mail.smtp_approved_per_day', 500)));
        $hourlyMax = max(1, min(65535, (int) config('campaign_mail.smtp_approved_per_hour', 100)));
        $tickMax = max(1, min(255, (int) config('campaign_mail.smtp_approved_per_tick', 5)));
        $this->validate([
            'volume' => ['required', 'integer', 'min:1', 'max:'.$dailyMax],
            'hour' => ['required', 'integer', 'min:1', 'max:'.$hourlyMax],
            'tick' => ['required', 'integer', 'min:1', 'max:'.$tickMax],
            'activate' => ['boolean'],
            'capacityConfirmed' => ['boolean'],
        ]);

        $policy->saveLimits(
            $user,
            (int) $this->volume,
            (int) $this->hour,
            (int) $this->tick,
            $this->activate,
            $this->capacityConfirmed,
        );
        unset($this->activePolicy);
        $this->capacityConfirmed = false;
        session()->flash('policy_success', $this->activate
            ? 'Limites SMTP atualizados. Nenhuma campanha foi iniciada por esta configuração.'
            : 'Limites salvos como planejamento. A política efetiva continua sob as configurações do ambiente.');
    }
};
?>
<div class="ecm6-page ecm7-page ec21-page ec22-page ec22-settings ec70-settings" data-campaigns-settings="NO-GLOBAL-SEND-WINDOW">
    <header class="ec22-module-head">
        <div>
            <span class="ec22-kicker">CAMPANHAS / CONFIGURAÇÕES</span>
            <h1>Configurações de envio</h1>
            <p><strong>Limites SMTP globais.</strong> Nenhum horário de início obrigatório: cada campanha aprovada pode começar no próximo ciclo do agendador, respeitando as cotas.</p>
        </div>
        <span class="ec22-state {{ config('campaign_mail.live_enabled') ? 'is-live' : 'is-safe' }}">{{ config('campaign_mail.live_enabled') ? 'Envio comercial habilitado' : 'Envios comerciais bloqueados' }}</span>
    </header>

    @include('campaigns.module-nav')

    @if (session('policy_success'))
        <div class="ec22-success" role="status">{{ session('policy_success') }}</div>
    @endif

    <div class="ec22-settings-intro">
        <h2>Configuração técnica do SMTP</h2>
        <p>Defina apenas a capacidade que o provedor SMTP autorizou. O tempo desejado é escolhido durante a criação de cada campanha.</p>
    </div>

    <section class="ec90-server-advanced" style="border:1px solid #d7e7e3;border-radius:12px;padding:15px;background:white;margin-top:18px">
        <h2 class="ec92-smtp-visible-title" style="color:#08786b;font-weight:720">Limites SMTP globais</h2>
        <div class="ec22-two-columns ec22-settings-grid" style="margin-top:16px">
            <section class="ec22-card ec22-settings-form">
                <header class="ec22-card-head">
                    <div>
                        <h2>Qual é a capacidade autorizada?</h2>
                        <p>Esses são os tetos máximos do motor, não uma programação de horário.</p>
                    </div>
                </header>

                <form wire:submit="saveSettings">
                    <div class="ec22-settings-fields">
                        <div class="ec22-input">
                            <label for="ec22-volume">Mensagens em 24 horas</label>
                            <input id="ec22-volume" type="number" min="1" wire:model="volume" required>
                            <small>Limite da aplicação: {{ number_format(min((int) config('campaign_mail.per_day', 500), (int) config('campaign_mail.smtp_approved_per_day', 500)), 0, ',', '.') }} mensagens.</small>
                            @error('volume')<small role="alert">{{ $message }}</small>@enderror
                        </div>
                        <div class="ec22-input">
                            <label for="ec22-hour">Mensagens por hora</label>
                            <input id="ec22-hour" type="number" min="1" wire:model="hour" required>
                            <small>Teto autorizado: {{ number_format((int) config('campaign_mail.smtp_approved_per_hour', 100), 0, ',', '.') }}/h.</small>
                            @error('hour')<small role="alert">{{ $message }}</small>@enderror
                        </div>
                        <div class="ec22-input">
                            <label for="ec22-tick">Mensagens por minuto</label>
                            <input id="ec22-tick" type="number" min="1" wire:model="tick" required>
                            <small>Teto autorizado: {{ number_format((int) config('campaign_mail.smtp_approved_per_tick', 5), 0, ',', '.') }}/min.</small>
                            @error('tick')<small role="alert">{{ $message }}</small>@enderror
                        </div>
                    </div>
                    <div class="ec22-settings-effective">
                        <label class="ec22-consent">
                            <input type="checkbox" wire:model.live="activate">
                            <span><strong>Aplicar como política efetiva do motor</strong><small>Desmarcado: mantém o limite ativo do ambiente. Não inicia campanhas.</small></span>
                        </label>
                        @if ($activate)
                            <label class="ec22-consent">
                                <input type="checkbox" wire:model="capacityConfirmed">
                                Confirmo que o provedor SMTP autorizou estes limites.
                            </label>
                        @endif
                        @error('capacityConfirmed')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                        @error('policy')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="ec22-button ec22-button-primary" @disabled(auth()->user()?->isCommercialSeller()) wire:loading.attr="disabled" wire:target="saveSettings">
                        Salvar limites autorizados
                    </button>
                    <span wire:loading wire:target="saveSettings">Salvando...</span>
                </form>
            </section>

            <aside class="ec22-card ec22-settings-summary">
                <header class="ec22-card-head">
                    <div><h2>Limites efetivos neste momento</h2><p>O motor usa o menor limite entre a política ativa e os tetos SMTP configurados.</p></div>
                </header>
                <div class="ec22-metric-grid">
                    <div><span>Por hora</span><strong>{{ number_format($this->activePolicy['hour'], 0, ',', '.') }}</strong></div>
                    <div><span>Por minuto</span><strong>{{ number_format($this->activePolicy['tick'], 0, ',', '.') }}</strong></div>
                    <div><span>Em 24 horas</span><strong>{{ number_format($this->activePolicy['day'], 0, ',', '.') }}</strong></div>
                    <div><span>Origem</span><strong>{{ $this->activePolicy['source'] === 'prospector' ? 'Prospector' : 'Ambiente' }}</strong></div>
                </div>
                <div class="ec22-info-note">
                    <strong>Início do envio:</strong> após a autorização explícita de uma campanha, o agendador verifica os destinatários a cada minuto.
                    A duração desejada define a cadência da campanha, mas não garante a conclusão no prazo caso existam cotas, filas ou erros SMTP.
                </div>
                <div class="ec22-info-note">Aceitação pelo servidor SMTP não garante a entrega. Alterar limites nunca inicia nem retoma automaticamente uma campanha pausada.</div>
            </aside>
        </div>
    </section>
</div>
