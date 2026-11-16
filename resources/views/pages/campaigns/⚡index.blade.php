<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Support\Facades\DB;
use App\Services\Campaigns\CampaignImapHealth;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\CampaignPilot;
use App\Services\Campaigns\CampaignSendPolicy;
use App\Services\Campaigns\CampaignTrackedTest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    // EC_RECEITA_UNIFIED_V10: chaves Receita verificadas em CampaignAudience.

    public string $screen = 'overview';

    public string $composerStep = 'audience';

    public string $plannedHours = '1'; // EC_CAMPAIGNS_UNIFIED_V90: ritmo mais rápido como padrão, sem exceder SMTP

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

    /** @var array<string,string> A seleção enviada de Contatos só pode gerar estes e-mails. */
    public array $importedExpectedEmailsV91 = [];

    public bool $lawfulBasisConfirmed = false;

    public string $campaignSearch = '';

    #[Url(as: 'situacao')]

    public string $campaignStatus = 'all';

    // EC_CAMPAIGNS_REVIEW_AND_CONFIRM_V73: revisão legível, sem enviar ao abrir.
    public int $reviewRecipientsPage = 1;

    public bool $sendConfirmationOpen = false;

    public int $campaignTablePage = 1;

    public int $recipientPage = 1;

    // EC_CAMPAIGNS_FOLLOWUP_V294: segunda rodada só gera rascunho.
    public int $followUpDays = 5;
    public string $followUpName = 'Retomada comercial';
    public string $followUpSubject = 'Retomando nosso contato';
    public string $followUpBody = '';

    // EC_CAMPAIGNS_BOUNCE_VISIBILITY_V29 — paginação e filtro locais de relatórios IMAP.
    public int $bouncePage = 1;

    public string $bounceFilter = 'all';

    public function mount(): void
    {
        // EC_CAMPAIGNS_V93_NATIVE_MOUNT: filtros GET funcionam mesmo sem JavaScript.
        $v93Status = request()->query('situacao');
        if (is_string($v93Status) && in_array($v93Status, ['all', 'draft', 'running', 'paused', 'completed', 'canceled'], true)) {
            $this->campaignStatus = $v93Status;
        }
        $v94Page = request()->query('paginaCampanha');
        if (is_string($v94Page) && preg_match('/^[1-9][0-9]{0,5}$/D', $v94Page) === 1) {
            $this->campaignTablePage = min(10000, (int) $v94Page);
        }
        $v93Search = request()->query('buscar');
        if (is_string($v93Search)) {
            $this->campaignSearch = mb_substr(trim($v93Search), 0, 120);
        }
        // EC92: transferência por POST usa snapshot exato e nao depende de eventos JS.
        $v92Pending = session()->pull('campaign_transfer_pending_v92', []);
        if ($this->canManageCampaigns && is_array($v92Pending) && $v92Pending !== []) {
            $v92Keys = array_values(array_keys($v92Pending));
            app(\App\Services\Campaigns\CampaignContactTransfer::class)->guard($v92Keys, $v92Pending);
            $this->audienceKeys = $v92Keys;
            $this->screen = 'composer';
            $this->composerStep = 'audience';
            $this->applyTemplate('initial');
            session()->put('campaign_transfer_expected_v92', $v92Pending);

            return;
        }
        // GET apenas prepara o assistente e os modelos; jamais dispara SMTP.
        if ((string) request()->query('nova', '') === '1' && $this->canManageCampaigns) {
            $this->screen = 'composer';
            $this->composerStep = 'audience';
            $v92Model = (string) request()->query('modelo', 'initial');
            $this->applyTemplate(in_array($v92Model, ['initial', 'followup', 'meeting'], true) ? $v92Model : 'initial');
            $v92Origin = (string) request()->query('origem', 'all');
            $this->audienceOrigin = in_array($v92Origin, ['all', 'hubspot', 'manual', 'receita'], true) ? $v92Origin : 'all';
            $this->audienceSearch = mb_substr(trim((string) request()->query('busca', '')), 0, 120);

            return;
        }

        // EC_CAMPAIGN_DIRECT_LINK_V905: a ficha abre também por URL HTTP.
        // Somente leitura: não inicia SMTP, não cria campanha e não altera banco.
        $campaignId = request()->query('campanha');
        if (is_string($campaignId) && preg_match('/^[1-9][0-9]{0,11}$/D', $campaignId) === 1) {
            $requested = EmailCampaign::query()->find((int) $campaignId);
            if ($requested !== null) {
                $this->selectedCampaignId = (int) $requested->getKey();
                $this->screen = 'detail';
                // EC_CAMPAIGN_DETAIL_TABS_V909: abas GET funcionam sem JavaScript/Livewire.
                $tab = request()->query('aba');
                $this->detailTab = is_string($tab) && in_array($tab, ['overview', 'recipients', 'bounces', 'message', 'replies'], true)
                    ? $tab : 'overview';
                $filter = request()->query('filtro');
                if (is_string($filter) && in_array($filter, ['all', 'invalid', 'temporary', 'permanent_other'], true)) {
                    $this->bounceFilter = $filter;
                }
                $recipientPage = request()->query('pagina');
                if (is_string($recipientPage) && preg_match('/^[1-9][0-9]{0,4}$/D', $recipientPage) === 1) {
                    $this->recipientPage = min(10000, (int) $recipientPage);
                }
                $bouncePage = request()->query('paginaDevolucoes');
                if (is_string($bouncePage) && preg_match('/^[1-9][0-9]{0,4}$/D', $bouncePage) === 1) {
                    $this->bouncePage = min(10000, (int) $bouncePage);
                }
                return;
            }
        }
        // EC_CAMPAIGN_NEW_HTTP_V91: nova campanha abre até com Livewire JavaScript indisponível.
        if (request()->query('nova') === '1') {
            session()->forget(['campaign_mail_selected', 'campaign_mail_expected_emails']);
            $this->screen = 'composer';
            $this->composerStep = 'audience';
            $this->applyTemplate('initial');
            return;
        }
        $keys = session()->pull('campaign_mail_selected', []);
        $expectedEmails = session()->pull('campaign_mail_expected_emails', []);
        if ($this->canManageCampaigns && is_array($keys) && $keys !== []) {
            $this->audienceKeys = array_values(array_unique(array_filter($keys,
                static fn (mixed $key): bool => is_string($key)
                    && preg_match('/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/D', $key) === 1
            )));
            // Validação fail-closed: sem e-mail original, nenhum destinatário será importado.
            try {
                $snapshot = app(\App\Services\Campaigns\CampaignContactsSelection::class)
                    ->validateExact($this->audienceKeys, is_array($expectedEmails) ? $expectedEmails : []);
                $this->audienceKeys = $snapshot['keys'];
                $this->importedExpectedEmailsV91 = $snapshot['emails'];
                $this->screen = 'composer';
                $this->composerStep = 'audience';
                $this->applyTemplate('initial');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->audienceKeys = [];
                $this->importedExpectedEmailsV91 = [];
                $this->screen = 'overview';
                $this->addError('recipients', 'Os e-mails mudaram desde a seleção. Nenhum contato foi importado. Volte a Contatos e selecione novamente.');
            }
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

    /** Operações de envio e edição permanecem exclusivas de gestores. */
    #[Computed]
    public function canManageCampaigns(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isCommercialManager();
    }

    private function assertCampaignManager(): User
    {
        $user = $this->assertManager();
        abort_unless($user->isCommercialManager(), 403);

        return $user;
    }

    #[Computed]
    public function campaigns(): Collection
    {
        $this->assertManager();

        // Um grupo por campanha ORIGINAL. Rodadas sao carregadas em uma consulta
        // eager loaded (sem N+1 e sem perder os registros reais de SMTP/IMAP).
        $query = EmailCampaign::query()
            ->whereNull('followup_root_campaign_id')
            ->with(['followupRounds' => static function ($rounds): void {
                $rounds->withCount('recipients')
                    ->withCount(['recipients as accepted_smtp_count' => static fn ($q) => $q->where('status', 'sent')])
                    ->withCount(['bounces as bounce_reports_count' => static fn ($q) => $q->whereNotNull('email_campaign_recipient_id')]);
            }])
            ->withCount('recipients')
            ->withCount(['bounces as bounce_reports_count' => static fn ($q) => $q->whereNotNull('email_campaign_recipient_id')])
            ->withCount(['recipients as accepted_smtp_count' => static fn ($q) => $q->where('status', 'sent')])
            ->latest('id');

        $term = trim(mb_substr($this->campaignSearch, 0, 120));
        if ($term !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);
            $like = '%'.$escaped.'%';
            $query->where(static function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhereHas('followupRounds', static fn ($r) => $r->where('name', 'like', $like));
            });
        }

        if (in_array($this->campaignStatus, ['draft', 'running', 'paused', 'completed', 'canceled'], true)) {
            $status = $this->campaignStatus;
            $query->where(static function ($q) use ($status): void {
                $q->where('status', $status)
                    ->orWhereHas('followupRounds', static fn ($r) => $r->where('status', $status));
            });
        }

        return $query->limit(40)->get();
    }

    #[Computed]
    public function overview(): array
    {
        $this->assertManager();

        return [
            'campaigns' => EmailCampaign::query()->whereNull('followup_root_campaign_id')->count(),
            'active' => EmailCampaign::query()->where('status', 'running')->count(),
            'sent' => EmailCampaignRecipient::query()->where('status', 'sent')->count(),
            'replies' => EmailCampaignRecipient::query()->whereNotNull('replied_at')->count(),
            'bounces' => EmailCampaignBounce::query()->whereNotNull('email_campaign_recipient_id')->count(),
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
            ->with(['bounces' => static fn ($query) => $query->orderByDesc('id')])
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

    /** @return array{total:int,invalid:int,temporary:int,permanent_other:int} */
    #[Computed]
    public function bounceSummary(): array
    {
        $this->assertManager();
        $counts = EmailCampaignBounce::query()
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->whereNotNull('email_campaign_recipient_id')
            ->select('classification')->selectRaw('COUNT(*) AS total')
            ->groupBy('classification')->pluck('total', 'classification');
        $result = ['invalid' => 0, 'temporary' => 0, 'permanent_other' => 0];
        foreach ($counts as $classification => $total) {
            if (array_key_exists((string) $classification, $result)) {
                $result[(string) $classification] = (int) $total;
            }
        }

        return ['total' => array_sum($result), ...$result];
    }

    /** @return LengthAwarePaginator<EmailCampaignBounce> */
    #[Computed]
    public function campaignBounces(): LengthAwarePaginator
    {
        $this->assertManager();
        $query = EmailCampaignBounce::query()
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->whereNotNull('email_campaign_recipient_id');
        if (in_array($this->bounceFilter, ['invalid', 'temporary', 'permanent_other'], true)) {
            $query->where('classification', $this->bounceFilter);
        }

        return $query->with('recipient')->orderByDesc('id')
            ->paginate(15, ['*'], 'bouncePage', $this->bouncePage);
    }

    public function updatedBounceFilter(): void
    {
        $this->assertManager();
        $this->bouncePage = 1;
        unset($this->campaignBounces);
    }

    public function changeBouncePage(int $page): void
    {
        $this->assertManager();
        $this->bouncePage = max(1, $page);
        unset($this->campaignBounces);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    #[Computed]
    public function followUpPlans(): \Illuminate\Support\Collection
    {
        $this->assertManager();
        return DB::table('email_campaign_sequences')
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)
            ->latest('id')->limit(10)->get();
    }

    public function planFollowUp(CampaignSequencePlanner $planner): void
    {
        $user = $this->assertCampaignManager();
        $source = EmailCampaign::query()->findOrFail($this->selectedCampaignId ?? 0);
        abort_unless($source->status === 'completed', 422);
        $values = $this->validate([
            'followUpDays' => 'required|integer|in:5,7,8',
            'followUpName' => 'required|string|min:3|max:150',
            'followUpSubject' => 'required|string|min:3|max:180',
            'followUpBody' => 'required|string|min:10|max:12000',
        ]);
        // O agendador pode preparar o RASCUNHO no prazo. Nenhum envio automático.
        $planId = DB::transaction(function () use ($planner, $user, $source, $values): int {
            $id = $planner->create($user, $source,
                $values['followUpName'], (int) $values['followUpDays'],
                $values['followUpSubject'], $values['followUpBody']);
            DB::table('email_campaign_sequences')->where('id', $id)->update(['auto_prepare' => true]);
            return $id;
        });
        $draftId = $planner->prepare($user, $planId);
        unset($this->followUpPlans, $this->campaigns, $this->overview);
        session()->flash('campaign_success', $draftId !== null
            ? 'Segunda rodada preparada como rascunho. Revise destinatários e mensagem antes de autorizar.'
            : 'Segunda rodada planejada. Após o prazo, o agendador criará um rascunho para aprovação.');
    }

    public function prepareFollowUp(int $planId, CampaignSequencePlanner $planner): void
    {
        $user = $this->assertCampaignManager();
        $this->assertManager();
        $plan = DB::table('email_campaign_sequences')
            ->where('id', $planId)
            ->where('email_campaign_id', $this->selectedCampaignId ?? 0)->first();
        abort_unless($plan !== null, 404);
        $draftId = $planner->prepare($user, $planId);
        unset($this->followUpPlans, $this->campaigns, $this->overview);
        if ($draftId !== null) {
            session()->flash('campaign_success', 'Rascunho da segunda rodada criado. Revise e autorize manualmente o envio.');
        } else {
            session()->flash('campaign_success', 'Ainda não há destinatários elegíveis ou o prazo não terminou. Nenhum e-mail foi enviado.');
        }
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
        $user = $this->assertCampaignManager();
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
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
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
        session()->forget('campaign_transfer_expected_v92');
        $this->assertCampaignManager();
        $this->sendConfirmationOpen = false;
        $this->reviewRecipientsPage = 1;
        $this->importedExpectedEmailsV91 = [];
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
        $this->bouncePage = 1;
        $this->bounceFilter = 'all';
        unset($this->selectedCampaign, $this->recipientStats, $this->recipients, $this->replies, $this->bounceSummary, $this->campaignBounces, $this->followUpPlans);
    }

    public function editDraft(int $id): void
    {
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
        $this->newCampaign();
        $this->applyTemplate($key);
        // Começa pela escolha de público para não esquecer destinatários.
    }

    public function applyTemplate(string $key): void
    {
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
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
        $user = $this->assertCampaignManager();
        if ($this->importedExpectedEmailsV91 !== []) {
            app(\App\Services\Campaigns\CampaignContactsSelection::class)
                ->validateExact($this->audienceKeys, $this->importedExpectedEmailsV91);
        }
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:12000'],
            'audienceKeys' => ['required', 'array', 'min:1'],
            'audienceKeys.*' => ['string', 'max:160', 'regex:/^(?:(?:hubspot|manual):[0-9]+|receita:[0-9a-fA-F-]{36})$/'],
        ]);
        // EC92: antes de gravar, bloquear qualquer troca silenciosa do e-mail original.
        app(\App\Services\Campaigns\CampaignContactTransfer::class)->guard(
            array_values($validated['audienceKeys']),
            (array) session()->get('campaign_transfer_expected_v92', []),
        );
        $campaign = $manager->saveDraft($user, [
            'name' => $validated['name'], 'subject' => $validated['subject'], 'body' => $validated['body'],
        ], array_values($validated['audienceKeys']), $this->editingCampaignId !== null
            ? EmailCampaign::query()->findOrFail($this->editingCampaignId) : null);
        app(\App\Services\Campaigns\CampaignPacing::class)->save($campaign, (int) $this->plannedHours);
        session()->forget('campaign_transfer_expected_v92');
session()->flash('campaign_success', 'Rascunho salvo. Nenhum e-mail foi enviado.');
        unset($this->overview, $this->campaigns);
        $this->openCampaign((int) $campaign->id);
    }

    /**
     * EC_SIMPLE_CAMPAIGNS_2026_V1
     * Disparo explícito: primeiro salva rascunho, depois usa a mesma
     * aprovação e todas as proteções já existentes no CampaignManager.
     * A duração escolhida grava a cadência da campanha; os limites globais continuam prioritários.
     */
    public function saveAndStart(CampaignManager $manager): void
    {
        $this->assertCampaignManager();
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
        $this->assertCampaignManager();
        $this->audienceKeys = array_values(array_filter(
            $this->audienceKeys,
            static fn (string $selected): bool => $selected !== $key,
        ));
    }

    public function appendTemplateVariable(string $token): void
    {
        $this->assertCampaignManager();
        abort_unless(in_array($token, ['nome', 'primeiro_nome', 'empresa', 'cargo'], true), 422);
        $this->body .= ($this->body === '' || str_ends_with($this->body, "\n") ? '' : ' ').'{{'.$token.'}}';
    }

    public function sendTest(CampaignManager $manager): void
    {
        $user = $this->assertCampaignManager();
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
        $user = $this->assertCampaignManager();
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
        $manager->start($this->assertCampaignManager(), EmailCampaign::query()->findOrFail($id), $this->lawfulBasisConfirmed);
        $this->lawfulBasisConfirmed = false;
        $this->openCampaign($id);
        session()->flash('campaign_success', 'Campanha autorizada. O agendador liberará os envios conforme os limites.');
        unset($this->overview, $this->campaigns);
    }

    public function pauseCampaign(int $id, CampaignManager $manager): void
    {
        $manager->pause($this->assertCampaignManager(), EmailCampaign::query()->findOrFail($id));
        $this->openCampaign($id);
        unset($this->overview, $this->campaigns);
    }

    public function resumeCampaign(int $id, CampaignManager $manager): void
    {
        $manager->resume($this->assertCampaignManager(), EmailCampaign::query()->findOrFail($id));
        $this->openCampaign($id);
        unset($this->overview, $this->campaigns);
    }

    public function cancelCampaign(int $id, CampaignManager $manager): void
    {
        $manager->cancel($this->assertCampaignManager(), EmailCampaign::query()->findOrFail($id));
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
    $canManageCampaigns = $this->canManageCampaigns;
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
    $canStart = $canManageCampaigns && $liveEnabled && $publicHttps;
    $hasAudience = count($audienceKeys) > 0;
    $hasContent = mb_strlen(trim($name)) >= 3
        && mb_strlen(trim($subject)) >= 3 && mb_strlen(trim($body)) >= 10;
    $selected = $screen === 'detail' ? $this->selectedCampaign : null;
    $lastTrackedTest = $canManageCampaigns && $selected ? EmailCampaignTestMessage::query()->where('email_campaign_id', $selected->id)->latest('id')->first() : null;
    $stats = $selected ? $this->recipientStats : [];
    $sentCount = (int) ($stats['sent'] ?? 0);
    $totalCount = (int) ($selected?->recipients_count ?? 0);
    $progress = $totalCount > 0 ? min(100, (int) round($sentCount * 100 / $totalCount)) : 0;
@endphp

{{-- EC_CAMPAIGNS_V93_NATIVE_LINKS: filtros reais por HTTP, sem eventos JS. --}}
<style>
.ecm2 .ecm2-filters>a{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px;color:#56758b;border:1px solid transparent;border-radius:8px;padding:9px 12px}
.ecm2 .ecm2-filters>a.is-active{background:#e6f7f1;border-color:#bce7db;color:#008779}
.ecm2 .ecm2-campaign-list>a{display:block;text-decoration:none;color:inherit}
</style>
{{-- EC_CAMPAIGN_STATUS_CONTRAST_V83: corrigir badge azul-escuro ilegível da coluna Situação. --}}
<style>
.ecm2 .ecm2-table td .ecm2-pill[class*="is-delivery-"] { display:inline-flex;align-items:center;min-height:24px;padding:4px 9px;border-radius:7px;font-size:11px;font-weight:730;line-height:1.3;background:#eef2f6 !important;border:1px solid #d5dfe8 !important;color:#2d4d61 !important;-webkit-text-fill-color:#2d4d61 !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-pending { background:#eef2f6 !important;border-color:#d5dfe8 !important;color:#344c61 !important;-webkit-text-fill-color:#344c61 !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-reserved { background:#e7f1fc !important;border-color:#bfd7f1 !important;color:#205b88 !important;-webkit-text-fill-color:#205b88 !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-sending { background:#e4f4fa !important;border-color:#bee1ee !important;color:#165f7b !important;-webkit-text-fill-color:#165f7b !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-sent { background:#e6f7ee !important;border-color:#bde3cf !important;color:#116b4a !important;-webkit-text-fill-color:#116b4a !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-suppressed { background:#fff0ed !important;border-color:#edcac4 !important;color:#973d35 !important;-webkit-text-fill-color:#973d35 !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-uncertain { background:#fff4dd !important;border-color:#efd7ac !important;color:#85601d !important;-webkit-text-fill-color:#85601d !important; }
.ecm2 .ecm2-table td .ecm2-pill.is-delivery-canceled { background:#f1f3f5 !important;border-color:#dce2e6 !important;color:#52616a !important;-webkit-text-fill-color:#52616a !important; }
</style>
@include('campaigns.workspace-context')
<div class="ecm2 ec21-page ec22-page ec68-page ec69-page" data-ec-campaigns-simple="M01-M10-V69" data-ec-campaigns-figma="M01-M10-V68" data-ec21="EC_CAMPAIGN_REDESIGN_V21" data-ec22-screen="{{ $screen }}" @if ($screen === 'detail') wire:poll.60s @endif>
    {{-- EC92_FORMS_START: navegacao GET de seguranca, sem criar rascunhos --}}
    <form id="ec-v92-campaign-filter-form" method="GET" action="{{ route('campaigns.index') }}"></form>
    <form id="ec-v92-campaign-template-form" method="GET" action="{{ route('campaigns.index') }}"></form>
    <form id="ec-v92-audience-filter-form" method="GET" action="{{ route('campaigns.index') }}"></form>
    <form id="ec-v92-audience-search-form" method="GET" action="{{ route('campaigns.index') }}"></form>
    {{-- EC92_FORMS_END --}}

    <header class="ecm2-head">
        <div>
            <div class="ecm2-breadcrumb">CAMPANHAS</div>
            <h1>Campanhas de E-mail</h1>
            <p>Prepare mensagens, acompanhe a fila e continue as conversas.</p>
        </div>
        <div class="ecm2-head-actions">
            <span class="ecm2-safe {{ $canStart ? 'is-live' : '' }}" aria-label="Status dos disparos comerciais">
                <span class="ecm2-dot"></span>
                {{ ! $canManageCampaigns ? 'Acesso somente leitura' : ($canStart ? 'Envios reais habilitados' : 'Modo seguro · disparos bloqueados') }}
            </span>
            {{-- EC_CAMPAIGNS_UX_V62_ROOT --}}
            @if ($screen === 'detail')
                <a href="#ecm7-context" class="ecm7-jump" aria-label="Ir para detalhes e ações da campanha">Ações e detalhes ↓</a>
            @endif
            @if ($canManageCampaigns)
                {{-- EC94_MAIN_HEADER_ENTRY: único assistente de nova campanha. --}}
                <a class="ecm2-btn ecm2-btn-primary" href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1]) }}">＋ Nova campanha</a>
            @endif
            <a href="{{ route('campaigns.replies') }}" wire:navigate class="ec68-replies-shortcut">Ver respostas</a>
            @if ($canManageCampaigns)
                <a href="{{ route('campaigns.queue') }}#ec294-unmatched" class="ec294-jump">DSNs sem vínculo</a>
            @endif
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
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-amber">!</span><div><small>Devoluções</small><strong>{{ number_format($counts['bounces'], 0, ',', '.') }}</strong><span>Relatórios comerciais correlacionados</span></div></div>
        <div class="ecm2-stat"><span class="ecm2-stat-icon is-red">!</span><div><small>Não confirmados</small><strong>{{ number_format($counts['uncertain'], 0, ',', '.') }}</strong><span>Conferir tentativas</span></div></div>
    </section>
    </details>

    @if ($screen === 'overview')
        @include('campaigns.home')
    @elseif ($screen === 'detail' && $selected)
        @include('campaigns.detail-header')
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
                        <a wire:click="$set('campaignStatus', @js($code))" class="{{ $campaignStatus === $code ? 'is-active' : '' }}" aria-pressed="{{ $campaignStatus === $code ? 'true' : 'false' }}" href="{{ route('campaigns.index', ['situacao' => $code]) }}">{{ $label }}</a>
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
                    <a role="listitem" wire:key="ecm2-campaign-{{ $item->id }}" wire:click="openCampaign({{ $item->id }})"
                        class="ecm2-campaign {{ $selectedCampaignId === $item->id && $screen === 'detail' ? 'is-current' : '' }}"
                        aria-current="{{ $selectedCampaignId === $item->id && $screen === 'detail' ? 'true' : 'false' }}" href="{{ route('campaigns.index', ['campanha' => $item->id]) }}">
                        <span class="ecm2-campaign-heading"><strong title="{{ $item->name }}">{{ $item->name }}</strong><span class="ecm2-pill is-{{ $item->status }}">{{ $labels[$item->status] ?? $item->status }}</span></span>
                        <span class="ecm2-campaign-sub">@if ((int) ($item->followup_round ?? 1) > 1) Rodada {{ (int) $item->followup_round }} · @else Rodada 1 · @endif{{ $itemTotal }} destinatário(s) · {{ $item->created_at?->format('d/m/Y') }}</span>
                        @if ((int) $item->bounce_reports_count > 0)
                            <span class="ec29-sidebar-count" data-ec-bounce-count>⚠ {{ $item->bounce_reports_count }} devolução(ões) registrada(s)</span>
                        @endif
                        @if ($itemTotal > 0)
                            <span class="ecm2-mini-progress"><i style="width: {{ $itemProgress }}%"></i></span>
                            <span class="ecm2-campaign-footer">{{ $itemSent }} aceito(s) SMTP <b>{{ $itemProgress }}%</b></span>
                        @endif
                    </a>
                @empty
                    <div class="ecm2-no-campaigns">
                        <span aria-hidden="true">✉</span>
                        <strong>{{ $counts['campaigns'] === 0 ? 'Sua primeira campanha começa aqui' : 'Nenhuma campanha neste filtro' }}</strong>
                        <p>{{ $counts['campaigns'] === 0 ? 'Crie um rascunho para organizar destinatários e mensagem.' : 'Altere os filtros ou pesquise outro nome.' }}</p>
                        @if ($counts['campaigns'] === 0 && $canManageCampaigns)
                            <a class="ecm2-text-action" href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1]) }}">Criar campanha →</a>
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
                                <h2>{{ $canManageCampaigns ? 'Crie sua próxima campanha de e-mail' : 'Acompanhe suas campanhas de e-mail' }}</h2>
                                <p>{{ $canManageCampaigns ? 'Crie sua campanha em poucos passos. Escolha os destinatários, escreva o texto e revise antes de enviar.' : 'Consulte campanhas, destinatários e resultados. A criação e o envio são exclusivos do gestor.' }}</p>
                            </div>
                            <div class="ec22-hero-symbol" aria-hidden="true">✉ <span>➤</span></div>
                        </div>
                        @if ($canManageCampaigns)
                        <ol class="ec22-home-steps" aria-label="Etapas da campanha">
                            <li><span>01</span><div><strong>Selecionar contatos</strong><small>Encontre destinatários na base de contatos.</small></div></li>
                            <li><span>02</span><div><strong>Escrever mensagem</strong><small>Personalize o assunto e o texto do e-mail.</small></div></li>
                            <li><span>03</span><div><strong>Ritmo e revisão</strong><small>Confira os limites, faça um teste e salve.</small></div></li>
                        </ol>
                        @endif
                        <div class="ec22-home-cta">
                            @if ($canManageCampaigns)
                                <a href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1]) }}" class="ec22-button ec22-button-primary">＋ Começar campanha <span aria-hidden="true">→</span></a>
                            @endif
                            <a href="{{ route('contacts.index') }}" wire:navigate>Consultar contatos →</a>
                        </div>
                    </div>
                    @if ($canManageCampaigns)
                    <div class="ec22-home-templates">
                        <div class="ec22-section-head"><div><h3>Modelos prontos para começar</h3><p>Você pode editar a mensagem antes de salvar a campanha.</p></div></div>
                        <div class="ec22-home-template-grid">
                            <a href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'modelo' => 'initial']) }}"><span aria-hidden="true">✉</span><strong>Apresentação</strong><small>Primeiro contato com uma empresa.</small><em>Usar modelo →</em></a>
                            <a href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'modelo' => 'followup']) }}"><span aria-hidden="true">↩</span><strong>Retomada</strong><small>Retome uma conversa com contexto.</small><em>Usar modelo →</em></a>
                            <a href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'modelo' => 'meeting']) }}"><span aria-hidden="true">▣</span><strong>Agendamento</strong><small>Convide para uma apresentação.</small><em>Usar modelo →</em></a>
                        </div>
                    </div>
                    @endif
                    <div class="ec22-home-footnote">Os resultados de envio refletem registros reais do SMTP e IMAP. Não há contagem de aberturas sem rastreamento validado.</div>
                </section>
            @elseif ($screen === 'composer' && $canManageCampaigns)
                {{-- EC22: um único assistente de três telas, sem painel lateral de campanhas. --}}
                <section class="ec22-wizard" data-ec22-section="composer" data-ec22-step="{{ $composerStep }}">
                    <header class="ec22-wizard-head">
                        <div>
                            <span class="ec22-kicker">CAMPANHAS DE E-MAIL / {{ $editingCampaignId ? 'EDITAR RASCUNHO' : 'NOVA CAMPANHA' }}</span>
                            <h1>{{ match ($composerStep) { 'audience' => 'Selecione os contatos', 'message' => 'Escreva a mensagem', default => 'Ritmo e revisão' } }}</h1><small class="ec68-composer-subtitle">Nova campanha</small>
                            <p>{{ match ($composerStep) { 'audience' => 'Escolha quem vai receber seu e-mail.', 'message' => 'Escreva sua mensagem e confira a prévia.', default => 'Confira o ritmo e revise antes de solicitar o envio.' } }}</p>
                        </div>
                        <a class="ec22-button ec22-button-outline" wire:click="showOverview" href="{{ route('campaigns.index') }}">Cancelar e voltar</a>
                    </header>
                    @php $ec22Step = ['audience' => 1, 'message' => 2, 'pace' => 3, 'review' => 3][$composerStep] ?? 1; @endphp
                    <nav class="ec22-stepper" aria-label="Etapas da criação">
                        @foreach ([['audience', 1, 'Selecionar contatos'], ['message', 2, 'Escrever mensagem'], ['pace', 3, 'Ritmo e revisão']] as [$stepKey, $index, $stepName])
                            <button type="button" class="{{ $ec22Step === $index ? 'is-current' : ($ec22Step > $index ? 'is-done' : '') }}" wire:click="goToComposerStep(@js($stepKey))" aria-current="{{ $ec22Step === $index ? 'step' : 'false' }}"><b>{{ $ec22Step > $index ? '✓' : $index }}</b><span><strong>{{ $stepName }}</strong><small>{{ match ($index) { 1 => 'Quem vai receber', 2 => 'O que será enviado', default => 'Quando e como enviar' } }}</small></span></button>
                        @endforeach
                    </nav>
                    @if ($composerStep === 'audience')
                        @if ($importedExpectedEmailsV91 !== [])
                            <section class="ec22-card" data-ec-v91-exact-selection style="border-color:#86cfbc;background:#f0faf6;margin-bottom:16px">
                                <strong style="display:block;color:#096c60;font-size:15px">{{ count($importedExpectedEmailsV91) }} e-mail(s) selecionado(s) em Contatos</strong>
                                <p style="font-size:12px;color:#315f58">Somente estes endereços foram importados. A lista abaixo é o catálogo geral; não são novos destinatários até serem marcados.</p>
                                <div style="display:flex;flex-wrap:wrap;gap:7px;max-height:200px;overflow:auto">
                                    @foreach ($importedExpectedEmailsV91 as $email)
                                        <span style="background:white;border:1px solid #cce8de;color:#135d51;border-radius:7px;padding:6px 9px;font-size:12px">{{ $email }}</span>
                                    @endforeach
                                </div>
                                <small style="display:block;margin-top:9px;color:#436b61">Se precisar trocar os destinatários, inicie uma nova campanha. Uma seleção importada nunca será substituída silenciosamente.</small>
                            </section>
                        @endif
                        <div class="ec22-two-columns ec22-audience-layout">
                            <section class="ec22-card ec22-audience-card">
                                <header class="ec22-card-head"><div><h2>Escolha os contatos</h2><p>Selecione na base. Você poderá revisar a lista antes de salvar.</p></div><span class="ec22-count">{{ number_format($this->audience->total(), 0, ',', '.') }} encontrados</span></header>
                                <label class="ec22-search"><span aria-hidden="true">⌕</span><input aria-label="Buscar contatos" type="search" wire:model.live.debounce.400ms="audienceSearch" placeholder="Buscar por nome, empresa ou e-mail..." name="busca" form="ec-v92-audience-search-form"></label>
                                <input type="hidden" form="ec-v92-audience-search-form" name="nova" value="1">
                                <input type="hidden" form="ec-v92-audience-search-form" name="origem" value="{{ $audienceOrigin }}">
                                <button type="submit" form="ec-v92-audience-search-form" class="ec22-audience-fallback-submit">Buscar</button>
                                <div class="ec22-tabs" role="group" aria-label="Origem dos contatos">
                                    @foreach (['all' => 'Todos', 'hubspot' => 'HubSpot', 'manual' => 'Cadastro manual', 'receita' => 'Receita Federal'] as $key => $caption)
                                        <button type="submit" class="{{ $audienceOrigin === $key ? 'is-active' : '' }}" wire:click.prevent="$set('audienceOrigin', @js($key))" aria-pressed="{{ $audienceOrigin === $key ? 'true' : 'false' }}" form="ec-v92-audience-filter-form" formaction="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'origem' => $key, 'busca' => $audienceSearch]) }}" formmethod="GET">{{ $caption }}</button>
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
                        <footer class="ec22-footer"><a class="ec22-button ec22-button-outline" wire:click="showOverview" href="{{ route('campaigns.index') }}">Cancelar</a><button type="button" class="ec22-button ec22-button-primary" wire:click="goToComposerStep('message')">Continuar →</button></footer>
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
                                <header class="ec22-card-head"><div><h2>Em quanto tempo você quer enviar?</h2><p>Defina o ritmo desta campanha. O motor usará o menor limite entre sua escolha e a capacidade SMTP autorizada.</p></div></header>
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
                            <aside class="ec22-card ec22-pace-summary"><header class="ec22-card-head"><div><h2>Previsão do seu envio</h2><p>Cadência salva no rascunho, respeitando o SMTP. Estimativa, não garantia de conclusão no horário.</p></div></header>
                                <div class="ec22-metric-grid"><div><span>Necessário por hora</span><strong>{{ $this->pacePreview['required_hour'] }}</strong></div><div><span>Necessário por minuto</span><strong>{{ $this->pacePreview['required_minute'] }}</strong></div><div><span>Duração desejada</span><strong>{{ $this->pacePreview['hours'] }}h</strong></div><div><span>Contatos</span><strong>{{ count($audienceKeys) }}</strong></div></div>
                                <div class="ec22-info-note">O início e a duração efetivos dependem do agendador e dos limites SMTP. A cadência é aplicada ao reservar destinatários, sem alterar a política global.</div>
                            </aside>
                        </div>
                        <section class="ec22-card ec22-review-card">
                            <header class="ec22-card-head"><div><h2>Revisão final</h2><p>Confira antes de salvar ou solicitar o envio.</p></div><button type="button" class="ec22-button ec22-button-outline" wire:click="goToComposerStep('message')">Editar mensagem</button></header>
                            <div class="ec22-review-grid"><div><span>Campanha</span><strong>{{ $name ?: '(sem nome)' }}</strong></div><div><span>Destinatários selecionados</span><strong>{{ count($audienceKeys) }}</strong></div><div><span>Remetente</span><strong>{{ config('campaign_mail.from_address') ?: 'Não configurado' }}</strong></div><div><span>Assunto</span><strong>{{ $subject ?: '(sem assunto)' }}</strong></div></div>
                            @include('campaigns.review-recipients')
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
                    <header class="ecm2-detail-header"><div><div class="ecm2-eyebrow">CAMPANHA #{{ $selected->id }} <span class="ecm2-pill is-{{ $selected->status }}">{{ $labels[$selected->status] ?? $selected->status }}</span></div><h2>{{ $selected->name }}</h2><p>{{ $selected->subject }}</p></div>@if ($selected->status === 'draft' && $canManageCampaigns)<a class="ecm2-btn ecm2-btn-muted" href="{{ route('campaigns.wizard-v93', ['reiniciar' => 1, 'editar' => $selected->id]) }}">✎ Editar rascunho</a><a class="ecm2-btn ecm2-btn-muted" href="#proximos-emails">Programar próximos e-mails</a>@endif</header>
                    <div class="ecm2-progress-section"><div><strong>Progresso de aceitação SMTP</strong><span>{{ $sentCount }} de {{ $totalCount }} destinatário(s) · {{ $progress }}%</span></div><div class="ecm2-progress"><span style="width:{{ $progress }}%"></span></div><small>Mensagens aceitas pelo servidor não significam entrega na caixa de entrada.</small></div>
                    <div class="ecm2-detail-stats">
                        <div><small>Destinatários</small><strong>{{ number_format($totalCount, 0, ',', '.') }}</strong></div>
                        <div><small>Aceitos SMTP</small><strong>{{ number_format($sentCount, 0, ',', '.') }}</strong></div>
                        <div><small>Respostas</small><strong>{{ number_format((int) $selected->replies()->count(), 0, ',', '.') }}</strong></div>
                        <div><small>Na fila</small><strong>{{ number_format((int) ($stats['pending'] ?? 0) + (int) ($stats['reserved'] ?? 0), 0, ',', '.') }}</strong></div>
                    </div>
                    {{-- EC_CAMPAIGN_TABS_VISUAL_V9012: mantém links HTTP da V90.9, restaura abas V76 sem depender do build Vite. --}}
                    <style>
                      #ec9012-tabs.ecm2-tabs {
                        display: flex !important;
                        align-items: center !important;
                        flex-wrap: wrap !important;
                        gap: 6px !important;
                        border: 0 !important;
                        border-bottom: 1px solid #e4edf0 !important;
                        padding: 14px 0 !important;
                        margin: 0 0 16px !important;
                        background: #fff !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a {
                        display: inline-flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        gap: 7px !important;
                        min-height: 40px !important;
                        padding: 10px 16px !important;
                        border: 0 !important;
                        border-radius: 8px !important;
                        background: transparent !important;
                        box-shadow: none !important;
                        color: #58748a !important;
                        -webkit-text-fill-color: #58748a !important;
                        font-size: 13px !important;
                        line-height: 1.2 !important;
                        font-weight: 660 !important;
                        text-decoration: none !important;
                        white-space: nowrap !important;
                        cursor: pointer !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a:hover {
                        background: #f1f8f5 !important;
                        color: #08776b !important;
                        -webkit-text-fill-color: #08776b !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a.is-active,
                      #ec9012-tabs.ecm2-tabs > a[aria-current="page"] {
                        background: #e5f5ee !important;
                        color: #007f71 !important;
                        -webkit-text-fill-color: #007f71 !important;
                        font-weight: 760 !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a:focus-visible {
                        outline: 2px solid #0c9a88 !important;
                        outline-offset: 2px !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a > span {
                        display: inline-flex !important;
                        align-items: center !important;
                        justify-content: center !important;
                        min-width: 19px !important;
                        height: 20px !important;
                        padding: 0 6px !important;
                        border: 1px solid #d9e7ed !important;
                        border-radius: 6px !important;
                        background: #f1f6f9 !important;
                        color: #547389 !important;
                        -webkit-text-fill-color: #547389 !important;
                        font-size: 10px !important;
                        font-weight: 700 !important;
                        line-height: 1 !important;
                      }
                      #ec9012-tabs.ecm2-tabs > a.is-active > span,
                      #ec9012-tabs.ecm2-tabs > a[aria-current="page"] > span {
                        background: #d4eee2 !important;
                        border-color: #c1e6d6 !important;
                        color: #007d70 !important;
                        -webkit-text-fill-color: #007d70 !important;
                      }
                      @media (max-width: 600px) {
                        #ec9012-tabs.ecm2-tabs { gap: 4px !important; padding: 12px 0 !important; }
                        #ec9012-tabs.ecm2-tabs > a { padding: 10px 11px !important; font-size: 12px !important; }
                      }
                    </style>
                    <nav class="ecm2-tabs" id="ec9012-tabs" aria-label="Conteúdo da campanha">
                        @foreach (['overview' => 'Visão geral', 'recipients' => 'Destinatários', 'bounces' => 'Devoluções', 'message' => 'Mensagem', 'replies' => 'Respostas'] as $tabKey => $tabLabel)
                            <a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => $tabKey]) }}" class="{{ $detailTab === $tabKey ? 'is-active' : '' }}" @if ($detailTab === $tabKey) aria-current="page" @endif>{{ $tabLabel }} @if ($tabKey === 'replies' && $selected->replies()->count() > 0)<span>{{ $selected->replies()->count() }}</span>@elseif ($tabKey === 'bounces' && $this->bounceSummary['total'] > 0)<span>{{ $this->bounceSummary['total'] }}</span>@endif</a>
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
                                <div class="ecm2-response-head"><strong>Respostas recentes</strong><a class="ecm2-text-action" href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'replies']) }}">Ver todas →</a></div>
                                @foreach ($this->replies->take(3) as $reply)
                                    <div class="ecm2-recent-reply"><span aria-hidden="true">↩</span><div><strong>{{ $reply->from_email }}</strong><small>{{ $reply->subject ?: 'Sem assunto' }}</small></div><time>{{ \App\Support\CampaignLocalTime::format($reply->getRawOriginal('received_at'), '') }}</time></div>
                                @endforeach
                            @endif
                        </div>
                        {{-- EC_CAMPAIGNS_FOLLOWUP_V294: editor duplicado ocultado pela EC_CAMPAIGNS_UNIFIED_V90; serviços históricos preservados. --}}
                    @elseif ($detailTab === 'recipients')
                        <div class="ecm2-table-wrap"><table class="ecm2-table"><thead><tr><th>Contato / E-mail</th><th>Empresa</th><th>Situação</th><th>Data / hora (Brasília)</th></tr></thead><tbody>
                            @forelse ($this->recipients as $recipient)
                                <tr wire:key="ecm2-recipient-{{ $recipient->id }}"><td><strong>{{ $recipient->contact_name ?: 'Contato sem nome' }}</strong><small>{{ $recipient->email }}</small></td><td>{{ $recipient->company_name ?: '—' }}</td><td>
                                    <span class="ecm2-pill is-delivery-{{ $recipient->status }}" style="{{ $recipient->status === 'pending' ? 'background:#eaf2f7 !important;color:#173b51 !important;-webkit-text-fill-color:#173b51 !important;border:1px solid #bdd1df !important;font-weight:700 !important;opacity:1 !important;' : '' }}">{{ $delivery[$recipient->status] ?? $recipient->status }}</span>
                                    @if ($recipient->bounces->isNotEmpty())
                                        @php $latestBounce = $recipient->bounces->first(); @endphp
                                        <span class="ec29-recipient-bounced">↩ Devolvido · {{ \App\Services\Campaigns\CampaignBounceReason::label((string) $latestBounce->status_code) }}</span>
                                        <small class="ec29-recipient-detail">Código {{ $latestBounce->status_code }} · {{ \App\Support\CampaignLocalTime::format($latestBounce->getRawOriginal('received_at'), 'Data não informada') }}</small>
                                    @elseif ($recipient->status === 'uncertain' && $recipient->error_message)
                                        <small class="ec29-recipient-detail">Envio não confirmado: {{ $recipient->error_message }}</small>
                                    @endif
                                    @if ($recipient->replied_at) <small>↩ Respondeu</small> @endif
                                </td><td>{{ \App\Support\CampaignLocalTime::format($recipient->getRawOriginal('sent_at')) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="ecm2-empty-cell">Nenhum destinatário registrado.</td></tr>
                            @endforelse
                        </tbody></table></div>
                        <nav class="ecm2-pagination" aria-label="Páginas de destinatários"><span>Página {{ $this->recipients->currentPage() }} / {{ $this->recipients->lastPage() }}</span><div>
                            @if ($this->recipients->onFirstPage())<span aria-disabled="true">Anterior</span>@else<a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'recipients', 'pagina' => $this->recipients->currentPage() - 1]) }}">Anterior</a>@endif
                            @if ($this->recipients->hasMorePages())<a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'recipients', 'pagina' => $this->recipients->currentPage() + 1]) }}">Próxima</a>@else<span aria-disabled="true">Próxima</span>@endif
                        </div></nav>
                    @elseif ($detailTab === 'bounces')
                        {{-- EC_CAMPAIGNS_BOUNCE_VISIBILITY_V29 — relatórios DSN da campanha atual. --}}
                        <div class="ec29-bounce-panel" data-ec-bounce-tab>
                            <div class="ec29-bounce-top">
                                <div><h3>E-mails devolvidos</h3><p>Retornos do servidor associados ao identificador do envio e ao destinatário. A aceitação inicial pelo SMTP não confirma entrega.</p></div>
                                <form class="ec29-bounce-filter" method="GET" action="{{ route('campaigns.index') }}">
                                    <input type="hidden" name="campanha" value="{{ $selected->id }}">
                                    <input type="hidden" name="aba" value="bounces">
                                    <label for="ec29-bounce-filter">Tipo de retorno</label>
                                    <select id="ec29-bounce-filter" name="filtro">
                                        <option value="all" @selected($bounceFilter === 'all')>Todos</option>
                                        <option value="invalid" @selected($bounceFilter === 'invalid')>Endereço inválido</option>
                                        <option value="temporary" @selected($bounceFilter === 'temporary')>Falha temporária</option>
                                        <option value="permanent_other" @selected($bounceFilter === 'permanent_other')>Outras rejeições</option>
                                    </select>
                                    <button type="submit">Filtrar</button>
                                </form>
                            </div>
                            <div class="ec29-bounce-metrics" aria-label="Totais de devoluções da campanha">
                                <div><small>Endereço inválido</small><strong>{{ $this->bounceSummary['invalid'] }}</strong></div>
                                <div><small>Falhas temporárias</small><strong>{{ $this->bounceSummary['temporary'] }}</strong></div>
                                <div><small>Outras rejeições</small><strong>{{ $this->bounceSummary['permanent_other'] }}</strong></div>
                            </div>
                            @if ($this->imapHealth['state'] !== 'healthy')
                                <p class="ec29-bounce-imap-warning" role="status">Monitoramento IMAP: {{ $this->imapHealth['label'] }}. Novas devoluções podem não aparecer até a sincronização funcionar.</p>
                            @endif
                            <div class="ecm2-table-wrap">
                                <table class="ecm2-table ec29-bounce-table">
                                    <thead><tr><th>Destinatário</th><th>Motivo do retorno</th><th>Tipo / código</th><th>Relatório do servidor</th><th>Recebido em</th></tr></thead>
                                    <tbody>
                                        @forelse ($this->campaignBounces as $bounce)
                                            <tr wire:key="ec29-bounce-{{ $bounce->id }}">
                                                <td><strong>{{ $bounce->final_recipient }}</strong><small>{{ $bounce->recipient?->contact_name ?: ($bounce->recipient?->company_name ?: 'Destinatário da campanha') }}</small></td>
                                                <td class="ec29-bounce-reason">{{ \App\Services\Campaigns\CampaignBounceReason::label((string) $bounce->status_code) }}</td>
                                                <td>{{ \App\Services\Campaigns\CampaignBounceReason::categoryLabel((string) $bounce->classification) }}<small>DSN {{ $bounce->status_code }}</small></td>
                                                <td>{{ $bounce->diagnostic ?: 'Servidor não informou detalhes' }}</td>
                                                <td>{{ \App\Support\CampaignLocalTime::format($bounce->getRawOriginal('received_at')) }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="ecm2-empty-cell">Nenhuma devolução registrada neste filtro. Consulte o status do monitoramento IMAP.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            @if ($this->campaignBounces->lastPage() > 1)
                                <nav class="ec29-bounce-pagination" aria-label="Páginas de devoluções">
                                    <span>Página {{ $this->campaignBounces->currentPage() }} de {{ $this->campaignBounces->lastPage() }}</span>
                                    <div>
                                        @if ($this->campaignBounces->onFirstPage())<span aria-disabled="true">Anterior</span>@else<a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'bounces', 'filtro' => $bounceFilter, 'paginaDevolucoes' => $this->campaignBounces->currentPage() - 1]) }}">Anterior</a>@endif
                                        @if ($this->campaignBounces->hasMorePages())<a href="{{ route('campaigns.index', ['campanha' => $selected->id, 'aba' => 'bounces', 'filtro' => $bounceFilter, 'paginaDevolucoes' => $this->campaignBounces->currentPage() + 1]) }}">Próxima</a>@else<span aria-disabled="true">Próxima</span>@endif
                                    </div>
                                </nav>
                            @endif
                            <p class="ec29-bounce-note">Cada linha é um relatório DSN correlacionado; o mesmo endereço pode ter mais de um retorno. Testes piloto são excluídos destes totais. Endereço inválido pode bloquear disparos futuros; caixa cheia e bloqueio por política exigem acompanhamento.</p>
                        </div>
                    @elseif ($detailTab === 'message')
                        <div class="ecm2-message-tab"><div class="ecm2-preview-label">Mensagem salva · somente leitura</div><div class="ecm2-mail-preview"><div>De: {{ $selected->from_name }} &lt;{{ $selected->from_address }}&gt;</div><div>Assunto: <strong>{{ $selected->subject }}</strong></div><p>{{ $selected->body }}</p></div><p>As variáveis são substituídas por contato no envio. O descadastro é adicionado a cada mensagem comercial.</p></div>
                    @elseif ($detailTab === 'replies')
                        <div class="ecm2-table-wrap"><table class="ecm2-table"><thead><tr><th>Remetente</th><th>Assunto recebido</th><th>Data</th></tr></thead><tbody>
                            @forelse ($this->replies as $reply)
                                <tr wire:key="ecm2-reply-{{ $reply->id }}"><td><strong>{{ $reply->from_email }}</strong></td><td>{{ $reply->subject ?: '(sem assunto)' }}</td><td>{{ \App\Support\CampaignLocalTime::format($reply->getRawOriginal('received_at')) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="ecm2-empty-cell">Nenhuma resposta identificada pelo IMAP até agora.</td></tr>
                            @endforelse
                        </tbody></table></div>
                    @endif
                    {{-- EC_CAMPAIGNS_UNIFIED_V90: única configuração de follow-up, depois dos indicadores e respostas. --}}
                </section>
                @if ($canManageCampaigns)
                    <section id="proximos-emails" class="ec90-followup-bottom" aria-label="Próximos e-mails da campanha">
                        @include('campaigns.followup-settings-detail')
                    </section>
                @endif
            @else
                <div class="ecm2-empty-workspace">
                    <div class="ecm2-eyebrow">CAMPANHA INDISPONÍVEL</div>
                    <h2>A campanha selecionada não está mais disponível.</h2>
                    <p>Ela pode ter sido excluída em outra sessão. Escolha outra campanha na lista ou volte ao início.</p>
                    <div class="ecm2-welcome-actions"><a wire:click="showOverview" class="ecm2-btn ecm2-btn-muted" href="{{ route('campaigns.index') }}">← Visão geral</a></div>
                </div>
            @endif
        </main>

        <aside id="ecm7-context" class="ecm2-context" aria-label="Ações e informações da campanha">
            <div class="ecm7-context-heading"><strong>Detalhes e ações</strong><span>Operação e configurações desta campanha</span></div>
            @if ($screen === 'composer' && $canManageCampaigns)
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
                    <div class="ecm2-key-value"><span>Criada em</span><strong>{{ \App\Support\CampaignLocalTime::format($selected->getRawOriginal('created_at'), '') }}</strong></div>
                    <div class="ecm2-key-value"><span>Remetente</span><strong>{{ $selected->from_address }}</strong></div>
                    <div class="ecm2-key-value"><span>Respostas para</span><strong>{{ $selected->reply_to }}</strong></div>
                    <div class="ecm2-key-value"><span>Destinatários</span><strong>{{ $totalCount }}</strong></div>
                    <div class="ecm2-key-value"><span>Aprovada em</span><strong>{{ \App\Support\CampaignLocalTime::format($selected->getRawOriginal('approved_at')) }}</strong></div>
                </section>
                @if ($canManageCampaigns)
                <section id="email-teste" class="ecm2-context-card" aria-label="E-mail de teste">
                    <header><h2>E-mail de teste</h2></header>
                    <div class="ecm2-quick-actions">
                        @if (session('campaigns_test_ok'))
                            <div class="ecm2-notice is-success" role="status" style="padding:12px;border:1px solid #a0ddbc;border-radius:8px;background:#eaf8f1;color:#165f42;">
                                {{ session('campaigns_test_ok') }}
                            </div>
                        @endif
                        @if (session('campaigns_test_error'))
                            <div class="ecm2-notice is-error" role="alert" style="padding:12px;border:1px solid #eeb8ac;border-radius:8px;background:#fff0ed;color:#8b3228;">
                                {{ session('campaigns_test_error') }}
                            </div>
                        @endif
                        <form method="POST" action="{{ route('campaigns.email-test', ['campaign' => $selected->id], false) }}" style="margin:0;display:block;">
                            @csrf
                            <input type="hidden" name="aba" value="{{ $detailTab }}">
                            <button type="submit" class="ecm2-btn ecm2-btn-muted"
                                style="width:100%;color:#10374e;background:#eaf5f4;border:1px solid #9acdc5;cursor:pointer;"
                                @disabled(! config('campaign_mail.enabled') || ! filter_var((string) config('campaign_mail.test_to'), FILTER_VALIDATE_EMAIL))>
                                ✉ Enviar e-mail de teste
                            </button>
                        </form>
                        <div class="ecm2-alert-note">
                            Destino: <strong>{{ config('campaign_mail.test_to') ?: 'Não configurado' }}</strong>.
                            É enviado apenas um teste com a mensagem salva, sem usar os destinatários comerciais.
                        </div>
                        @if (! config('campaign_mail.enabled'))
                            <div class="ecm2-alert-note" role="alert">O SMTP de teste está desativado. A liberação do teste não exige ativar o envio comercial.</div>
                        @elseif (! filter_var((string) config('campaign_mail.test_to'), FILTER_VALIDATE_EMAIL))
                            <div class="ecm2-alert-note" role="alert">Configure um endereço válido em CAMPAIGN_MAIL_TEST_TO.</div>
                        @endif
                    </div>
                </section>
                @else
                    <section class="ecm2-context-card"><header><h2>Acesso somente leitura</h2></header><p>Você pode consultar campanhas e resultados. A criação, o envio e as alterações são exclusivos do gestor.</p></section>
                @endif
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
            @if ($canManageCampaigns && $screen === 'detail' && $selected && $selected->status === 'draft')
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
    @include('campaigns.send-confirmation')
    <footer class="ecm2-footer"><span aria-hidden="true">●</span> Dados locais · SMTP individual · IMAP por cabeçalhos · Sem métricas fictícias de abertura</footer>
</div>
