<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignImapHealth;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignPilot;
use App\Services\Campaigns\CampaignSendPolicy;
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

    public string $plannedHours = '8';

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

    // EC_CAMPAIGNS_REVIEW_AND_CONFIRM_V73: revisão legível, sem enviar ao abrir.
    public int $reviewRecipientsPage = 1;

    public bool $sendConfirmationOpen = false;

    public int $campaignTablePage = 1;

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
                // Abre o ponto de partida; não força a campanha antiga sobre o usuário.
                $this->screen = 'overview';
                $this->detailTab = 'overview';
            }
        }
    }

    private function assertManager(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);

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
        $this->campaignTablePage = 1;
        unset($this->campaigns);
    }

    public function updatedCampaignStatus(): void
    {
        $this->campaignTablePage = 1;
        unset($this->campaigns);
    }

    public function changeCampaignTablePage(int $page): void
    {
        $this->campaignTablePage = max(1, min(5, $page));
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

    /**
     * Limita consultas do painel de prévia à página atual; não resolve milhares
     * de endereços durante uma única renderização do Livewire.
     */
    public function reviewRecipientsPerPage(): int
    {
        return min(15, max(1, (int) config('campaign_mail.max_recipients', 100)));
    }

    public function reviewRecipientPageCount(): int
    {
        return max(1, (int) ceil(count($this->audienceKeys) / $this->reviewRecipientsPerPage()));
    }

    /**
     * @return list<array{source_key:string,email:string,normalized_email:string,contact_name:string,company_name:string,job_title:string,suppressed:bool}>
     */
    #[Computed]
    public function reviewRecipientPreview(): array
    {
        $this->assertManager();
        if ($this->screen !== 'composer' || $this->audienceKeys === []) {
            return [];
        }
        $page = min(max(1, $this->reviewRecipientsPage), $this->reviewRecipientPageCount());
        $offset = ($page - 1) * $this->reviewRecipientsPerPage();
        $keys = array_slice($this->audienceKeys, $offset, $this->reviewRecipientsPerPage());

        // Mesma validação de e-mail, duplicatas e opt-out do salvamento real.
        return app(CampaignAudience::class)->resolve($keys);
    }

    public function changeReviewRecipientsPage(int $page): void
    {
        $this->assertManager();
        $this->reviewRecipientsPage = max(1, min($this->reviewRecipientPageCount(), $page));
        unset($this->reviewRecipientPreview);
    }

    /** Abre a confirmação visual, sem salvar, reservar ou enviar mensagens. */
    public function requestSendConfirmation(): void
    {
        $this->assertManager();
        if ($this->screen !== 'composer' || ! in_array($this->composerStep, ['pace', 'review'], true)) {
            $this->addError('campaign', 'Conclua a etapa de revisão antes de confirmar o envio.');

            return;
        }
        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
            'audienceKeys' => ['required', 'array', 'min:1'],
            'audienceKeys.*' => ['string', 'max:160', 'regex:/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/'],
        ]);
        if (! $this->lawfulBasisConfirmed) {
            $this->addError('lawfulBasisConfirmed', 'Confirme a base legal e o descadastro antes de enviar.');

            return;
        }
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')
            || ! app(\App\Services\Campaigns\CampaignUnsubscribeLink::class)->isReady()) {
            $this->addError('campaign', 'O envio ainda não está habilitado. Confira o SMTP e o descadastro público.');

            return;
        }
        if (! $this->pacePreview['within_policy']) {
            $this->addError('campaign', 'O ritmo solicitado ultrapassa os limites ativos.');

            return;
        }
        $this->sendConfirmationOpen = true;
    }

    public function cancelSendConfirmation(): void
    {
        $this->sendConfirmationOpen = false;
    }

    /**
     * Confirmação expressa: preserva o fluxo de salvamento/aprovação do CampaignManager.
     * A V74 exibe falhas reais sem devolver um 500 genérico ao usuário.
     * Nunca repete a operação, descarta um rascunho existente ou aciona fila.
     */
    public function confirmSendCampaign(CampaignManager $manager): void
    {
        $this->assertManager();
        abort_unless($this->sendConfirmationOpen, 422);
        $this->sendConfirmationOpen = false;

        // EC_CAMPAIGN_CONFIRMATION_DIAGNOSTIC_V74
        try {
            $this->saveAndStart($manager);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            // Mostra a mensagem de negócio do backend, em vez de esconder a causa.
            $this->setErrorBag($exception->errors());
        } catch (\InvalidArgumentException $exception) {
            report($exception);
            $maxSelected = min(1000, max(1, (int) config('campaign_mail.max_recipients', 100)));
            $this->addError(
                'recipients',
                'Não foi possível validar os destinatários. O limite efetivo por campanha é '
                .$maxSelected.' e-mail(s). Revise a seleção e tente novamente somente após conferir o rascunho.'
            );
        } catch (\Throwable $exception) {
            // A exceção fica no laravel.log com stack trace para diagnóstico.
            // Como saveDraft e start executam transações distintas, verificar
            // o status ANTES de mostrar um resultado. Não fazer nova tentativa.
            report($exception);
            $currentStatus = null;
            if ($this->selectedCampaignId !== null) {
                try {
                    $currentStatus = EmailCampaign::query()
                        ->whereKey($this->selectedCampaignId)
                        ->value('status');
                } catch (\Throwable $lookupError) {
                    report($lookupError);
                }
            }

            if ($currentStatus === 'running') {
                session()->flash(
                    'campaign_success',
                    'A campanha foi autorizada, mas houve erro ao atualizar a tela. '
                    .'Não confirme novamente. Consulte a campanha e a fila antes de qualquer ação.'
                );
            } elseif ($currentStatus === 'draft') {
                $this->addError(
                    'campaign',
                    'O rascunho foi salvo, mas o envio não foi autorizado. '
                    .'Confira o erro no laravel.log. Não repita a confirmação sem revisar o rascunho.'
                );
            } else {
                $this->addError(
                    'campaign',
                    'Houve um erro interno na confirmação. O resultado precisa ser conferido '
                    .'nos registros das campanhas e no laravel.log antes de tentar novamente.'
                );
            }
        }
    }

    public function newCampaign(): void
    {
        $this->assertManager();
        $this->sendConfirmationOpen = false;
        $this->reviewRecipientsPage = 1;
        $this->reset(['editingCampaignId', 'selectedCampaignId', 'name', 'subject', 'body',
            'audienceSearch', 'audienceOrigin', 'audiencePage', 'audienceKeys', 'lawfulBasisConfirmed']);
        $this->screen = 'composer';
        $this->composerStep = 'audience';
        $this->plannedHours = '8';
        $this->applyTemplate('initial');
        $this->resetValidation();
    }

    public function showOverview(): void
    {
        $this->assertManager();
        $this->sendConfirmationOpen = false;
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
        $this->sendConfirmationOpen = false;
        $this->reviewRecipientsPage = 1;
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
        $this->plannedHours = '8';
        $this->resetValidation();
    }

    /** Avança na preparação sem disparar nem salvar mensagens. */
    public function goToComposerStep(string $step): void
    {
        $this->assertManager();
        abort_unless(in_array($step, ['audience', 'message', 'pace', 'review'], true), 422);
        $this->resetValidation();

        if (in_array($step, ['message', 'pace', 'review'], true) && $this->audienceKeys === []) {
            $this->addError('recipients', 'Selecione pelo menos um contato antes de continuar.');
            $this->composerStep = 'audience';

            return;
        }

        if (in_array($step, ['pace', 'review'], true)) {
            $this->validate([
                'name' => ['required', 'string', 'min:3', 'max:180'],
                'subject' => ['required', 'string', 'min:3', 'max:180'],
                'body' => ['required', 'string', 'min:10', 'max:12000'],
            ]);
        }

        if ($step === 'review') {
            $this->validate(['plannedHours' => ['required', 'in:1,2,4,8,12,24']]);
        }
        $this->composerStep = $step;
    }

    /** Simulação da duração solicitada. Não altera a política real de envio.
     * @return array{total:int,hours:int,required_hour:int,required_minute:int,allowed_hour:int,allowed_minute:int,allowed_day:int,max_recipients:int,possible:bool,within_policy:bool}
     */
    #[Computed]
    public function pacePreview(): array
    {
        $this->assertManager();
        $total = count($this->audienceKeys);
        $hours = max(1, (int) $this->plannedHours);
        $policy = app(CampaignSendPolicy::class);
        $quote = $policy->quote($total, $hours);
        $effective = $policy->effective();
        $possible = $quote['possible'] && $total <= (int) config('campaign_mail.max_recipients', 100);
        $withinPolicy = $possible
            && $total <= $effective['day']
            && $quote['hourly_limit'] <= $effective['hour']
            && $quote['per_tick_limit'] <= $effective['tick']
            && (! $effective['window_active'] || $hours <= $effective['duration_hours']);

        return [
            'total' => $total,
            'hours' => $hours,
            'required_hour' => $quote['hourly_limit'],
            'required_minute' => $quote['per_tick_limit'],
            'allowed_hour' => $effective['hour'],
            'allowed_minute' => $effective['tick'],
            'allowed_day' => $effective['day'],
            'max_recipients' => (int) config('campaign_mail.max_recipients', 100),
            'possible' => $possible,
            'within_policy' => $withinPolicy,
        ];
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
        // EC77_CAMPAIGN_SELECTION: seleção em lote não inclui endereços bloqueados.
        $people = $this->audience->items();
        $emails = array_values(array_unique(array_map(
            static fn (object $p): string => mb_strtolower(trim((string) $p->email)), $people,
        )));
        $blocked = $emails === [] ? [] : \App\Models\EmailCampaignSuppression::query()
            ->whereIn('normalized_email', $emails)->pluck('normalized_email')->all();
        $pageKeys = array_values(array_map(
            static fn (object $p): string => (string) $p->source_key,
            array_filter($people, static fn (object $p): bool => ! in_array(
                mb_strtolower(trim((string) $p->email)), $blocked, true,
            )),
        ));
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
            $eligible = array_values(array_map(
                static fn (array $row): string => $row['source_key'],
                array_filter(app(CampaignAudience::class)->resolve($keys),
                    static fn (array $row): bool => ! $row['suppressed']),
            ));
            $combined = array_values(array_unique(array_merge($this->audienceKeys, $eligible)));
            if (count($combined) > (int) config('campaign_mail.max_recipients', 100)) {
                throw new InvalidArgumentException('O total selecionado ultrapassou o limite.');
            }
            $this->audienceKeys = $combined;
        } catch (InvalidArgumentException $error) {
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
            'audienceKeys.*' => ['string', 'max:160', 'regex:/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/'],
        ]);
        $campaign = $manager->saveDraft($user, [
            'name' => $validated['name'], 'subject' => $validated['subject'], 'body' => $validated['body'],
        ], array_values($validated['audienceKeys']), $this->editingCampaignId !== null
            ? EmailCampaign::query()->findOrFail($this->editingCampaignId) : null);
        session()->flash('campaign_success', 'Rascunho salvo. Nenhum e-mail foi enviado.');
        unset($this->overview, $this->campaigns);
        $this->openCampaign((int) $campaign->id);
    }

    /**
     * EC_SIMPLE_CAMPAIGNS_2026_V1
     * Disparo explícito: primeiro salva rascunho, depois usa a mesma
     * aprovação e todas as proteções já existentes no CampaignManager.
     * A duração escolhida é uma simulação; a fila usa a política global.
     */
    public function saveAndStart(CampaignManager $manager): void
    {
        $this->assertManager();
        if (! $this->lawfulBasisConfirmed) {
            $this->addError('lawfulBasisConfirmed', 'Confirme a base legal e os descadastros antes de enviar.');

            return;
        }
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
            $this->addError('campaign', 'Envio real bloqueado. Salve o rascunho ou consulte as configurações.');

            return;
        }
        if (! $this->pacePreview['within_policy']) {
            $this->addError('campaign', 'O ritmo desejado não é compatível com os limites ativos.');

            return;
        }
        $this->saveDraft($manager);
        if ($this->selectedCampaignId !== null) {
            $this->lawfulBasisConfirmed = true;
            $this->startCampaign($this->selectedCampaignId, $manager);
        }
    }

    // EC_CAMPAIGN_REDESIGN_V21: ações de editor, sem operações externas.
    public function removeAudienceKey(string $key): void
    {
        $this->assertManager();
        $this->audienceKeys = array_values(array_filter(
            $this->audienceKeys,
            static fn (string $selected): bool => $selected !== $key,
        ));
    }

    public function appendTemplateVariable(string $token): void
    {
        $this->assertManager();
        abort_unless(in_array($token, ['nome', 'primeiro_nome', 'empresa', 'cargo'], true), 422);
        $this->body .= ($this->body === '' || str_ends_with($this->body, "\n") ? '' : ' ').'{{'.$token.'}}';
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
        } catch (Throwable $exception) {
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
    // Mesma URL de descadastro usada no CampaignManager.
    $appUrl = (string) (app(\App\Services\Campaigns\CampaignUnsubscribeLink::class)->base() ?? '');
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

<div class="ecm2 ec21-page ec22-page ec68-page ec69-page" data-ec-campaigns-simple="M01-M10-V69" data-ec-campaigns-figma="M01-M10-V68" data-ec21="EC_CAMPAIGN_REDESIGN_V21" data-ec22-screen="{{ $screen }}" @if ($screen === 'detail') wire:poll.60s @endif>
    <header class="ecm2-head">
        <div>
            <div class="ecm2-breadcrumb">CAMPANHAS</div>
            <h1>Campanhas de E-mail</h1>
            <p>Prepare mensagens, acompanhe a fila e continue as conversas.</p>
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
            <a href="{{ route('campaigns.replies') }}" wire:navigate class="ec68-replies-shortcut">Ver respostas</a>
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

    <details class="ec-simple-metrics-details"><summary>Ver indicadores das campanhas (avançado)</summary>
    <section class="ecm2-stats" aria-label="Indicadores reais das campanhas">
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-blue">▤</span><div><small>Rascunhos</small><strong>{{ number_format($counts['drafts'], 0, ',', '.') }}</strong><span>Aguardando preparação</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-amber">◷</span><div><small>Na fila ativa</small><strong>{{ number_format($counts['queue'], 0, ',', '.') }}</strong><span>Aguardando envio real</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-cyan">➤</span><div><small>Aceitos hoje</small><strong>{{ number_format($counts['sent_today'], 0, ',', '.') }}</strong><span>Pelo servidor SMTP</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-green">↩</span><div><small>Respostas</small><strong>{{ number_format($counts['replies'], 0, ',', '.') }}</strong><span>Registradas pelo IMAP</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-red">!</span><div><small>Não confirmados</small><strong>{{ number_format($counts['uncertain'], 0, ',', '.') }}</strong><span>Conferir tentativas</span></div></div>
    </section>
    </details>

    @if ($screen === 'overview')
        @include('campaigns.figma-home-v68')
    @elseif ($screen === 'detail' && $selected)
        @include('campaigns.figma-detail-header-v761')
    @endif

    <div class="ecm2-shell ec21-shell is-{{ $screen }}">
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
                {{-- EC22: home simples, sem métricas inventadas nem controles técnicos. --}}
                <section class="ec22-home" data-ec22-section="home">
                    <div class="ec22-home-hero">
                        <div class="ec22-home-hero-top">
                            <div>
                                <span class="ec22-kicker">CAMPANHAS / COMEÇAR</span>
                                <h2>Crie sua próxima campanha de e-mail</h2>
                                <p>Crie sua campanha em poucos passos. Escolha os destinatários, escreva o texto e revise antes de enviar.</p>
                            </div>
                            <div class="ec22-hero-symbol" aria-hidden="true">✉ <span>➤</span></div>
                        </div>
                        <ol class="ec22-home-steps" aria-label="Etapas da campanha">
                            <li><span>01</span><div><strong>Selecionar contatos</strong><small>Encontre destinatários na base de contatos.</small></div></li>
                            <li><span>02</span><div><strong>Escrever mensagem</strong><small>Personalize o assunto e o texto do e-mail.</small></div></li>
                            <li><span>03</span><div><strong>Ritmo e revisão</strong><small>Confira os limites, faça um teste e salve.</small></div></li>
                        </ol>
                        <div class="ec22-home-cta">
                            <button type="button" class="ec22-button ec22-button-primary" wire:click="newCampaign">＋ Começar campanha <span aria-hidden="true">→</span></button>
                            <a href="{{ route('contacts.index') }}" wire:navigate>Consultar contatos →</a>
                        </div>
                    </div>
                    <div class="ec22-home-templates">
                        <div class="ec22-section-head"><div><h3>Modelos prontos para começar</h3><p>Você pode editar a mensagem antes de salvar a campanha.</p></div></div>
                        <div class="ec22-home-template-grid">
                            <button type="button" wire:click="startWithTemplate('initial')"><span aria-hidden="true">✉</span><strong>Apresentação</strong><small>Primeiro contato com uma empresa.</small><em>Usar modelo →</em></button>
                            <button type="button" wire:click="startWithTemplate('followup')"><span aria-hidden="true">↩</span><strong>Retomada</strong><small>Retome uma conversa com contexto.</small><em>Usar modelo →</em></button>
                            <button type="button" wire:click="startWithTemplate('meeting')"><span aria-hidden="true">▣</span><strong>Agendamento</strong><small>Convide para uma apresentação.</small><em>Usar modelo →</em></button>
                        </div>
                    </div>
                    <div class="ec22-home-footnote">Os resultados de envio refletem registros reais do SMTP e IMAP. Não há contagem de aberturas sem rastreamento validado.</div>
                </section>
            @elseif ($screen === 'composer')
                {{-- EC22: um único assistente de três telas, sem painel lateral de campanhas. --}}
                <section class="ec22-wizard" data-ec22-section="composer" data-ec22-step="{{ $composerStep }}">
                    <header class="ec22-wizard-head">
                        <div>
                            <span class="ec22-kicker">CAMPANHAS DE E-MAIL / {{ $editingCampaignId ? 'EDITAR RASCUNHO' : 'NOVA CAMPANHA' }}</span>
                            <h1>{{ match ($composerStep) { 'audience' => 'Selecione os contatos', 'message' => 'Escreva a mensagem', default => 'Ritmo e revisão' } }}</h1><small class="ec68-composer-subtitle">Nova campanha</small>
                            <p>{{ match ($composerStep) { 'audience' => 'Escolha quem vai receber seu e-mail.', 'message' => 'Escreva sua mensagem e confira a prévia.', default => 'Confira o ritmo e revise antes de solicitar o envio.' } }}</p>
                        </div>
                        <button type="button" class="ec22-button ec22-button-outline" wire:click="showOverview">Cancelar e voltar</button>
                    </header>
                    @php $ec22Step = ['audience' => 1, 'message' => 2, 'pace' => 3, 'review' => 3][$composerStep] ?? 1; @endphp
                    <nav class="ec22-stepper" aria-label="Etapas da criação">
                        @foreach ([['audience', 1, 'Selecionar contatos'], ['message', 2, 'Escrever mensagem'], ['pace', 3, 'Ritmo e revisão']] as [$stepKey, $index, $stepName])
                            <button type="button" class="{{ $ec22Step === $index ? 'is-current' : ($ec22Step > $index ? 'is-done' : '') }}" wire:click="goToComposerStep(@js($stepKey))" aria-current="{{ $ec22Step === $index ? 'step' : 'false' }}"><b>{{ $ec22Step > $index ? '✓' : $index }}</b><span><strong>{{ $stepName }}</strong><small>{{ match ($index) { 1 => 'Quem vai receber', 2 => 'O que será enviado', default => 'Quando e como enviar' } }}</small></span></button>
                        @endforeach
                    </nav>
                    @if ($composerStep === 'audience')
                        <div class="ec22-two-columns ec22-audience-layout">
                            <section class="ec22-card ec22-audience-card">
                                <header class="ec22-card-head"><div><h2>Escolha os contatos</h2><p>Selecione na base. Você poderá revisar a lista antes de salvar.</p></div><span class="ec22-count">{{ number_format($this->audience->total(), 0, ',', '.') }} encontrados</span></header>
                                <label class="ec22-search"><span aria-hidden="true">⌕</span><input aria-label="Buscar contatos" type="search" wire:model.live.debounce.400ms="audienceSearch" placeholder="Buscar por nome, empresa ou e-mail..."></label>
                                <div class="ec22-tabs" role="group" aria-label="Origem dos contatos">
                                    @foreach (['all' => 'Todos', 'hubspot' => 'HubSpot', 'manual' => 'Cadastro manual', 'receita' => 'Receita Federal'] as $key => $caption)
                                        <button type="button" class="{{ $audienceOrigin === $key ? 'is-active' : '' }}" wire:click="$set('audienceOrigin', @js($key))" aria-pressed="{{ $audienceOrigin === $key ? 'true' : 'false' }}">{{ $caption }}</button>
                                    @endforeach
                                </div>
                                <div class="ec22-recipient-tools"><button type="button" wire:click="toggleAudiencePage">Selecionar página</button><button type="button" wire:click="addAllFiltered">Adicionar resultados filtrados</button><button type="button" wire:click="$set('audienceKeys', [])" @disabled($audienceKeys === [])>Limpar seleção</button></div>
                                <div class="ec22-table-scroll"><table class="ec22-contact-table"><thead><tr><th scope="col">✓</th><th scope="col">Contato / e-mail</th><th scope="col">Empresa</th><th scope="col">Origem</th></tr></thead><tbody>
                                @php
                                    $ec77OnPage = array_map(
                                        static fn (object $row): string => mb_strtolower(trim((string) $row->email)),
                                        $this->audience->items(),
                                    );
                                    $ec77BlockedEmails = $ec77OnPage === [] ? [] : \App\Models\EmailCampaignSuppression::query()
                                        ->whereIn('normalized_email', $ec77OnPage)
                                        ->pluck('reason', 'normalized_email')->all();
                                @endphp
                                @forelse ($this->audience as $person)
                                    @php
                                        $ec22PersonName = trim((string) $person->person_name);
                                        $ec22Email = (string) $person->email;
                                        $ec22Picked = in_array((string) $person->source_key, $audienceKeys, true);
                                        $ec77BlockReason = $ec77BlockedEmails[mb_strtolower(trim($ec22Email))] ?? null;
                                        $ec77OptedOut = $ec77BlockReason === 'unsubscribe';
                                    @endphp
                                    <tr class="{{ $ec22Picked ? 'is-picked' : '' }} {{ $ec77OptedOut ? 'ec77-unsubscribed-row' : '' }}" wire:key="ec22-contact-{{ $person->source_key }}"><td><input type="checkbox" wire:model.live="audienceKeys" value="{{ $person->source_key }}" aria-label="Selecionar {{ $ec22Email }}" @disabled($ec77BlockReason !== null)></td><td><div class="ec22-person-cell"><span class="ec22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($ec22PersonName ?: $ec22Email, 0, 1)) }}</span><span><strong>{{ $ec22PersonName ?: $ec22Email }}</strong>@if($ec22PersonName)<small>{{ $ec22Email }}</small>@else<small>Nome não informado</small>@endif
                                            @if ($ec77OptedOut)<small class="ec77-block-reason">Descadastrado · envio impedido</small>@elseif ($ec77BlockReason !== null)<small class="ec77-block-reason">Endereço bloqueado para campanhas</small>@endif</span></div></td><td>{{ trim((string) $person->company_name) ?: 'Não informada' }}</td><td><span class="ec22-origin">{{ match ($person->origin) { 'hubspot' => 'HubSpot', 'receita' => 'Receita Federal', default => 'Manual' } }}</span></td></tr>
                                @empty
                                    <tr><td colspan="4" class="ec22-table-empty">Nenhum contato com e-mail encontrado.</td></tr>
                                @endforelse
                                </tbody></table></div>
                                <footer class="ec22-table-footer"><span>Página {{ $this->audience->currentPage() }} de {{ $this->audience->lastPage() }} • {{ number_format($this->audience->total(), 0, ',', '.') }} com e-mail</span><div><button type="button" wire:click="changeAudiencePage({{ $this->audience->currentPage() - 1 }})" @disabled($this->audience->onFirstPage())>← Anterior</button><button type="button" wire:click="changeAudiencePage({{ $this->audience->currentPage() + 1 }})" @disabled(! $this->audience->hasMorePages())>Próxima →</button></div></footer>
                            </section>
                            <aside class="ec22-card ec22-selection-card">
                                <h2>Destinatários selecionados</h2><div class="ec22-big-count">{{ count($audienceKeys) }}</div><p>contato(s) na seleção</p>
                                <div class="ec22-info-note">Só endereços válidos e não bloqueados poderão entrar na campanha. Duplicados serão deduplicados ao salvar.</div>
                                @php $ec22OnPage = collect($this->audience->items())->filter(fn ($p) => in_array((string) $p->source_key, $audienceKeys, true))->take(6); @endphp
                                <div class="ec22-picks">
                                    @foreach ($ec22OnPage as $person)
                                        <div class="ec22-pick" wire:key="ec22-picked-{{ $person->source_key }}"><span class="ec22-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) $person->person_name) ?: (string) $person->email, 0, 1)) }}</span><span><strong>{{ trim((string) $person->person_name) ?: (string) $person->email }}</strong><small>{{ $person->email }}</small></span><button type="button" wire:click="removeAudienceKey(@js($person->source_key))" aria-label="Remover {{ $person->email }}">×</button></div>
                                    @endforeach
                                </div>
                                @if (count($audienceKeys) > $ec22OnPage->count())<p class="ec22-secondary">Mais destinatários estão selecionados em outras páginas.</p>@endif
                                <p class="ec22-limit">Limite por campanha: <strong>{{ (int) config('campaign_mail.max_recipients', 100) }}</strong></p>
                                @error('recipients')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                                @error('audienceKeys')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                            </aside>
                        </div>
                        <footer class="ec22-footer"><button type="button" class="ec22-button ec22-button-outline" wire:click="showOverview">Cancelar</button><button type="button" class="ec22-button ec22-button-primary" wire:click="goToComposerStep('message')">Continuar →</button></footer>
                    @elseif ($composerStep === 'message')
                        <div class="ec22-two-columns ec22-message-layout">
                            <section class="ec22-card ec22-editor-card">
                                <header class="ec22-card-head"><div><h2>Escreva sua mensagem</h2><p>Defina o nome interno, o assunto e o conteúdo.</p></div></header>
                                <div class="ec22-input"><label for="ec22-campaign-name">Nome interno da campanha</label><input id="ec22-campaign-name" type="text" wire:model.blur="name" maxlength="180" placeholder="Ex.: Apresentação ExportControl">@error('name')<small role="alert">{{ $message }}</small>@enderror</div>
                                <div class="ec22-input"><label for="ec22-subject">Assunto do e-mail</label><input id="ec22-subject" type="text" wire:model.live.debounce.400ms="subject" maxlength="180" placeholder="Escreva um assunto claro...">@error('subject')<small role="alert">{{ $message }}</small>@enderror</div>
                                <div class="ec22-token-row"><strong>Personalizar:</strong>@foreach (['primeiro_nome' => 'Primeiro nome', 'nome' => 'Nome', 'empresa' => 'Empresa', 'cargo' => 'Cargo'] as $token => $caption)<button type="button" wire:click="appendTemplateVariable(@js($token))" title="Inserir {{ $caption }} ao final da mensagem">&#123;&#123;{{ $token }}&#125;&#125;</button>@endforeach</div>
                                <div class="ec22-input"><label for="ec22-body">Mensagem</label><textarea id="ec22-body" rows="15" maxlength="12000" wire:model.live.debounce.500ms="body" placeholder="Escreva aqui sua mensagem personalizada..."></textarea><small>Editor de texto simples, sem HTML. {{ mb_strlen($body) }}/12.000 caracteres.</small>@error('body')<small role="alert">{{ $message }}</small>@enderror</div>
                                <div class="ec22-quick-templates"><strong>Usar modelo:</strong><button type="button" wire:click="applyTemplate('initial')">Apresentação</button><button type="button" wire:click="applyTemplate('followup')">Retomada</button><button type="button" wire:click="applyTemplate('meeting')">Agendamento</button></div>
                                @if ($errors->any())<div class="ec22-error" role="alert"><strong>Não foi possível avançar. Confira os campos:</strong><ul>@foreach ($errors->all() as $messageError)<li>{{ $messageError }}</li>@endforeach</ul></div>@endif
                            </section>
                            <aside class="ec22-card ec22-preview-card">
                                <header class="ec22-card-head"><div><h2>Pré-visualização</h2><p>Exemplo de como o texto aparecerá ao destinatário.</p></div></header>
                                @php
                                    $ec22PreviewBody = strtr((string) $body, ['{{primeiro_nome}}' => 'João', '{{nome}}' => 'João Exemplo', '{{empresa}}' => 'Empresa Exemplo', '{{cargo}}' => 'Responsável']);
                                    $ec22PreviewSubject = strtr((string) $subject, ['{{primeiro_nome}}' => 'João', '{{nome}}' => 'João Exemplo', '{{empresa}}' => 'Empresa Exemplo', '{{cargo}}' => 'Responsável']);
                                @endphp
                                <div class="ec22-email-paper"><div class="ec22-email-headers"><div><span>De:</span> {{ config('campaign_mail.from_address') ?: 'Conta de envio não configurada' }}</div><div><span>Para:</span> contato@empresa.example (exemplo)</div><div><span>Assunto:</span> {{ $ec22PreviewSubject ?: '(sem assunto)' }}</div></div><div class="ec22-email-body">{{ $ec22PreviewBody ?: 'Escreva uma mensagem para visualizar a prévia.' }}</div></div>
                                <div class="ec22-tip"><strong>Antes de enviar</strong><p>Use um assunto direto, revise as variáveis e envie um teste para a sua caixa. O link de descadastro é gerado pelo sistema.</p></div>
                            </aside>
                        </div>
                        <footer class="ec22-footer"><button type="button" class="ec22-button ec22-button-outline" wire:click="goToComposerStep('audience')">← Voltar</button><button type="button" class="ec22-button ec22-button-primary" wire:click="goToComposerStep('pace')">Continuar →</button></footer>
                    @else
                        <div class="ec22-two-columns ec22-final-layout">
                            <section class="ec22-card ec22-rhythm-card">
                                <header class="ec22-card-head"><div><h2>Em quanto tempo você quer enviar?</h2><p>Escolha um prazo desejado. A fila real seguirá a política autorizada.</p></div></header>
                                <p class="ec22-secondary">{{ count($audienceKeys) }} destinatário(s) selecionado(s). Limite desta campanha: {{ (int) config('campaign_mail.max_recipients', 100) }}.</p>
                                <h3 class="ec22-subheading">Duração desejada</h3>
                                <div class="ec22-duration-grid" role="group" aria-label="Duração desejada">
                                    @foreach ([1, 2, 4, 8, 12, 24] as $h)<button type="button" wire:click="$set('plannedHours', @js((string) $h))" class="{{ (int) $plannedHours === $h ? 'is-active' : '' }}" aria-pressed="{{ (int) $plannedHours === $h ? 'true' : 'false' }}">{{ $h }} {{ $h === 1 ? 'hora' : 'horas' }}</button>@endforeach
                                </div>
                                <div class="ec22-current-policy"><div><strong>Limites atuais de envio</strong><p>Esses valores são globais. O prazo que você escolher não os altera.</p></div><dl><div><dt>Por hora</dt><dd>{{ $this->pacePreview['allowed_hour'] }}</dd></div><div><dt>Por minuto</dt><dd>{{ $this->pacePreview['allowed_minute'] }}</dd></div><div><dt>Em 24 horas</dt><dd>{{ $this->pacePreview['allowed_day'] }}</dd></div></dl><a href="{{ route('campaigns.settings') }}" target="_blank" rel="noopener">Ver configurações gerais ↗</a></div>
                                @if (! $this->pacePreview['possible'])<div class="ec22-warning" role="alert">A quantidade ou o prazo ultrapassa o teto técnico ou o limite de destinatários. Ajuste antes de enviar.</div>
                                @elseif (! $this->pacePreview['within_policy'])<div class="ec22-warning" role="status">O prazo escolhido ultrapassa os limites atuais. Salve como rascunho ou revise as configurações autorizadas.</div>
                                @else<div class="ec22-success" role="status">Ritmo teórico compatível com a política atual. Filas, janelas móveis e SMTP podem prolongar o envio.</div>@endif
                            </section>
                            <aside class="ec22-card ec22-pace-summary"><header class="ec22-card-head"><div><h2>Previsão do seu envio</h2><p>Simulação, não agendamento.</p></div></header>
                                <div class="ec22-metric-grid"><div><span>Necessário por hora</span><strong>{{ $this->pacePreview['required_hour'] }}</strong></div><div><span>Necessário por minuto</span><strong>{{ $this->pacePreview['required_minute'] }}</strong></div><div><span>Duração desejada</span><strong>{{ $this->pacePreview['hours'] }}h</strong></div><div><span>Contatos</span><strong>{{ count($audienceKeys) }}</strong></div></div>
                                <div class="ec22-info-note">O início e a duração efetivos dependem do agendador e dos limites SMTP. Este prazo não fica agendado individualmente.</div>
                            </aside>
                        </div>
                        <section class="ec22-card ec22-review-card">
                            <header class="ec22-card-head"><div><h2>Revisão final</h2><p>Confira antes de salvar ou solicitar o envio.</p></div><button type="button" class="ec22-button ec22-button-outline" wire:click="goToComposerStep('message')">Editar mensagem</button></header>
                            <div class="ec22-review-grid"><div><span>Campanha</span><strong>{{ $name ?: '(sem nome)' }}</strong></div><div><span>Destinatários selecionados</span><strong>{{ count($audienceKeys) }}</strong></div><div><span>Remetente</span><strong>{{ config('campaign_mail.from_address') ?: 'Não configurado' }}</strong></div><div><span>Assunto</span><strong>{{ $subject ?: '(sem assunto)' }}</strong></div></div>
                            @include('campaigns.review-recipients-v73')
                            <div class="ec22-test-block ec73-test-block" data-ec73-test-block>
                                <div class="ec73-test-content">
                                    <span class="ec73-test-label">TESTE DE MENSAGEM</span>
                                    <strong>Envio de teste</strong>
                                    <p>Confira como a mensagem chegará à sua caixa. Nenhum dos contatos selecionados receberá esse teste.</p>
                                    <div class="ec73-test-target">Destino: <b>{{ config('campaign_mail.test_to') ?: 'E-mail de teste não configurado' }}</b></div>
                                    <small>O teste é separado do envio da campanha e não altera seus destinatários.</small>
                                </div>
                                <button type="button" class="ec22-button ec73-test-action" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" @disabled(! $mailEnabled)>
                                    <span wire:loading.remove wire:target="sendTest">✉ Enviar teste</span>
                                    <span wire:loading wire:target="sendTest">Enviando teste…</span>
                                </button>
                            </div>
                            <label class="ec22-consent"><input type="checkbox" wire:model.live="lawfulBasisConfirmed"> Confirmo que revisei os destinatários, a base legal do contato e a política de descadastro.</label>
                            @unless ($canStart)<div class="ec22-warning" role="status"><strong>Modo seguro ativo.</strong> Você pode salvar o rascunho agora. Para enviar, configure seu SMTP e o descadastro em <a href="{{ route('campaigns.settings') }}" target="_blank" rel="noopener noreferrer">Preparar envio</a>.</div>@endunless
                            @error('campaign')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                            @error('lawfulBasisConfirmed')<div class="ec22-error" role="alert">{{ $message }}</div>@enderror
                        </section>
                        <footer class="ec22-footer"><button type="button" class="ec22-button ec22-button-outline" wire:click="goToComposerStep('message')">← Voltar</button><div><button type="button" class="ec22-button ec22-button-outline" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">Salvar rascunho</button><button type="button" class="ec22-button ec22-button-primary" wire:click="requestSendConfirmation" wire:loading.attr="disabled" wire:target="saveAndStart" @disabled(! $canStart || ! $lawfulBasisConfirmed || ! $this->pacePreview['within_policy'])>Solicitar envio</button></div></footer>
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
                            <details class="ec76-breakdown" @if ((int) ($stats['uncertain'] ?? 0) > 0) open @endif>
                                <summary>Ver todos os estados do envio <span>Detalhes técnicos da fila</span></summary>
                            <div class="ecm2-state-grid">
                                @foreach (['pending' => 'Pendentes', 'reserved' => 'Reservados', 'sending' => 'Processando', 'sent' => 'Aceitos SMTP', 'suppressed' => 'Bloqueados', 'uncertain' => 'Não confirmados', 'canceled' => 'Cancelados'] as $key => $caption)
                                    <div><small>{{ $caption }}</small><strong>{{ number_format((int) ($stats[$key] ?? 0), 0, ',', '.') }}</strong></div>
                                @endforeach
                            </div>
                            </details>
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
            @if (auth()->user()?->isCommercialManager())
                <livewire:campaign-queue-lab />
            @endif
            <section class="ecm2-context-card ecm2-context-help"><strong>Segurança do envio</strong><p>Limites por hora e dia, aprovação explícita e descadastro. Aceitação SMTP não comprova entrega ou leitura.</p></section>
                </div>
            </details>
        </aside>
    </div>
    @include('campaigns.send-confirmation-v73')
    <footer class="ecm2-footer"><span aria-hidden="true">●</span> Dados locais · SMTP individual · IMAP por cabeçalhos · Sem métricas fictícias de abertura</footer>
</div>
