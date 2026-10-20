<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignTestMessage;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignPilot;
use App\Services\Campaigns\CampaignImapHealth;
use App\Services\Campaigns\CampaignTrackedTest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    // EC_RECEITA_UNIFIED_V10: chaves Receita verificadas em CampaignAudience.

    public string $screen = 'overview';
    public string $composerStep = 'audience';
    public string $detailTab = 'recipients';
    public ?int $editingCampaignId = null;
    public ?int $selectedCampaignId = null;
    public string $name = '';
    public string $subject = '';
    public string $body = '';
    public string $audienceSearch = '';
    public string $audienceOrigin = 'all';
    public int $audiencePage = 1;
    /** @var list<string> */
    public array $audienceKeys = [];
    public bool $lawfulBasisConfirmed = false;
    public string $campaignSearch = '';
    public string $campaignStatus = 'all';
    public int $recipientPage = 1;

    public function mount(): void
    {
        $keys = session()->pull('campaign_mail_selected', []);
        if (is_array($keys) && $keys !== []) {
            $this->audienceKeys = array_values(array_unique(array_filter($keys,
                static fn (mixed $key): bool => is_string($key)
                    && preg_match('/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/D', $key) === 1
            )));
            $this->screen = 'composer';
            $this->composerStep = 'audience';
            $this->applyTemplate('initial');
        } else {
            // Quando já existem campanhas, a mais recente abre na área operacional.
            $latestId = EmailCampaign::query()->latest('id')->value('id');
            if ($latestId !== null) {
                $this->selectedCampaignId = (int) $latestId;
                $this->screen = 'detail';
                $this->detailTab = 'overview';
            }
        }
    }

    private function assertManager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);

        return $user;
    }

    #[Computed]
    public function campaigns(): Collection
    {
        $this->assertManager();
        $query = EmailCampaign::query()
            ->withCount('recipients')
            ->withCount(['recipients as accepted_smtp_count' => static fn ($q) => $q->where('status', 'sent')])
            ->latest('id');
        $term = trim(mb_substr($this->campaignSearch, 0, 120));
        if ($term !== '') {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%');
        }
        if (in_array($this->campaignStatus, ['draft', 'running', 'paused', 'completed', 'canceled'], true)) {
            $query->where('status', $this->campaignStatus);
        }

        return $query->limit(40)->get();
    }

    #[Computed]
    public function overview(): array
    {
        $this->assertManager();
        return [
            'campaigns' => EmailCampaign::query()->count(),
            'active' => EmailCampaign::query()->where('status', 'running')->count(),
            'sent' => EmailCampaignRecipient::query()->where('status', 'sent')->count(),
            'replies' => EmailCampaignRecipient::query()->whereNotNull('replied_at')->count(),
            'pending' => EmailCampaignRecipient::query()->whereIn('status', ['pending', 'reserved'])->count(),
            'queue' => EmailCampaignRecipient::query()
                ->whereIn('status', ['pending', 'reserved'])
                ->whereHas('campaign', static fn ($query) => $query->where('status', 'running'))
                ->count(),
            'drafts' => EmailCampaign::query()->where('status', 'draft')->count(),
            'paused' => EmailCampaign::query()->where('status', 'paused')->count(),
            'uncertain' => EmailCampaignRecipient::query()->where('status', 'uncertain')->count(),
            'sent_today' => EmailCampaignRecipient::query()->where('status', 'sent')
                ->whereDate('sent_at', now()->toDateString())->count(),
        ];
    }

    #[Computed]
    public function audience(): LengthAwarePaginator
    {
        $this->assertManager();
        return app(CampaignAudience::class)->paginate(
            $this->audienceSearch, $this->audienceOrigin, $this->audiencePage,
        );
    }

    #[Computed]
    public function selectedCampaign(): ?EmailCampaign
    {
        $this->assertManager();
        return $this->selectedCampaignId !== null
            ? EmailCampaign::query()->withCount('recipients')->find($this->selectedCampaignId)
            : null;
    }

    #[Computed]
    public function recipientStats(): array
    {
        $this->assertManager();
        if ($this->selectedCampaignId === null) {
            return [];
        }
        return EmailCampaignRecipient::query()
            ->where('email_campaign_id', $this->selectedCampaignId)
            ->select('status')->selectRaw('COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status')->all();
    }

    #[Computed]
    public function recipients(): LengthAwarePaginator
    {
        $this->assertManager();
        return EmailCampaignRecipient::query()
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->orderBy('id')->paginate(15, ['*'], 'recipientPage', $this->recipientPage);
    }

    #[Computed]
    public function replies(): Collection
    {
        $this->assertManager();
        return EmailCampaignReply::query()
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->latest('received_at')->limit(30)->get();
    }

    // EC_CAMPAIGNS_PILOT_V5: somente endereços explícitos, sem liberar envio comercial.
    #[Computed]
    public function pilotHistory(): Collection
    {
        $this->assertManager();

        return EmailCampaignTestMessage::query()
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->where('status', 'like', 'pilot_%')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function queuePilot(int $campaignId, CampaignPilot $pilot): void
    {
        $user = $this->assertManager();
        $campaign = EmailCampaign::query()->findOrFail($campaignId);
        $total = $pilot->queue($user, $campaign);
        unset($this->pilotHistory);
        session()->flash('campaign_success', $total > 0
            ? $total.' caixa(s) autorizada(s) adicionada(s) à fila piloto.'
            : 'Este rascunho já possui testes piloto para todos os endereços autorizados.');
    }
    // EC_CAMPAIGN_IMAP_MONITOR_V1: monitoramento local sem leitura de mensagens.
    #[Computed]
    public function imapHealth(): array
    {
        $this->assertManager();

        return app(CampaignImapHealth::class)->snapshot();
    }

    public function refreshImapHealth(): void
    {
        $this->assertManager();
        unset($this->imapHealth);
    }

    public function updatedAudienceSearch(): void
    {
        $this->audiencePage = 1;
        unset($this->audience);
    }

    public function updatedAudienceOrigin(): void
    {
        $this->audiencePage = 1;
        unset($this->audience);
    }

    public function updatedCampaignSearch(): void
    {
        unset($this->campaigns);
    }

    public function updatedCampaignStatus(): void
    {
        unset($this->campaigns);
    }

    public function changeAudiencePage(int $page): void
    {
        $this->audiencePage = max(1, min(10000, $page));
        unset($this->audience);
    }

    public function changeRecipientPage(int $page): void
    {
        $this->recipientPage = max(1, min(10000, $page));
        unset($this->recipients);
    }

    public function newCampaign(): void
    {
        $this->assertManager();
        $this->reset(['editingCampaignId', 'selectedCampaignId', 'name', 'subject', 'body',
            'audienceSearch', 'audienceOrigin', 'audiencePage', 'audienceKeys', 'lawfulBasisConfirmed']);
        $this->screen = 'composer';
        $this->composerStep = 'audience';
        $this->applyTemplate('initial');
        $this->resetValidation();
    }

    public function showOverview(): void
    {
        $this->assertManager();
        $this->screen = 'overview';
        $this->editingCampaignId = null;
    }

    public function openCampaign(int $id): void
    {
        $this->assertManager();
        abort_unless(EmailCampaign::query()->whereKey($id)->exists(), 404);
        $this->selectedCampaignId = $id;
        $this->screen = 'detail';
        $this->detailTab = 'overview';
        $this->recipientPage = 1;
        unset($this->selectedCampaign, $this->recipientStats, $this->recipients, $this->replies);
    }

    public function editDraft(int $id): void
    {
        $this->assertManager();
        $campaign = EmailCampaign::query()->findOrFail($id);
        abort_unless($campaign->status === 'draft', 422);
        $this->editingCampaignId = $id;
        $this->name = $campaign->name;
        $this->subject = $campaign->subject;
        $this->body = $campaign->body;
        $this->audienceKeys = $campaign->recipients()->whereNotNull('source_key')
            ->pluck('source_key')->all();
        $this->screen = 'composer';
        $this->composerStep = 'message';
        $this->resetValidation();
    }

    /** Avança na preparação sem disparar nem salvar mensagens. */
    public function goToComposerStep(string $step): void
    {
        $this->assertManager();
        abort_unless(in_array($step, ['audience', 'message', 'review'], true), 422);
        $this->resetValidation();

        if (in_array($step, ['message', 'review'], true) && $this->audienceKeys === []) {
            $this->addError('recipients', 'Selecione pelo menos um contato antes de continuar.');
            $this->composerStep = 'audience';
            return;
        }

        if ($step === 'review') {
            $this->validate([
                'name' => ['required', 'string', 'min:3', 'max:180'],
                'subject' => ['required', 'string', 'min:3', 'max:180'],
                'body' => ['required', 'string', 'min:10', 'max:12000'],
            ]);
        }

        $this->composerStep = $step;
    }

    public function startWithTemplate(string $key): void
    {
        $this->newCampaign();
        $this->applyTemplate($key);
        // Começa pela escolha de público para não esquecer destinatários.
    }

    public function applyTemplate(string $key): void
    {
        $templates = [
            'initial' => [
                'Apresentação ExportControl — {{empresa}}',
                "Olá, {{primeiro_nome}}!\n\nTudo bem?\n\nSou Adolfo, da ExportControl. Atuamos com soluções para acompanhamento e controle documental das operações de exportação.\n\nGostaria de entender como a {{empresa}} realiza hoje esse acompanhamento e verificar se faz sentido conversarmos.\n\nSe não for sua área, poderia me indicar a pessoa responsável?\n\nObrigado,\nAdolfo\nExportControl",
            ],
            'followup' => [
                'Retomando nosso contato — ExportControl',
                "Olá, {{primeiro_nome}}!\n\nRetomo meu contato sobre o controle e acompanhamento das operações de exportação da {{empresa}}.\n\nSe o assunto fizer sentido, podemos conversar brevemente nesta semana.\n\nObrigado,\nAdolfo | ExportControl",
            ],
            'meeting' => [
                'Convite para apresentação — ExportControl',
                "Olá, {{primeiro_nome}}!\n\nGostaria de agendar uma apresentação rápida das soluções ExportControl para sua equipe.\n\nVocê teria disponibilidade nos próximos dias?\n\nAtenciosamente,\nAdolfo | ExportControl",
            ],
        ];
        if (! isset($templates[$key])) {
            return;
        }
        [$this->subject, $this->body] = $templates[$key];
    }

    public function toggleAudiencePage(): void
    {
        $this->assertManager();
        $pageKeys = array_map(static fn (object $p): string => (string) $p->source_key, $this->audience->items());
        $selected = array_values(array_intersect($this->audienceKeys, $pageKeys));
        if (count($selected) === count($pageKeys)) {
            $this->audienceKeys = array_values(array_diff($this->audienceKeys, $pageKeys));
        } else {
            $this->audienceKeys = array_values(array_unique(array_merge($this->audienceKeys, $pageKeys)));
        }
    }

    public function addAllFiltered(): void
    {
        $this->assertManager();
        try {
            $keys = app(CampaignAudience::class)->allMatchingKeys(
                $this->audienceSearch, $this->audienceOrigin,
                max(1, min(1000, (int) config('campaign_mail.max_recipients', 100))),
            );
            $combined = array_values(array_unique(array_merge($this->audienceKeys, $keys)));
            if (count($combined) > (int) config('campaign_mail.max_recipients', 100)) {
                throw new \InvalidArgumentException('O total selecionado ultrapassou o limite.');
            }
            $this->audienceKeys = $combined;
        } catch (\InvalidArgumentException $error) {
            $this->addError('recipients', $error->getMessage());
        }
    }

    public function saveDraft(CampaignManager $manager): void
    {
        $user = $this->assertManager();
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
            'audienceKeys' => ['required', 'array', 'min:1'],
            'audienceKeys.*' => ['string', 'max:160', 'regex:/^(hubspot|manual):[0-9]+$/'],
        ]);
        $campaign = $manager->saveDraft($user, [
            'name' => $validated['name'], 'subject' => $validated['subject'], 'body' => $validated['body'],
        ], array_values($validated['audienceKeys']), $this->editingCampaignId !== null
            ? EmailCampaign::query()->findOrFail($this->editingCampaignId) : null);
        session()->flash('campaign_success', 'Rascunho salvo. Nenhum e-mail foi enviado.');
        unset($this->overview, $this->campaigns);
        $this->openCampaign((int) $campaign->id);
    }

    public function sendTest(CampaignManager $manager): void
    {
        $user = $this->assertManager();
        $validated = $this->validate([
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
        ]);
        try {
            $manager->sendTest($user, $validated['subject'], $validated['body']);
            session()->flash('campaign_success', 'Teste enviado somente à caixa configurada. Verifique o recebimento.');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('campaign', 'Falha no envio de teste: '.class_basename($exception).'. Verifique o SMTP.');
        }
    }

    // EC_TRACKED_IMAP_TEST_V1: envio somente para caixa de teste, não clientes.
    public function sendTrackedTest(int $id, CampaignTrackedTest $sender): void
    {
        $user = $this->assertManager();
        $campaign = EmailCampaign::query()->findOrFail($id);
        try {
            $test = $sender->send($user, $campaign);
            session()->flash('campaign_success', 'Teste rastreado aceito pelo SMTP para '.$test->test_to.'. Responda pela segunda caixa e execute a sincronização IMAP.');
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    public function startCampaign(int $id, CampaignManager $manager): void
    {
        $manager->start($this->assertManager(), EmailCampaign::query()->findOrFail($id), $this->lawfulBasisConfirmed);
        $this->lawfulBasisConfirmed = false;
        $this->openCampaign($id);
        session()->flash('campaign_success', 'Campanha autorizada. O agendador liberará os envios conforme os limites.');
        unset($this->overview, $this->campaigns);
    }

    public function pauseCampaign(int $id, CampaignManager $manager): void
    {
        $manager->pause($this->assertManager(), EmailCampaign::query()->findOrFail($id));
        $this->openCampaign($id);
        unset($this->overview, $this->campaigns);
    }

    public function resumeCampaign(int $id, CampaignManager $manager): void
    {
        $manager->resume($this->assertManager(), EmailCampaign::query()->findOrFail($id));
        $this->openCampaign($id);
        unset($this->overview, $this->campaigns);
    }

    public function cancelCampaign(int $id, CampaignManager $manager): void
    {
        $manager->cancel($this->assertManager(), EmailCampaign::query()->findOrFail($id));
        $this->openCampaign($id);
        unset($this->overview, $this->campaigns);
    }
};
?>

@php
    $labels = [
        'draft' => 'Rascunho', 'running' => 'Enviando', 'paused' => 'Pausada',
        'completed' => 'Concluída', 'canceled' => 'Cancelada',
    ];
    $delivery = [
        'pending' => 'Na fila', 'reserved' => 'Reservado', 'sending' => 'Processando',
        'sent' => 'Aceito SMTP', 'suppressed' => 'Bloqueado',
        'uncertain' => 'Não confirmado', 'canceled' => 'Cancelado',
    ];
    $counts = $this->overview;
    $campaignsList = $this->campaigns;
    $mailEnabled = (bool) config('campaign_mail.enabled');
    $liveEnabled = $mailEnabled && (bool) config('campaign_mail.live_enabled');
    $appUrl = (string) config('app.url');
    $appHost = parse_url($appUrl, PHP_URL_HOST);
    $publicHttps = parse_url($appUrl, PHP_URL_SCHEME) === 'https'
        && is_string($appHost)
        && ! in_array(mb_strtolower($appHost), ['localhost', '127.0.0.1', '0.0.0.0'], true);
    $canStart = $liveEnabled && $publicHttps;
    $hasAudience = count($audienceKeys) > 0;
    $hasContent = mb_strlen(trim($name)) >= 3
        && mb_strlen(trim($subject)) >= 3 && mb_strlen(trim($body)) >= 10;
    $selected = $screen === 'detail' ? $this->selectedCampaign : null;
    $lastTrackedTest = $selected ? EmailCampaignTestMessage::query()->where('email_campaign_id', $selected->id)->latest('id')->first() : null;
    $stats = $selected ? $this->recipientStats : [];
    $sentCount = (int) ($stats['sent'] ?? 0);
    $totalCount = (int) ($selected?->recipients_count ?? 0);
    $progress = $totalCount > 0 ? min(100, (int) round($sentCount * 100 / $totalCount)) : 0;
@endphp

<div class="ecm2" @if ($screen === 'detail') wire:poll.60s @endif>
    <header class="ecm2-head">
        <div>
            <div class="ecm2-breadcrumb">RELACIONAMENTO <span>/</span> EXPORTCONTROL</div>
            <h1>Campanhas de E-mail</h1>
            <p>Crie, personalize e acompanhe seus e-mails comerciais em um único lugar.</p>
        </div>
        <div class="ecm2-head-actions">
            <span class="ecm2-safe {{ $canStart ? 'is-live' : '' }}" aria-label="Status dos disparos comerciais">
                <span class="ecm2-dot"></span>
                {{ $canStart ? 'Envios reais habilitados' : 'Modo seguro · disparos bloqueados' }}
            </span>
            {{-- EC_CAMPAIGNS_UX_V62_ROOT --}}
            @if ($screen === 'detail')
                <a href="#ecm7-context" class="ecm7-jump" aria-label="Ir para detalhes e ações da campanha">Ações e detalhes ↓</a>
            @endif
            <button type="button" class="ecm2-btn ecm2-btn-primary" wire:click="newCampaign">
                <span aria-hidden="true">＋</span> Nova campanha
            </button>
        </div>
    </header>

    @if (session('campaign_success'))
        <div class="ecm2-notice is-success" role="status">✓ {{ session('campaign_success') }}</div>
    @endif
    @error('campaign') <div class="ecm2-notice is-error" role="alert">{{ $message }}</div> @enderror
    @error('recipients') <div class="ecm2-notice is-error" role="alert">{{ $message }}</div> @enderror
    @error('lawfulBasisConfirmed') <div class="ecm2-notice is-error" role="alert">{{ $message }}</div> @enderror

    {{-- EC_CAMPAIGN_V6_NAV --}}
    @include('campaigns.module-nav')

    <section class="ecm2-stats" aria-label="Indicadores reais das campanhas">
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-blue">▤</span><div><small>Rascunhos</small><strong>{{ number_format($counts['drafts'], 0, ',', '.') }}</strong><span>Aguardando preparação</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-amber">◷</span><div><small>Na fila ativa</small><strong>{{ number_format($counts['queue'], 0, ',', '.') }}</strong><span>Aguardando envio real</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-cyan">➤</span><div><small>Aceitos hoje</small><strong>{{ number_format($counts['sent_today'], 0, ',', '.') }}</strong><span>Pelo servidor SMTP</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-green">↩</span><div><small>Respostas</small><strong>{{ number_format($counts['replies'], 0, ',', '.') }}</strong><span>Registradas pelo IMAP</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-red">!</span><div><small>Não confirmados</small><strong>{{ number_format($counts['uncertain'], 0, ',', '.') }}</strong><span>Conferir tentativas</span></div></div>
    </section>

    <div class="ecm2-shell">
        <aside class="ecm2-list-panel" aria-label="Campanhas cadastradas">
            <header class="ecm2-panel-header">
                <div><small>SUA OPERAÇÃO</small><h2>Minhas campanhas</h2></div>
                <span class="ecm2-number">{{ number_format($counts['campaigns'], 0, ',', '.') }}</span>
            </header>
            <div class="ecm2-list-controls">
                <label class="ecm2-search">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
                    <input type="search" wire:model.live.debounce.350ms="campaignSearch" placeholder="Buscar campanhas..." aria-label="Buscar campanhas">
                </label>
                <div class="ecm2-filters" aria-label="Filtrar campanhas por situação">
                    @foreach (['all' => 'Todas', 'draft' => 'Rascunhos', 'running' => 'Enviando', 'paused' => 'Pausadas', 'completed' => 'Concluídas', 'canceled' => 'Canceladas'] as $code => $label)
                        <button type="button" wire:click="$set('campaignStatus', @js($code))" class="{{ $campaignStatus === $code ? 'is-active' : '' }}" aria-pressed="{{ $campaignStatus === $code ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="ecm2-campaign-list" role="list">
                @forelse ($campaignsList as $item)
                    @php
                        $itemSent = (int) $item->accepted_smtp_count;
                        $itemTotal = (int) $item->recipients_count;
                        $itemProgress = $itemTotal > 0 ? min(100, (int) round($itemSent * 100 / $itemTotal)) : 0;
                    @endphp
                    <button type="button" role="listitem" wire:key="ecm2-campaign-{{ $item->id }}" wire:click="openCampaign({{ $item->id }})"
                        class="ecm2-campaign {{ $selectedCampaignId === $item->id && $screen === 'detail' ? 'is-current' : '' }}"
                        aria-current="{{ $selectedCampaignId === $item->id && $screen === 'detail' ? 'true' : 'false' }}">
                        <span class="ecm2-campaign-heading"><strong title="{{ $item->name }}">{{ $item->name }}</strong><span class="ecm2-pill is-{{ $item->status }}">{{ $labels[$item->status] ?? $item->status }}</span></span>
                        <span class="ecm2-campaign-sub">{{ $itemTotal }} destinatário(s) · {{ $item->created_at?->format('d/m/Y') }}</span>
                        @if ($itemTotal > 0)
                            <span class="ecm2-mini-progress"><i style="width: {{ $itemProgress }}%"></i></span>
                            <span class="ecm2-campaign-footer">{{ $itemSent }} aceito(s) SMTP <b>{{ $itemProgress }}%</b></span>
                        @endif
                    </button>
                @empty
                    <div class="ecm2-no-campaigns">
                        <span aria-hidden="true">✉</span>
                        <strong>{{ $counts['campaigns'] === 0 ? 'Sua primeira campanha começa aqui' : 'Nenhuma campanha neste filtro' }}</strong>
                        <p>{{ $counts['campaigns'] === 0 ? 'Crie um rascunho para organizar destinatários e mensagem.' : 'Altere os filtros ou pesquise outro nome.' }}</p>
                        @if ($counts['campaigns'] === 0)
                            <button type="button" class="ecm2-text-action" wire:click="newCampaign">Criar campanha →</button>
                        @endif
                    </div>
                @endforelse
            </div>
            <footer class="ecm2-list-footer">
                <span aria-hidden="true">◎</span><span>Envio individual via SMTP<br><small>Caixa: {{ config('campaign_mail.from_address') }}</small></span>
            </footer>
        </aside>

        <main class="ecm2-main" aria-label="Área de trabalho de campanhas">
            @if ($screen === 'overview')
                <section class="ecm2-empty-workspace">
                    <div class="ecm2-eyebrow">ÁREA DE TRABALHO / CAMPANHAS</div>
                    <div class="ecm2-welcome-illustration" aria-hidden="true"><span>✉</span><i>✓</i></div>
                    <h2>Da seleção de contatos ao acompanhamento, tudo organizado.</h2>
                    <p>Escolha uma campanha à esquerda ou prepare uma nova comunicação em etapas. Nenhuma mensagem é enviada ao salvar um rascunho.</p>
                    <div class="ecm2-overview-steps">
                        <div><b>01</b><strong>Selecionar contatos</strong><small>HubSpot e cadastros manuais, sem duplicidades de e-mail.</small></div>
                        <div><b>02</b><strong>Escrever mensagem</strong><small>Modelos prontos, personalização e prévia.</small></div>
                        <div><b>03</b><strong>Testar e revisar</strong><small>Envie somente para sua caixa antes de salvar.</small></div>
                    </div>
                    <div class="ecm2-welcome-actions">
                        <button type="button" wire:click="newCampaign" class="ecm2-btn ecm2-btn-primary">＋ Começar campanha</button>
                        <a class="ecm2-link" href="{{ route('contacts.index') }}" wire:navigate>Consultar contatos →</a>
                    </div>
                    <div class="ecm2-templates">
                        <h3>Começar com um modelo</h3>
                        <div>
                            <button type="button" wire:click="startWithTemplate('initial')"><span>▤</span><strong>Apresentação</strong><small>Primeiro contato comercial</small></button>
                            <button type="button" wire:click="startWithTemplate('followup')"><span>↩</span><strong>Retomada</strong><small>Reabrir uma conversa</small></button>
                            <button type="button" wire:click="startWithTemplate('meeting')"><span>▦</span><strong>Agendamento</strong><small>Convite para apresentação</small></button>
                        </div>
                    </div>
                </section>
            @elseif ($screen === 'composer')
                <section class="ecm2-workflow">
                    <header class="ecm2-workflow-heading">
                        <div><div class="ecm2-eyebrow">{{ $editingCampaignId ? 'EDITANDO RASCUNHO' : 'NOVA CAMPANHA' }}</div><h2>{{ $editingCampaignId ? 'Editar campanha' : 'Preparar campanha' }}</h2><p>Três etapas antes de salvar o rascunho.</p></div>
                        <button type="button" class="ecm2-btn ecm2-btn-muted" wire:click="showOverview">← Voltar</button>
                    </header>
                    <nav class="ecm2-stepper" aria-label="Etapas de criação">
                        @foreach ([['audience','01','Destinatários'], ['message','02','Mensagem'], ['review','03','Revisão']] as [$stepKey, $stepNumber, $stepLabel])
                            <button type="button" wire:click="goToComposerStep(@js($stepKey))" class="{{ $composerStep === $stepKey ? 'is-current' : '' }}" aria-current="{{ $composerStep === $stepKey ? 'step' : 'false' }}">
                                <span>{{ $stepNumber }}</span><strong>{{ $stepLabel }}</strong>
                            </button>
                        @endforeach
                    </nav>
                    @if ($composerStep === 'audience')
                        <section class="ecm2-step-content">
                            <div class="ecm2-section-top"><div><h3>Escolha quem receberá a campanha</h3><p>Somente pessoas com e-mail cadastrado, vindas do HubSpot ou da base manual.</p></div><span class="ecm2-selection-count">{{ count($audienceKeys) }} selecionado(s)</span></div>
                            <div class="ecm2-field"><label for="ecm2-name">Nome interno da campanha <span>*</span></label><input id="ecm2-name" type="text" maxlength="180" wire:model="name" placeholder="Ex.: Prospecção — Cooperativas PR">@error('name') <small class="ecm2-field-error">{{ $message }}</small> @enderror</div>
                            <div class="ecm2-audience-toolbar">
                                <label class="ecm2-search"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input type="search" wire:model.live.debounce.450ms="audienceSearch" placeholder="Nome, empresa, e-mail..." aria-label="Buscar destinatários"></label>
                                <select wire:model.live="audienceOrigin" aria-label="Origem dos destinatários"><option value="all">Todas as fontes</option><option value="hubspot">HubSpot</option><option value="manual">Manuais</option></select>
                            </div>
                            <div class="ecm2-audience-actions"><button type="button" wire:click="toggleAudiencePage">☐ Selecionar página</button><button type="button" wire:click="addAllFiltered">＋ Adicionar filtrados</button><button type="button" wire:click="$set('audienceKeys', [])" @disabled($audienceKeys === [])>Limpar seleção</button></div>
                            <div class="ecm2-audience-list">
                                @forelse ($this->audience as $person)
                                    <label class="ecm2-audience-person" wire:key="ecm2-audience-{{ $person->source_key }}">
                                        <input type="checkbox" wire:model.live="audienceKeys" value="{{ $person->source_key }}">
                                        <span class="ecm2-avatar">{{ mb_strtoupper(mb_substr(trim((string) $person->person_name) ?: '?', 0, 1)) }}</span>
                                        <span class="ecm2-audience-name"><strong>{{ trim((string) $person->person_name) ?: 'Contato sem nome' }}</strong><small>{{ $person->email }}</small><small>{{ trim((string) $person->company_name) ?: 'Empresa não informada' }}</small></span>
                                        <span class="ecm2-origin is-{{ $person->origin }}">{{ $person->origin === 'hubspot' ? 'HubSpot' : 'Manual' }}</span>
                                    </label>
                                @empty
                                    <div class="ecm2-audience-blank">Nenhum contato com e-mail encontrado para esta pesquisa.</div>
                                @endforelse
                            </div>
                            <div class="ecm2-pagination"><span>{{ $this->audience->total() }} contato(s) elegíveis · Página {{ $this->audience->currentPage() }} de {{ $this->audience->lastPage() }}</span><div><button type="button" wire:click="changeAudiencePage({{ $this->audience->currentPage() - 1 }})" @disabled($this->audience->onFirstPage())>‹</button><button type="button" wire:click="changeAudiencePage({{ $this->audience->currentPage() + 1 }})" @disabled(! $this->audience->hasMorePages())>›</button></div></div>
                            @error('audienceKeys') <div class="ecm2-field-error">{{ $message }}</div> @enderror
                            <div class="ecm2-step-actions"><span>A seleção será deduplicada ao salvar.</span><button type="button" wire:click="goToComposerStep('message')" class="ecm2-btn ecm2-btn-primary">Próximo: mensagem →</button></div>
                        </section>
                    @elseif ($composerStep === 'message')
                        <section class="ecm2-step-content">
                            <div class="ecm2-section-top"><div><h3>Escreva a mensagem</h3><p>Use o editor e confira como o e-mail será apresentado.</p></div><span class="ecm2-selection-count">{{ count($audienceKeys) }} contato(s)</span></div>
                            {{-- EC_CAMPAIGN_WIZARD_VALIDATION_V2_1: o nome não pode ficar escondido na etapa anterior. --}}
                            <div class="ecm2-field">
                                <label for="ecm2-campaign-name-message">Nome interno da campanha <span>*</span></label>
                                <input id="ecm2-campaign-name-message" type="text" maxlength="180"
                                       wire:model.blur="name" placeholder="Ex.: Retomada de contato - Cooperativas">
                                <small class="ecm2-field-hint">Somente para organização interna; não aparece no e-mail enviado.</small>
                                @error('name') <small class="ecm2-field-error" role="alert">{{ $message }}</small> @enderror
                            </div>
                            <div class="ecm2-template-bar"><span>Modelos rápidos</span><button type="button" wire:click="applyTemplate('initial')">Apresentação</button><button type="button" wire:click="applyTemplate('followup')">Retomada</button><button type="button" wire:click="applyTemplate('meeting')">Agendamento</button></div>
                            <div class="ecm2-field"><label for="ecm2-subject">Assunto <span>*</span></label><input id="ecm2-subject" type="text" maxlength="180" wire:model.live.debounce.400ms="subject" placeholder="Assunto do e-mail">@error('subject') <small class="ecm2-field-error">{{ $message }}</small> @enderror</div>
                            <div class="ecm2-field"><label for="ecm2-body">Corpo do e-mail <span>*</span></label><textarea id="ecm2-body" rows="11" maxlength="12000" spellcheck="true" wire:model.live.debounce.500ms="body" placeholder="Escreva sua mensagem..."></textarea>@error('body') <small class="ecm2-field-error">{{ $message }}</small> @enderror</div>
                            <p class="ecm2-variables">Variáveis disponíveis: <code>@{{nome}}</code> <code>@{{primeiro_nome}}</code> <code>@{{empresa}}</code> <code>@{{cargo}}</code>. Elas são substituídas ao enviar cada mensagem.</p>
                            <div class="ecm2-preview-label">Pré-visualização do texto <small>Variáveis aparecem como digitadas</small></div>
                            <div class="ecm2-mail-preview"><div>De: {{ config('campaign_mail.from_name') }} &lt;{{ config('campaign_mail.from_address') }}&gt;</div><div>Assunto: <strong>{{ $subject ?: '(sem assunto)' }}</strong></div><p>{{ $body ?: 'O conteúdo da mensagem aparecerá aqui.' }}</p></div>
                            @if ($errors->any())
                                <div class="ecm2-validation-alert" role="alert" aria-live="assertive" data-campaign-errors>
                                    <strong>Não foi possível avançar. Confira:</strong>
                                    <ul>
                                        @foreach ($errors->all() as $validationMessage)
                                            <li>{{ $validationMessage }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <div class="ecm2-step-actions"><button type="button" wire:click="goToComposerStep('audience')" class="ecm2-btn ecm2-btn-muted">← Voltar</button><button type="button" wire:click="goToComposerStep('review')" wire:loading.attr="disabled" wire:target="goToComposerStep" class="ecm2-btn ecm2-btn-primary">Próximo: revisar →</button></div>
                        </section>
                    @else
                        <section class="ecm2-step-content">
                            <div class="ecm2-section-top"><div><h3>Revise antes de salvar</h3><p>Salvar cria um rascunho. Nenhum destinatário recebe e-mail nesta etapa.</p></div><span class="ecm2-pill is-draft">Rascunho</span></div>
                            <div class="ecm2-review-grid">
                                <div><small>Campanha</small><strong>{{ $name }}</strong></div>
                                <div><small>Contatos selecionados</small><strong>{{ count($audienceKeys) }}</strong></div>
                                <div><small>Remetente</small><strong>{{ config('campaign_mail.from_address') }}</strong></div>
                                <div><small>Limite da campanha</small><strong>{{ config('campaign_mail.max_recipients') }} endereços</strong></div>
                            </div>
                            <div class="ecm2-preview-label">Revisão do conteúdo</div>
                            <div class="ecm2-mail-preview"><div>De: {{ config('campaign_mail.from_name') }} &lt;{{ config('campaign_mail.from_address') }}&gt;</div><div>Assunto: <strong>{{ $subject }}</strong></div><p>{{ $body }}</p></div>
                            <div class="ecm2-testbox"><span aria-hidden="true">✉</span><div><strong>Envio de teste</strong><p>O teste vai somente para {{ config('campaign_mail.test_to') }} e não dispara aos contatos selecionados.</p></div><button type="button" class="ecm2-btn ecm2-btn-muted" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" @disabled(! $mailEnabled)>Enviar teste</button></div>
                            <div class="ecm2-step-actions"><button type="button" wire:click="goToComposerStep('message')" class="ecm2-btn ecm2-btn-muted">← Editar mensagem</button><button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft" class="ecm2-btn ecm2-btn-primary">✓ Salvar rascunho</button></div>
                        </section>
                    @endif
                </section>
            @elseif ($screen === 'detail' && $selected)
                <section class="ecm2-detail">
                    <header class="ecm2-detail-header"><div><div class="ecm2-eyebrow">CAMPANHA #{{ $selected->id }} <span class="ecm2-pill is-{{ $selected->status }}">{{ $labels[$selected->status] ?? $selected->status }}</span></div><h2>{{ $selected->name }}</h2><p>{{ $selected->subject }}</p></div>@if ($selected->status === 'draft')<button type="button" class="ecm2-btn ecm2-btn-muted" wire:click="editDraft({{ $selected->id }})">✎ Editar rascunho</button>@endif</header>
                    <div class="ecm2-progress-section"><div><strong>Progresso de aceitação SMTP</strong><span>{{ $sentCount }} de {{ $totalCount }} destinatário(s) · {{ $progress }}%</span></div><div class="ecm2-progress"><span style="width:{{ $progress }}%"></span></div><small>Mensagens aceitas pelo servidor não significam entrega na caixa de entrada.</small></div>
                    <div class="ecm2-detail-stats">
                        <div><small>Destinatários</small><strong>{{ number_format($totalCount, 0, ',', '.') }}</strong></div>
                        <div><small>Aceitos SMTP</small><strong>{{ number_format($sentCount, 0, ',', '.') }}</strong></div>
                        <div><small>Respostas</small><strong>{{ number_format((int) $selected->replies()->count(), 0, ',', '.') }}</strong></div>
                        <div><small>Na fila</small><strong>{{ number_format((int) ($stats['pending'] ?? 0) + (int) ($stats['reserved'] ?? 0), 0, ',', '.') }}</strong></div>
                    </div>
                    <nav class="ecm2-tabs" aria-label="Conteúdo da campanha">
                        @foreach (['overview' => 'Visão geral', 'recipients' => 'Destinatários', 'message' => 'Mensagem', 'replies' => 'Respostas'] as $tabKey => $tabLabel)
                            <button type="button" wire:click="$set('detailTab', @js($tabKey))" class="{{ $detailTab === $tabKey ? 'is-active' : '' }}" aria-current="{{ $detailTab === $tabKey ? 'page' : 'false' }}">{{ $tabLabel }} @if ($tabKey === 'replies' && $selected->replies()->count() > 0)<span>{{ $selected->replies()->count() }}</span>@endif</button>
                        @endforeach
                    </nav>
                    @if ($detailTab === 'overview')
                        <div class="ecm2-overview-body">
                            <div class="ecm2-section-top"><div><h3>Visão geral do envio</h3><p>Estados registrados pelo sistema para esta campanha.</p></div></div>
                            <div class="ecm2-state-grid">
                                @foreach (['pending' => 'Pendentes', 'reserved' => 'Reservados', 'sending' => 'Processando', 'sent' => 'Aceitos SMTP', 'suppressed' => 'Bloqueados', 'uncertain' => 'Não confirmados', 'canceled' => 'Cancelados'] as $key => $caption)
                                    <div><small>{{ $caption }}</small><strong>{{ number_format((int) ($stats[$key] ?? 0), 0, ',', '.') }}</strong></div>
                                @endforeach
                            </div>
                            @if ($selected->status === 'draft')
                                <div class="ecm2-information"><strong>Campanha em rascunho</strong><p>Você pode editar destinatários e conteúdo. Depois revise as regras de descadastro e autorize o envio quando o ambiente estiver pronto.</p></div>
                            @elseif ($selected->status === 'running')
                                <div class="ecm2-information"><strong>Campanha em andamento</strong><p>O agendador processa os envios conforme os limites configurados. Você pode pausar novas reservas a qualquer momento.</p></div>
                            @else
                                <div class="ecm2-information"><strong>Histórico preservado</strong><p>Consulte os destinatários e as respostas sem modificar os registros de envio existentes.</p></div>
                            @endif
                            @if ($selected->replies()->exists())
                                <div class="ecm2-response-head"><strong>Respostas recentes</strong><button type="button" class="ecm2-text-action" wire:click="$set('detailTab', 'replies')">Ver todas →</button></div>
                                @foreach ($this->replies->take(3) as $reply)
                                    <div class="ecm2-recent-reply"><span aria-hidden="true">↩</span><div><strong>{{ $reply->from_email }}</strong><small>{{ $reply->subject ?: 'Sem assunto' }}</small></div><time>{{ $reply->received_at?->format('d/m/Y H:i') }}</time></div>
                                @endforeach
                            @endif
                        </div>
                    @elseif ($detailTab === 'recipients')
                        <div class="ecm2-table-wrap"><table class="ecm2-table"><thead><tr><th>Contato / E-mail</th><th>Empresa</th><th>Situação</th><th>Data</th></tr></thead><tbody>
                            @forelse ($this->recipients as $recipient)
                                <tr wire:key="ecm2-recipient-{{ $recipient->id }}"><td><strong>{{ $recipient->contact_name ?: 'Contato sem nome' }}</strong><small>{{ $recipient->email }}</small></td><td>{{ $recipient->company_name ?: '—' }}</td><td><span class="ecm2-pill is-delivery-{{ $recipient->status }}">{{ $delivery[$recipient->status] ?? $recipient->status }}</span>@if($recipient->replied_at) <small>↩ Respondeu</small> @endif</td><td>{{ $recipient->sent_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="ecm2-empty-cell">Nenhum destinatário registrado.</td></tr>
                            @endforelse
                        </tbody></table></div>
                        <div class="ecm2-pagination"><span>Página {{ $this->recipients->currentPage() }} / {{ $this->recipients->lastPage() }}</span><div><button type="button" wire:click="changeRecipientPage({{ $this->recipients->currentPage() - 1 }})" @disabled($this->recipients->onFirstPage())>‹</button><button type="button" wire:click="changeRecipientPage({{ $this->recipients->currentPage() + 1 }})" @disabled(! $this->recipients->hasMorePages())>›</button></div></div>
                    @elseif ($detailTab === 'message')
                        <div class="ecm2-message-tab"><div class="ecm2-preview-label">Mensagem salva · somente leitura</div><div class="ecm2-mail-preview"><div>De: {{ $selected->from_name }} &lt;{{ $selected->from_address }}&gt;</div><div>Assunto: <strong>{{ $selected->subject }}</strong></div><p>{{ $selected->body }}</p></div><p>As variáveis são substituídas por contato no envio. O descadastro é adicionado a cada mensagem comercial.</p></div>
                    @elseif ($detailTab === 'replies')
                        <div class="ecm2-table-wrap"><table class="ecm2-table"><thead><tr><th>Remetente</th><th>Assunto recebido</th><th>Data</th></tr></thead><tbody>
                            @forelse ($this->replies as $reply)
                                <tr wire:key="ecm2-reply-{{ $reply->id }}"><td><strong>{{ $reply->from_email }}</strong></td><td>{{ $reply->subject ?: '(sem assunto)' }}</td><td>{{ $reply->received_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="ecm2-empty-cell">Nenhuma resposta identificada pelo IMAP até agora.</td></tr>
                            @endforelse
                        </tbody></table></div>
                    @endif
                </section>
            @else
                <div class="ecm2-empty-workspace">
                    <div class="ecm2-eyebrow">CAMPANHA INDISPONÍVEL</div>
                    <h2>A campanha selecionada não está mais disponível.</h2>
                    <p>Ela pode ter sido excluída em outra sessão. Escolha outra campanha na lista ou volte ao início.</p>
                    <div class="ecm2-welcome-actions"><button type="button" wire:click="showOverview" class="ecm2-btn ecm2-btn-muted">← Visão geral</button></div>
                </div>
            @endif
        </main>

        <aside id="ecm7-context" class="ecm2-context" aria-label="Ações e informações da campanha">
            <div class="ecm7-context-heading"><strong>Detalhes e ações</strong><span>Operação e configurações desta campanha</span></div>
            @if ($screen === 'composer')
                <section class="ecm2-context-card"><header><h2>Checklist da campanha</h2><span class="ecm2-number">{{ (int) $hasAudience + (int) $hasContent }}/2</span></header>
                    <div class="ecm2-check {{ $hasAudience ? 'is-done' : '' }}"><span>{{ $hasAudience ? '✓' : '○' }}</span><div><strong>Destinatários</strong><small>{{ count($audienceKeys) }} contato(s) selecionado(s)</small></div><button type="button" wire:click="goToComposerStep('audience')" aria-label="Editar destinatários">Editar</button></div>
                    <div class="ecm2-check {{ $hasContent ? 'is-done' : '' }}"><span>{{ $hasContent ? '✓' : '○' }}</span><div><strong>Conteúdo</strong><small>{{ $hasContent ? 'Nome, assunto e mensagem informados' : 'Preencha nome, assunto e texto' }}</small></div><button type="button" wire:click="goToComposerStep('message')" aria-label="Editar mensagem">Editar</button></div>
                    <div class="ecm2-check"><span>○</span><div><strong>Teste e revisão</strong><small>Envie um teste para sua própria caixa</small></div><button type="button" wire:click="goToComposerStep('review')">Abrir</button></div>
                </section>
                <section class="ecm2-context-card"><header><h2>Servidor de envio</h2></header>
                    <div class="ecm2-key-value"><span>Remetente</span><strong>{{ config('campaign_mail.from_address') }}</strong></div>
                    <div class="ecm2-key-value"><span>Teste</span><strong>{{ config('campaign_mail.test_to') }}</strong></div>
                    <div class="ecm2-context-divider"></div>
                    <div class="ecm2-key-value"><span>Limite por hora</span><strong>{{ config('campaign_mail.per_hour') }}</strong></div>
                    <div class="ecm2-key-value"><span>Limite por dia</span><strong>{{ config('campaign_mail.per_day') }}</strong></div>
                    <div class="ecm2-alert-note">{{ $canStart ? 'O ambiente permite aprovação de campanhas após revisão.' : 'Modo seguro ativo: nenhuma campanha real será iniciada.' }}</div>
                </section>
            @elseif ($screen === 'detail' && $selected)
                <section class="ecm2-context-card"><header><h2>Detalhes da campanha</h2><span class="ecm2-pill is-{{ $selected->status }}">{{ $labels[$selected->status] ?? $selected->status }}</span></header>
                    <div class="ecm2-key-value"><span>Criada em</span><strong>{{ $selected->created_at?->format('d/m/Y H:i') }}</strong></div>
                    <div class="ecm2-key-value"><span>Remetente</span><strong>{{ $selected->from_address }}</strong></div>
                    <div class="ecm2-key-value"><span>Respostas para</span><strong>{{ $selected->reply_to }}</strong></div>
                    <div class="ecm2-key-value"><span>Destinatários</span><strong>{{ $totalCount }}</strong></div>
                    <div class="ecm2-key-value"><span>Aprovada em</span><strong>{{ $selected->approved_at?->format('d/m/Y H:i') ?? '—' }}</strong></div>
                </section>
                <section class="ecm2-context-card"><header><h2>Ações rápidas</h2></header>
                    <div class="ecm2-quick-actions">
                        @if ($selected->status === 'draft')
                            <button type="button" wire:click="sendTrackedTest({{ $selected->id }})"
                                wire:loading.attr="disabled" wire:target="sendTrackedTest"
                                class="ecm2-btn ecm2-btn-muted"
                                @disabled(! config('campaign_mail.imap_enabled') || ! config('campaign_mail.tracked_test_to'))>
                                ✉ Enviar teste rastreado
                            </button>
                            <div class="ecm2-alert-note">Destino: {{ config('campaign_mail.tracked_test_to') ?: 'Configure CAMPAIGN_TRACKED_TEST_TO no .env' }}. Nunca utiliza os contatos da campanha.</div>
                            @if ($lastTrackedTest)
                                <div class="ecm2-alert-note">Último teste: {{ $lastTrackedTest->replied_at ? 'Resposta identificada pelo IMAP' : ($lastTrackedTest->status === 'smtp_accepted' ? 'Aceito SMTP · aguardando resposta' : 'Envio não confirmado') }}. @if($lastTrackedTest->replied_at) {{ $lastTrackedTest->replied_at->format('d/m/Y H:i') }} @endif</div>
                            @endif
                        @endif
                        @if ($selected->status === 'draft')
                            <button type="button" wire:click="editDraft({{ $selected->id }})" class="ecm2-btn ecm2-btn-muted">✎ Editar campanha</button>
                            <label class="ecm2-legal"><input type="checkbox" wire:model.live="lawfulBasisConfirmed"> Confirmo a base legal, a elegibilidade do público e a opção de descadastro.</label>
                            <button type="button" wire:click="startCampaign({{ $selected->id }})" wire:loading.attr="disabled" wire:target="startCampaign" class="ecm2-btn ecm2-btn-primary" @disabled(! $canStart || ! $lawfulBasisConfirmed)>✓ Aprovar e iniciar</button>
                        @elseif ($selected->status === 'running')
                            <button type="button" wire:click="pauseCampaign({{ $selected->id }})" class="ecm2-btn ecm2-btn-muted">Ⅱ Pausar novos envios</button>
                        @elseif ($selected->status === 'paused')
                            <button type="button" wire:click="resumeCampaign({{ $selected->id }})" class="ecm2-btn ecm2-btn-primary" @disabled(! $canStart)>▶ Continuar campanha</button>
                        @endif
                        @if (in_array($selected->status, ['draft', 'running', 'paused'], true))
                            <button type="button" wire:click="cancelCampaign({{ $selected->id }})" wire:confirm="Cancelar esta campanha? E-mails já enviados não podem ser desfeitos." class="ecm2-btn ecm2-btn-danger">Cancelar campanha</button>
                        @endif
                    </div>
                    @unless ($canStart)
                        <div class="ecm2-alert-note">Envio real bloqueado. Para ativar futuramente é necessário configurar as permissões do ambiente e URL pública HTTPS para descadastro.</div>
                    @endunless
                </section>
            @else
                <section class="ecm2-context-card"><header><h2>Como começar</h2></header>
                    <div class="ecm2-check"><span>1</span><div><strong>Selecione seu público</strong><small>Contatos importados e manuais</small></div></div>
                    <div class="ecm2-check"><span>2</span><div><strong>Prepare a mensagem</strong><small>Personalize com nome e empresa</small></div></div>
                    <div class="ecm2-check"><span>3</span><div><strong>Revise e salve</strong><small>Sem disparar ao salvar</small></div></div>
                </section>
            @endif
            <details class="ecm6-advanced"><summary>Configuração, piloto e diagnósticos avançados</summary>
                <div class="ecm6-advanced-content">
            <section class="ecm2-context-card ecm2-server-card"><header><h2>Configuração de envio</h2></header>
                <div class="ecm2-service-line"><span aria-hidden="true">✉</span><div><strong>SMTP · {{ config('campaign_mail.mailer') }}</strong><small>{{ config('campaign_mail.from_address') }}</small></div><span class="ecm2-service-state {{ $mailEnabled ? 'is-ready' : '' }}">{{ $mailEnabled ? 'Habilitado' : 'Desabilitado' }}</span></div>
                <div class="ecm2-service-line"><span aria-hidden="true">↩</span><div><strong>IMAP · Respostas</strong><small>Leitura de novas mensagens</small></div><span class="ecm2-service-state {{ config('campaign_mail.imap_enabled') ? 'is-ready' : '' }}">{{ config('campaign_mail.imap_enabled') ? 'Habilitado' : 'Desabilitado' }}</span></div>
                <p>Estes indicadores mostram a configuração do sistema, não são testes de conexão em tempo real.</p>
            </section>
            {{-- EC_CAMPAIGNS_PILOT_V5 --}}
            @if ($screen === 'detail' && $selected && $selected->status === 'draft')
                <section class="ecm2-context-card ecm5-pilot-card" data-ec-pilot-v5>
                    <header><h2 class="ecm5-pilot-title">✉ Piloto seguro V5</h2><span class="ecm2-number">Até 3 caixas</span></header>
                    <p class="ecm5-pilot-note">Teste a fila Redis e a identificação de respostas IMAP. Usa somente a lista autorizada, nunca os destinatários comerciais da campanha.</p>
                    <div class="ecm5-pilot-targets">{{ config('campaign_mail.pilot_allowlist') ?: 'Defina CAMPAIGN_PILOT_ALLOWLIST no .env' }}</div>
                    @if (config('campaign_mail.pilot_enabled') && ! config('campaign_mail.live_enabled'))
                        <button type="button" class="ecm5-pilot-action" wire:click="queuePilot({{ $selected->id }})"
                                wire:confirm="Enviar um piloto somente para os e-mails autorizados na configuração?"
                                wire:loading.attr="disabled" wire:target="queuePilot">
                            Adicionar piloto à fila
                        </button>
                    @else
                        <p class="ecm5-pilot-disabled">Desativado por padrão. Exige CAMPAIGN_PILOT_ENABLED=true e CAMPAIGN_MAIL_LIVE_ENABLED=false.</p>
                    @endif
                    @foreach ($this->pilotHistory as $pilotTest)
                        <div class="ecm5-pilot-status" wire:key="pilot-v5-{{ $pilotTest->id }}">
                            <strong>{{ $pilotTest->test_to }}</strong>
                            <span>
                                {{ match ((string) $pilotTest->status) {
                                    'pilot_queued' => 'Aguardando fila',
                                    'pilot_sending' => 'Em processamento',
                                    'pilot_smtp_accepted' => 'Aceito pelo SMTP',
                                    'pilot_blocked' => 'Bloqueado',
                                    'pilot_queue_error' => 'Erro de fila',
                                    'pilot_uncertain' => 'Resultado incerto',
                                    default => 'Em análise',
                                } }}
                                @if ($pilotTest->replied_at) · Respondeu @endif
                            </span>
                        </div>
                    @endforeach
                </section>
            @endif
            {{-- EC_CAMPAIGNS_PILOT_V5_END --}}
            {{-- EC_CAMPAIGN_IMAP_MONITOR_V1 --}}
            <section class="ecm2-context-card ecm2-imap-health" data-imap-monitor>
                <header><h2>Monitoramento IMAP</h2>
                    <span class="ecm2-imap-status" data-state="{{ $this->imapHealth['state'] }}">{{ $this->imapHealth['label'] }}</span>
                </header>
                <div class="ecm2-imap-health-body">
                    <strong>Última sincronização IMAP</strong>
                    <span>{{ $this->imapHealth['last_sync_at'] ?? 'Ainda não registrada' }}</span>
                    <small>
                        @if ($this->imapHealth['minutes_since_sync'] !== null)
                            Há {{ $this->imapHealth['minutes_since_sync'] }} minuto(s).
                        @else
                            Aguardando primeira execução.
                        @endif
                        Consulta programada a cada {{ $this->imapHealth['expected_interval_minutes'] }} minutos.
                    </small>
                    <div class="ecm2-imap-totals">
                        <div><span>Testes respondidos</span><b>{{ $this->imapHealth['tracked_replies'] }}</b></div>
                        <div><span>Respostas comerciais</span><b>{{ $this->imapHealth['campaign_replies'] }}</b></div>
                    </div>
                    <button type="button" wire:click="refreshImapHealth" wire:loading.attr="disabled"
                            wire:target="refreshImapHealth" class="ecm2-imap-refresh">
                        ↻ Atualizar indicador
                    </button>
                    <p>Este indicador usa o histórico do banco. Não testa a conexão com o servidor em tempo real nem envia e-mails.</p>
                </div>
            </section>
                        {{-- EC_CAMPAIGN_QUEUE_LAB_V2 --}}
            <livewire:campaign-queue-lab />
            <section class="ecm2-context-card ecm2-context-help"><strong>Segurança do envio</strong><p>Limites por hora e dia, aprovação explícita e descadastro. Aceitação SMTP não comprova entrega ou leitura.</p></section>
                </div>
            </details>
        </aside>
    </div>
    <footer class="ecm2-footer"><span aria-hidden="true">●</span> Dados locais · SMTP individual · IMAP por cabeçalhos · Sem métricas fictícias de abertura</footer>
</div>
