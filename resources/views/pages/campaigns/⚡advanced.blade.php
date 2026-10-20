<?php

use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        $this->ensureManager();
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    /** @return array<string, int> */
    #[Computed]
    public function stats(): array
    {
        $this->ensureManager();
        $counts = EmailCampaignRecipient::query()
            ->select('status')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'reserved' => (int) ($counts['reserved'] ?? 0),
            'sending' => (int) ($counts['sending'] ?? 0),
            'smtp' => (int) ($counts['sent'] ?? 0),
            'uncertain' => (int) ($counts['uncertain'] ?? 0),
            'bounces' => EmailCampaignBounce::query()->count(),
        ];
    }
};
?>
<div class="ecm6-page ecm7-page ec21-page ec22-page ec22-advanced" data-ec22-screen="advanced">
    <header class="ec22-module-head"><div><span class="ec22-kicker">CAMPANHAS / ADMINISTRAÇÃO</span><h1>Central avançada</h1><p>Ferramentas técnicas para acompanhar filas, segurança e segmentação. Elas não fazem parte da criação de campanhas.</p></div><a class="ec22-button ec22-button-outline" href="{{ route('campaigns.settings') }}" wire:navigate>← Configurações gerais</a></header>
    @include('campaigns.module-nav')
    <div class="ec22-tool-grid ec22-advanced-tabs" aria-label="Áreas avançadas">
        <a href="{{ route('campaigns.queue') }}" wire:navigate><span aria-hidden="true">➤</span><strong>Fila e entregabilidade</strong><small>Envios, resultados incertos e devoluções.</small><b>↗</b></a>
        <a href="{{ route('campaigns.production.index') }}"><span aria-hidden="true">◇</span><strong>Homologação e segurança</strong><small>Checklist de liberação comercial.</small><b>↗</b></a>
        <a href="{{ route('campaigns.intelligence.index') }}"><span aria-hidden="true">▥</span><strong>Inteligência comercial</strong><small>Segmentos, follow-ups e rascunhos.</small><b>↗</b></a>
    </div>
    <div class="ec22-two-columns ec22-advanced-layout">
        <section class="ec22-card"><header class="ec22-card-head"><div><h2>Fila e entregabilidade</h2><p>Contagens reais dos registros locais. Aceitação SMTP não confirma entrega.</p></div><a href="{{ route('campaigns.queue') }}" wire:navigate>Ver fila completa →</a></header>
            <div class="ec22-metric-grid ec22-advanced-stats"><div><span>Pendentes</span><strong>{{ $this->stats['pending'] }}</strong></div><div><span>Aceitos SMTP</span><strong>{{ $this->stats['smtp'] }}</strong></div><div><span>Não confirmados</span><strong>{{ $this->stats['uncertain'] }}</strong></div><div><span>Devoluções registradas</span><strong>{{ $this->stats['bounces'] }}</strong></div></div>
            <div class="ec22-advanced-summary"><h3>O que precisa de acompanhamento?</h3><dl><div><dt>Reservados</dt><dd>{{ $this->stats['reserved'] }}</dd></div><div><dt>Processando</dt><dd>{{ $this->stats['sending'] }}</dd></div></dl><p>Resultados incertos não são reenviados automaticamente, para evitar duplicidade.</p></div>
            <a class="ec22-button ec22-button-outline" href="{{ route('campaigns.queue') }}" wire:navigate>Monitorar fila e devoluções →</a>
        </section>
        <aside class="ec22-advanced-asides">
            <section class="ec22-card"><header class="ec22-card-head"><div><h2>Homologação e segurança</h2><p>Controles que protegem seus envios.</p></div><span class="ec22-state {{ config('campaign_mail.live_enabled') ? 'is-live' : 'is-safe' }}">{{ config('campaign_mail.live_enabled') ? 'Habilitado' : 'Bloqueado' }}</span></header><div class="ec22-info-note">Exige evidências de capacidade SMTP, URL pública HTTPS, descadastro e autorização antes de campanhas reais.</div><a class="ec22-button ec22-button-outline" href="{{ route('campaigns.production.index') }}">Abrir homologação →</a></section>
            <section class="ec22-card"><header class="ec22-card-head"><div><h2>Inteligência comercial</h2><p>Segmentação e rascunhos com segurança.</p></div></header><div class="ec22-info-note">Prepare grupos e follow-ups. Ações comerciais e disparos seguem aprovações próprias.</div><a class="ec22-button ec22-button-outline" href="{{ route('campaigns.intelligence.index') }}">Abrir inteligência →</a></section>
        </aside>
    </div>
</div>
