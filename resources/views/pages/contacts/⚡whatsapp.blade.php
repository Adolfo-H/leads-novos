<?php

use App\Models\User;
use App\Services\Contacts\BrazilPhoneNumber;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'busca')]
    public string $search = '';

    #[Url(as: 'tipo')]
    public string $kind = 'all';

    #[Url(as: 'situacao')]
    public string $status = 'all';

    #[Url(as: 'origem')]
    public string $origin = 'all';

    #[Url(as: 'consulta')]
    public string $webFilter = 'all';

    #[Url(as: 'publico')]
    public string $publicFilter = 'all'; // EC_WA_PUBLIC_SITES_V10

    public int $webBatchLimit = 5;
    public int $publicBatchSites = 5;
    // EC_WA_SIMPLE_SEARCH_V88: pesquisa pública em um clique, sem abrir snapshot do CRM.
    // EC_WA_REVIEW_APPROVE_V89: seleção consciente, nunca confirmação automática.
    public array $publicSelectedPhonesV89 = [];
    public string $publicSaveTargetV89 = '';
    public string $publicApprovalNoteV89 = '';
    public bool $publicApprovalConfirmedV89 = false;

    public string $publicQuickNameV88 = '';
    public ?string $publicQuickSearchKeyV88 = null;

    public string $publicCompanyQuery = '';
    public ?int $publicCompanyId = null; // EC_WA_PUBLIC_COMPANY_V11
    public ?string $publicBusinessKeyV85 = null; // Pesquisa comercial ampliada, sem inferir vínculos.
    public int $publicDailySites = 5;
    public bool $publicDailyEnabled = false;

    // EC_WA_UNIFIED_V7: preferências editáveis apenas pelo gestor.
    public int $webDailyLimit = 10;
    public string $webScanScope = 'all';
    public string $webDailyTime = '09:00';
    public string $webDailyEndTime = '18:00'; // EC_WA_200_DUAS_CONTAS_V9
    public bool $webDailyEnabled = false;

    public int $page = 1;

    // EC_WA_FULL_DIRECTORY_V8: paginação rápida sem buscar os milhares no navegador.
    public int $pageSize = 50;

    public ?int $selectedId = null;

    public string $note = '';

    public string $inlineCorrectedPhone = '';

    public string $inlineCorrectionNote = '';

    public function mount(): void
    {
        $publicSettings = app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)->settings();
        $this->publicDailySites = (int) $publicSettings->sites_per_day;
        $this->publicDailyEnabled = (bool) $publicSettings->auto_enabled;
        $settings = app(\App\Services\Contacts\WhatsappWebDailyCoordinator::class)->settings();
        $this->webDailyLimit = (int) $settings->daily_limit;
        $this->webScanScope = (string) $settings->scan_scope;
        $this->webDailyTime = (string) $settings->start_time;
        $this->webDailyEndTime = (string) $settings->end_time;
        $this->webDailyEnabled = (bool) $settings->auto_enabled;
        // EC_NATIVE_WA_FILTER_GET_V1: pagina, limite e numero selecionado por GET.
        // Validacao sem alterar o status dos telefones ou chamar o robo.
        $perPage = request()->query('porPagina');
        if (is_string($perPage) && in_array($perPage, ['20', '50', '100'], true)) {
            $this->pageSize = (int) $perPage;
        }
        $page = request()->query('pagina');
        if (is_string($page) && preg_match('/^[1-9][0-9]{0,5}$/D', $page) === 1) {
            $this->page = min(100000, (int) $page);
        }
        $selected = request()->query('numero');
        if (is_string($selected) && preg_match('/^[1-9][0-9]{0,10}$/D', $selected) === 1) {
            $this->selectedId = (int) $selected;
        }
    }


    // EC_WA_PAIR_V13_1: gestor solicita conexão; o Ubuntu processa o pedido.
    public function requestWebPairing(int $session, bool $reset = false): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        if (! in_array($session, [1, 2], true)) {
            $this->addError('wa_pair', 'Selecione a conta 1 ou 2.');
            return;
        }
        // EC_WA_CONNECTOR_GUARD_V83: o botao da UI fica indisponivel offline.
        // EC_WA_PAIR_COMPAT_V83_1: preservar o protocolo V13.1 para chamadas
        // Livewire autorizadas e testes. O pedido expira sozinho; o QR so e
        // gerado quando houver um conector Ubuntu realmente conectado.
        $busy = \Illuminate\Support\Facades\DB::table('whatsapp_web_scan_batches')
            ->where('session', $session)->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            $this->addError('wa_pair', 'Há um lote pendente nesta conta. Aguarde ou interrompa antes de reconectar.');
            return;
        }
        $base = 'prospector:wa:pair:v13_1:';
        \Illuminate\Support\Facades\Cache::put($base.'request:'.$session, [
            'token' => \Illuminate\Support\Str::random(40),
            'reset' => $reset,
            'actor_id' => auth()->id(),
            'requested_at' => now('UTC')->toIso8601String(),
        ], now()->addSeconds(125));
        \Illuminate\Support\Facades\Cache::forget($base.'qr:'.$session);
        \Illuminate\Support\Facades\Cache::put($base.'state:'.$session, [
            'state' => 'requested', 'at' => now('UTC')->toIso8601String(),
        ], now()->addMinutes(3));
        $beat = \Illuminate\Support\Facades\Cache::get($base.'connector:heartbeat');
        $alive = is_string($beat) && strtotime($beat) !== false
            && strtotime($beat) > time() - 60;
        unset($this->webAgents);
        session()->flash('wa_pair_notice', $alive
            ? 'Solicitação registrada. Veja o QR ou o estado da conexão na coluna direita.'
            : 'Conector Ubuntu offline: inicie integrations/whatsapp-web/iniciar-conector.sh. A solicitação expira em dois minutos.');
    }

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isCommercialManager(), 403);
    }

    public function updatedSearch(): void { $this->resetList(); }
    public function updatedKind(): void { $this->resetList(); }
    public function updatedStatus(): void { $this->resetList(); }
    public function updatedOrigin(): void { $this->resetList(); }
    public function updatedPageSize(): void
    {
        if (! in_array($this->pageSize, [20, 50, 100], true)) { $this->pageSize = 50; }
        $this->resetList();
    }
    public function updatedWebFilter(): void { $this->resetList(); }
    public function updatedPublicFilter(): void { $this->resetList(); }
    public function updatedWebBatchLimit(): void {
        if (! in_array($this->webBatchLimit, [3, 5, 10], true)) { $this->webBatchLimit = 5; }
    }

    private function resetList(): void
    {
        $this->page = 1;
        $this->selectedId = null;
        $this->note = '';
        $this->inlineCorrectedPhone = '';
        $this->inlineCorrectionNote = '';
        unset($this->items, $this->selectedNumber, $this->associations, $this->history);
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->kind = 'all';
        $this->status = 'all';
        $this->origin = 'all';
        $this->webFilter = 'all';
        $this->publicFilter = 'all';
        $this->resetList();
    }

    public function quickFilter(string $kind, string $status): void
    {
        $this->kind = in_array($kind, ['all', 'mobile', 'landline', 'review'], true) ? $kind : 'mobile';
        $this->status = in_array($status, ['all', 'unknown', 'yes', 'no'], true) ? $status : 'unknown';
        $this->webFilter = 'all';
        $this->resetList();
    }

    public function changePage(int $page): void
    {
        $this->page = max(1, min($page, $this->items->lastPage()));
        $this->selectedId = null;
        $this->note = '';
        $this->inlineCorrectedPhone = '';
        $this->inlineCorrectionNote = '';
        unset($this->items, $this->selectedNumber, $this->associations, $this->history);
    }

    public function selectNumber(int $id): void
    {
        $row = collect($this->items->items())
            ->first(static fn (object $r): bool => (int) $r->id === $id);
        abort_unless($row !== null, 404);
        $this->selectedId = $id;
        $this->note = '';
        $this->inlineCorrectionNote = '';
        $suggested = $row->kind === 'review'
            ? BrazilPhoneNumber::legacyMobileCandidate((string) $row->digits) : null;
        $this->inlineCorrectedPhone = $suggested !== null
            ? BrazilPhoneNumber::display($suggested, (string) $row->digits) : '';
        $this->resetValidation();
        unset($this->selectedNumber, $this->associations, $this->history);
    }

    public function mark(string $status): void
    {
        $selected = $this->selectedNumber;
        abort_unless($selected !== null, 404);

        app(WhatsappPhoneRegistry::class)->record(
            auth()->user(), (int) $selected->id, $status, $this->note,
        );
        $this->note = '';
        unset($this->items, $this->selectedNumber, $this->summary, $this->associations, $this->history);
        session()->flash('wa_registry_success', match ($status) {
            'yes' => 'WhatsApp confirmado manualmente e atualizado nos contatos vinculados.',
            'no' => 'Resultado da conferência salvo e atualizado nos contatos vinculados.',
            default => 'Confirmação removida; número voltou a não verificado.',
        });
    }

    // EC_WA_INLINE_REVIEW_V20: corrigir sem sair do diretório e sem consultar WhatsApp.
    public function saveInlinePhoneCorrection(): void
    {
        $selected = $this->selectedNumber;
        abort_unless($selected !== null && $selected->kind === 'review', 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        $moved = app(\App\Services\Contacts\WhatsappInlinePhoneCorrection::class)
            ->correct($actor, (int) $selected->id, $this->inlineCorrectedPhone, $this->inlineCorrectionNote);
        $this->selectedId = null;
        $this->inlineCorrectedPhone = '';
        $this->inlineCorrectionNote = '';
        unset($this->items, $this->selectedNumber, $this->summary, $this->associations, $this->history);
        session()->flash('wa_registry_success',
            'Formato corrigido no índice local; '.$moved.' vínculo(s) atualizado(s). Original preservado. WhatsApp continua não verificado.');
    }

    public function markInlinePhoneInvalid(): void
    {
        $selected = $this->selectedNumber;
        abort_unless($selected !== null && $selected->kind === 'review', 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);

        app(\App\Services\Contacts\WhatsappInlinePhoneCorrection::class)
            ->invalid($actor, (int) $selected->id, $this->inlineCorrectionNote);
        $this->selectedId = null;
        $this->inlineCorrectedPhone = '';
        $this->inlineCorrectionNote = '';
        unset($this->items, $this->selectedNumber, $this->summary, $this->associations, $this->history);
        session()->flash('wa_registry_success',
            'Formato marcado como inválido. Registro original preservado e removido das revisões pendentes.');
    }

    #[Computed]
    public function summary(): array
    {
        return app(WhatsappPhoneRegistry::class)->totals();
    }

    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return app(WhatsappPhoneRegistry::class)->search(
            $this->search, $this->kind, $this->status, $this->origin, $this->page, $this->webFilter, $this->pageSize, $this->publicFilter,
        );
    }

    #[Computed]
    public function selectedNumber(): ?object
    {
        $rows = $this->items->items();
        if ($this->selectedId !== null) {
            foreach ($rows as $row) {
                if ((int) $row->id === $this->selectedId) {
                    return $row;
                }
            }
        }

        return $rows[0] ?? null;
    }

    #[Computed]
    public function associations(): Collection
    {
        $selected = $this->selectedNumber;
        if ($selected === null) {
            return collect();
        }

        return DB::table('whatsapp_phone_sources')->where('phone_id', $selected->id)
            ->select('source_key', 'origin', 'contact_label', 'company_label', 'phone_field')
            ->orderByRaw("CASE origin WHEN 'hubspot' THEN 0 WHEN 'manual' THEN 1 ELSE 2 END")
            ->orderBy('contact_label')->limit(20)->get();
    }

    #[Computed]
    public function history(): Collection
    {
        $selected = $this->selectedNumber;
        if ($selected === null) {
            return collect();
        }

        return DB::table('whatsapp_phone_events as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_id')
            ->where('h.phone_id', $selected->id)
            ->select('h.old_status', 'h.new_status', 'h.note', 'h.created_at', 'u.name as actor_name')
            ->orderByDesc('h.id')->limit(8)->get();
    }

    // EC_WA_UNOFFICIAL_V4: consultas do cliente Web não são confirmações humanas.
    #[Computed]
    public function webProbeTotals(): array
    {
        $row = DB::table('whatsapp_web_probe_results')
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'found' THEN 1 ELSE 0 END), 0) AS found")
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'not_found' THEN 1 ELSE 0 END), 0) AS not_found")
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'inconclusive' THEN 1 ELSE 0 END), 0) AS inconclusive")
            ->selectRaw("COALESCE(SUM(CASE WHEN result = 'error' THEN 1 ELSE 0 END), 0) AS error")
            ->first();

        return [
            'found' => (int) ($row->found ?? 0),
            'not_found' => (int) ($row->not_found ?? 0),
            'inconclusive' => (int) ($row->inconclusive ?? 0),
            'error' => (int) ($row->error ?? 0),
        ];
    }

    /** Uma consulta por PÁGINA, nunca uma por contato (evita N+1). */
    #[Computed]
    public function webProbePage(): array
    {
        $ids = array_map(static fn (object $row): int => (int) $row->id, $this->items->items());
        if ($ids === []) {
            return [];
        }

        return DB::table('whatsapp_web_probe_results')
            ->whereIn('phone_id', $ids)->get()->keyBy('phone_id')->all();
    }

    // EC_WA_UNIFIED_V7: um comando para as duas sessões, com limite por dia.
    public function requestWebBoth(): void
    {
        $queued = app(\App\Services\Contacts\WhatsappWebDailyCoordinator::class)
            ->startBoth(auth()->user(), in_array($this->webBatchLimit, [3, 5, 10], true) ? $this->webBatchLimit : 5, $this->webScanScope);
        unset($this->webMetrics, $this->webAgents, $this->webRecent, $this->webDaily);
        session()->flash('wa_web_v7_message', count($queued).' conta(s) receberam um lote. Os resultados entram automaticamente em Contatos.');
    }

    public function saveWebDailyPlan(): void
    {
        app(\App\Services\Contacts\WhatsappWebDailyCoordinator::class)->save(
            auth()->user(), (int) $this->webDailyLimit, (string) $this->webDailyTime, (bool) $this->webDailyEnabled, $this->webScanScope, $this->webDailyEndTime,
        );
        unset($this->webDaily);
        session()->flash('wa_web_v7_message', $this->webDailyEnabled
            ? 'Programação diária ativa. Os agentes executarão os lotes dentro do limite, se estiverem conectados.'
            : 'Programação salva; execução automática desativada. Você pode usar Verificar agora.');
    }

    #[Computed]
    public function webDaily(): array
    {
        $coordinator = app(\App\Services\Contacts\WhatsappWebDailyCoordinator::class);
        $settings = $coordinator->settings();
        return ['settings' => $settings, 'budget' => $coordinator->budget($settings)];
    }

    // EC_WA_WEB_CONTROL_V6: monitoramento e lotes disparados APENAS pelo gestor.
    public function requestWebBatch(int $session): void
    {
        $limit = in_array($this->webBatchLimit, [3, 5, 10], true) ? $this->webBatchLimit : 5;
        app(\App\Services\Contacts\WhatsappWebControlPanel::class)->request(auth()->user(), $session, $limit, $this->webScanScope);
        unset($this->webAgents, $this->webMetrics);
        session()->flash('wa_web_v6_message', 'Lote colocado na fila do WhatsApp '.$session.'. O agente conectado iniciará a consulta.');
    }

    public function cancelWebBatch(int $id): void
    {
        app(\App\Services\Contacts\WhatsappWebControlPanel::class)->cancel(auth()->user(), $id);
        unset($this->webAgents);
        session()->flash('wa_web_v6_message', 'Parada solicitada. O agente terminará a consulta atual sem iniciar a próxima.');
    }

    public function quickWebFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['consulted', 'never', 'found', 'not_found', 'inconclusive'], true), 422);
        $this->search = '';
        $this->kind = 'all';
        $this->status = 'all';
        $this->origin = 'all';
        $this->webFilter = $filter;
        $this->resetList();
    }

    public function openWebRecent(int $id): void
    {
        $phone = DB::table('whatsapp_phone_numbers as n')
            ->join('whatsapp_web_probe_results as p', 'p.phone_id', '=', 'n.id')
            ->where('n.id', $id)->where('n.contacts_count', '>', 0)
            ->first(['n.digits', 'n.e164']);
        abort_unless($phone !== null, 404);
        $this->kind = 'all';
        $this->status = 'all';
        $this->origin = 'all';
        $this->webFilter = 'all';
        $this->search = (string) ($phone->e164 ?: $phone->digits);
        $this->resetList();
        $this->selectedId = $id;
    }

    #[Computed]
    public function webMetrics(): array
    {
        return app(\App\Services\Contacts\WhatsappWebControlPanel::class)->metrics();
    }

    #[Computed]
    public function webAgents(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Contacts\WhatsappWebControlPanel::class)->agents();
    }

    #[Computed]
    public function webRecent(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Contacts\WhatsappWebControlPanel::class)->latest();
    }

    // EC_WA_PUBLIC_SITES_V10: leitura pública independente da integração WhatsApp Web.
    public function startPublicSites(): void
    {
        $id = app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->queueRun(auth()->user(), $this->publicBatchSites);
        unset($this->publicSiteMetrics, $this->publicSiteRun, $this->publicSiteRecent);
        session()->flash('wa_public_success', 'Pesquisa #'.$id.' entrou na fila. Acompanhe o resultado nesta tela.');
    }

    public function savePublicSitesSchedule(): void
    {
        app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->saveSettings(auth()->user(), $this->publicDailyEnabled, $this->publicDailySites);
        session()->flash('wa_public_success', $this->publicDailyEnabled
            ? 'Pesquisa diária de sites públicos ativada para dias úteis às 07h10.'
            : 'Programação diária de sites públicos desativada.');
    }

    public function updatedPublicBatchSites(): void
    {
        if (! in_array($this->publicBatchSites, [3,5,10,20], true)) {
            $this->publicBatchSites = 5;
        }
    }

    public function openPublicNumber(int $phoneId, bool $allowCompanyLinked = false): void
    {
        $phone = DB::table('whatsapp_phone_numbers as n')
            ->where('n.id', $phoneId)->where('n.contacts_count', '>', 0)
            ->when(! $allowCompanyLinked, static function ($q): void {
                $q->whereExists(static function ($e): void {
                    $e->selectRaw('1')->from('whatsapp_public_site_evidences as pe')
                        ->where(static function ($or): void {
                            $or->whereColumn('pe.phone_id', 'n.id')
                                ->orWhereColumn('pe.global_phone_id', 'n.id');
                        });
                });
            })->first(['n.id', 'n.e164']);
        abort_unless($phone !== null, 404);
        $this->kind = 'all'; $this->status = 'all';
        $this->origin = 'all'; $this->webFilter = 'all'; $this->publicFilter = 'all';
        $this->search = (string) $phone->e164;
        $this->resetList();
        $this->selectedId = (int) $phone->id;
    }

    // EC_WA_ALL_BUSINESSES_V85: buscas em empresas/negócios com e sem site.
    public function selectBusinessV85(string $key): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        try {
            $business = app(\App\Services\Contacts\WhatsappBusinessGroupDirectory::class)->snapshot($key);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('public_site', 'Falha ao carregar os detalhes. A ocorrência foi registrada no log do Laravel; nenhum vínculo foi alterado.');

            return;
        }
        abort_unless($business !== null, 404);
        // Preservar integralmente a pesquisa pública V11 quando a Company tem site.
        if (str_starts_with($key, 'hubspot:') && $business['site_hubspot_company_id'] !== null) {
            $this->selectPublicCompany((int) $business['site_hubspot_company_id']);

            return;
        }
        $this->publicCompanyId = null;
        $this->publicBusinessKeyV85 = $key;
        $this->publicCompanyQuery = '';
        unset($this->publicSelectedCompany, $this->publicCompanyCandidates,
            $this->businessSelectedV85, $this->businessCandidatesV85);
    }

    public function openBusinessNumberV85(int $phoneId): void
    {
        $business = $this->businessSelectedV85;
        abort_unless($business !== null && $business['phones']->contains(
            static fn (object $phone): bool => (int) $phone->id === $phoneId), 404);
        $phone = $business['phones']->firstWhere('id', $phoneId);
        $this->kind = 'all';
        $this->status = 'all';
        $this->origin = 'all';
        $this->webFilter = 'all';
        $this->publicFilter = 'all';
        // Telefones em revisão não têm e164: buscar os dígitos originais.
        $this->search = (string) ($phone->e164 ?: $phone->digits);
        $this->resetList();
        $this->selectedId = $phoneId;
    }

    #[Computed]
    public function businessCandidatesV85(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Contacts\WhatsappBusinessGroupDirectory::class)
            ->search($this->publicCompanyQuery);
    }

    #[Computed]
    public function businessSelectedV85(): ?array
    {
        if ($this->publicBusinessKeyV85 === null) {
            return null;
        }
        try {
            return app(\App\Services\Contacts\WhatsappBusinessGroupDirectory::class)
                ->snapshot($this->publicBusinessKeyV85);
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('public_site', 'Não foi possível carregar os detalhes desta empresa. Confira o log do Laravel.');

            return null;
        }
    }

    public function selectPublicCompany(int $companyId): void
    {
        $snapshot = app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)->companySnapshot($companyId);
        abort_unless($snapshot !== null, 404);
        $this->publicBusinessKeyV85 = null;
        $this->publicCompanyId = $companyId;
        $this->publicCompanyQuery = '';
        unset($this->publicCompanyCandidates, $this->publicSelectedCompany);
    }

    public function clearPublicCompany(): void
    {
        $this->publicBusinessKeyV85 = null;
        $this->publicCompanyId = null;
        $this->publicCompanyQuery = '';
        unset($this->publicCompanyCandidates, $this->publicSelectedCompany);
    }

    // EC_WA_PUBLIC_DIRECTORY_V14_METHODS: acesso gerente, pesquisa indexada sem
    // login social e aprovação explícita antes de associar fonte de terceiros.
    public function searchExternalCompanyV14(): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        abort_unless($this->publicCompanyId !== null, 422);
        $run = app(\App\Services\Contacts\WhatsappExternalCompanyDiscovery::class)
            ->queue(auth()->user(), (int) $this->publicCompanyId);
        session()->flash('wa_public_success', 'Pesquisa externa na fila #'.$run.'. Atualize a seção para acompanhar.');
    }

    public function acceptExternalCompanyV14(int $findingId): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        abort_unless($this->publicCompanyId !== null, 422);
        app(\App\Services\Contacts\WhatsappExternalCompanyDiscovery::class)
            ->accept(auth()->user(), (int) $this->publicCompanyId, $findingId);
        unset($this->publicSelectedCompany);
        session()->flash('wa_public_success', 'Canal público adicionado à empresa; o número está disponível em Contatos.');
    }

    // EC_WA_PUBLIC_MULTI_SOURCE_V87: consulta assíncrona explícita do gestor.
    public function startCompanyWebResearchV87(string $targetKey): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        $current = $this->publicBusinessKeyV85;
        $legacy = $this->publicCompanyId !== null
            ? 'hubspot:'.$this->publicCompanyId : null;
        abort_unless($targetKey === $current || $targetKey === $legacy, 404);
        $runId = app(\App\Services\Contacts\WhatsappCompanyWebResearch::class)
            ->queue(auth()->user(), $targetKey);
        $status = (string) \Illuminate\Support\Facades\DB::table('whatsapp_company_web_runs_v87')
            ->where('id', $runId)->value('status');
        session()->flash('wa_public_success', $status === 'completed'
            ? 'Pesquisa recente reutilizada. Consulte os resultados abaixo.'
            : 'Pesquisa pública #'.$runId.' solicitada. Acompanhe o estado nesta tela.');
    }

    // EC_WA_SIMPLE_SEARCH_V88: fluxo principal não requer seleção de negócio/empresa.
    public function selectAllPublicPhonesV89(): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        $research = app(\App\Services\Contacts\WhatsappCompanyQuickResearch::class);
        $run = $this->publicQuickSearchKeyV88 !== null
            ? $research->latest($this->publicQuickSearchKeyV88) : null;
        if ($run === null || $run->status !== 'completed'
            || $research->keyFor(trim($this->publicQuickNameV88)) !== $this->publicQuickSearchKeyV88) {
            $this->addError('wa89_selection', 'Pesquise a empresa novamente para selecionar os números.');
            return;
        }
        $this->publicSelectedPhonesV89 = $research->findings($run)
            ->pluck('phone_e164')->unique()->values()->all();
    }

    public function savePublicNumbersV89(): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        $this->resetValidation('wa89_selection');
        $this->resetValidation('wa89_target');
        if (! $this->publicApprovalConfirmedV89) {
            $this->addError('wa89_selection', 'Confira as fontes e marque a confirmação antes de vincular.');
            return;
        }
        $key = $this->publicQuickSearchKeyV88;
        $research = app(\App\Services\Contacts\WhatsappCompanyQuickResearch::class);
        $run = $key !== null ? $research->latest($key) : null;
        if ($run === null || $key === null) {
            $this->addError('wa89_selection', 'Não há pesquisa concluída para aprovação.');
            return;
        }
        try {
            $out = app(\App\Services\Contacts\WhatsappCompanyPhoneApproval::class)->approve(
                auth()->user(), (string) $this->publicQuickNameV88, $key,
                (int) $run->id, (string) $this->publicSaveTargetV89,
                (array) $this->publicSelectedPhonesV89, $this->publicApprovalNoteV89
            );
            $this->publicSelectedPhonesV89 = [];
            $this->publicApprovalConfirmedV89 = false;
            session()->flash('wa89_saved', $out['created'].' vínculo(s) novo(s) criado(s); '
                .$out['already'].' já existia(m). Números e fontes preservados no Prospector.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('wa89_selection', 'Não foi possível salvar. Nenhum vínculo parcial foi confirmado. Consulte o log.');
        }
    }

    public function searchPublicCompanyV88(): void
    {
        abort_unless(auth()->user()?->isCommercialManager(), 403);
        $this->resetValidation('wa88_name');
        $service = app(\App\Services\Contacts\WhatsappCompanyQuickResearch::class);
        try {
            $name = trim($this->publicQuickNameV88);
            $service->start(auth()->user(), $name);
            $this->publicQuickSearchKeyV88 = $service->keyFor($name);
            $this->publicSelectedPhonesV89 = [];
            $this->publicSaveTargetV89 = '';
            $this->publicApprovalConfirmedV89 = false;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('wa88_name', 'Não foi possível concluir a solicitação. Confira a fila e os logs do Laravel.');
        }
    }

    public function updatedPublicQuickNameV88(): void
    {
        $this->publicSelectedPhonesV89 = [];
        $this->publicSaveTargetV89 = '';
        $this->publicApprovalConfirmedV89 = false;
        $this->publicQuickSearchKeyV88 = null;
        $this->resetValidation('wa88_name');
    }

    public function startPublicCompany(): void
    {
        abort_unless($this->publicCompanyId !== null, 422);
        $id = app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->queueCompany(auth()->user(), $this->publicCompanyId);
        unset($this->publicSiteMetrics, $this->publicSiteRun, $this->publicSiteRecent,
            $this->publicSelectedCompany);
        session()->flash('wa_public_success', $id === null
            ? 'Site pesquisado recentemente. Resultados já disponíveis abaixo.'
            : 'Pesquisa da empresa enviada para a fila #'.$id.'.');
    }

    public function openCompanyPublicNumber(int $phoneId): void
    {
        $snapshot = $this->publicSelectedCompany;
        abort_unless($snapshot !== null && $snapshot['phones']->contains(
            static fn ($p): bool => (int) $p->id === $phoneId), 404);
        $this->openPublicNumber($phoneId, true);
    }

    #[Computed]
    public function publicCompanyCandidates(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->companyCandidates($this->publicCompanyQuery);
    }

    #[Computed]
    public function publicSelectedCompany(): ?array
    {
        return $this->publicCompanyId === null ? null
            : app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
                ->companySnapshot($this->publicCompanyId);
    }

    #[Computed]
    public function publicSiteMetrics(): array
    {
        return app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)->metrics();
    }

    #[Computed]
    public function publicSiteRun(): ?object
    {
        return app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)->activeRun();
    }

    #[Computed]
    public function publicSiteRecent(): \Illuminate\Support\Collection
    {
        return app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)->recent(12);
    }

    #[Computed]
    public function publicEvidencePage(): array
    {
        $ids = array_map(static fn ($r): int => (int) $r->id, $this->items->items());
        return app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->pageEvidence($ids)->groupBy(static fn ($item): int => (int) ($item->phone_id ?: $item->global_phone_id))
                ->map(static fn ($items) => $items->first())->all();
    }

    #[Computed]
    public function publicEvidenceSelected(): \Illuminate\Support\Collection
    {
        $phone = $this->selectedNumber;
        return $phone === null ? collect() : app(\App\Services\Contacts\WhatsappPublicSiteResearch::class)
            ->pageEvidence([(int) $phone->id])->take(3)->values();
    }

    #[Computed]
    public function lastSync(): ?object
    {
        return DB::table('whatsapp_phone_sync_runs')->orderByDesc('id')->first();
    }
};
?>
@php
    $items = $this->items;
    $rows = $items->items();
    $summary = $this->summary;
    $selected = $this->selectedNumber;
    $fmt = static fn (int $value): string => number_format($value, 0, ',', '.');
    $hasManualConfirmation = static function (string $status, ?string $evidence, mixed $verifiedBy = null): bool {
        if ($status === 'yes' && in_array($evidence, [
            'manual_app_confirmed', 'directory_manual_check',
        ], true)) {
            return true;
        }
        if ($status === 'no' && in_array($evidence, [
            'manual_app_unavailable', 'directory_manual_check',
        ], true)) {
            return true;
        }

        return in_array($status, ['yes', 'no'], true)
            && $evidence === 'legacy_manual' && $verifiedBy !== null;
    };
    // EC_WA_WEB_RESULT_V1: o status da tela representa o resultado real da consulta.
    // NAO escreve uma confirmacao humana e NAO trata 'inconclusive' como 'not_found'.
    $statusLabel = static function (string $status, ?string $evidence = null, mixed $verifiedBy = null) use ($hasManualConfirmation): string {
        if ($status === 'yes' && $evidence === 'web_found_unofficial') {
            return 'Tem WhatsApp · robô';
        }
        if (! $hasManualConfirmation($status, $evidence, $verifiedBy)) {
            return 'A verificar';
        }

        return match ($status) {
            'yes' => 'Com WhatsApp', 'no' => 'Não possui', default => 'A verificar',
        };
    };
    /**
     * Resultado visual, nunca uma confirmacao humana ou gravacao em Contatos.
     * Confirmacoes manuais prevalecem sobre a biblioteca nao oficial.
     * @return array{css:string,label:string,source:string}
     */
    $webDisplayStatus = static function (object $phone, ?object $probe) use ($hasManualConfirmation, $statusLabel): array {
        $status = (string) ($phone->status ?? 'unknown');
        $evidence = (string) ($phone->evidence ?? '');
        $verifiedBy = $phone->verified_by ?? null;
        if ($hasManualConfirmation($status, $evidence, $verifiedBy)) {
            return ['css' => $status, 'label' => $statusLabel($status, $evidence, $verifiedBy), 'source' => 'manual'];
        }
        $result = (string) ($probe->result ?? '');
        if ($status === 'yes' && $evidence === 'web_found_unofficial') {
            if ($result === 'not_found') {
                return ['css' => 'unknown', 'label' => 'Resultados divergentes · revisar', 'source' => 'conflict'];
            }
            return ['css' => 'yes', 'label' => 'Tem WhatsApp · robô', 'source' => 'web_found'];
        }
        if ($status === 'unknown' && $result === 'not_found') {
            return ['css' => 'no', 'label' => 'Não possui WhatsApp · robô', 'source' => 'web_not_found'];
        }
        if ($status === 'unknown' && $result === 'inconclusive') {
            return ['css' => 'unknown', 'label' => 'Consulta inconclusiva', 'source' => 'inconclusive'];
        }
        if ($status === 'unknown' && $result === 'error') {
            return ['css' => 'unknown', 'label' => 'Falha na consulta', 'source' => 'error'];
        }
        if ($status === 'unknown' && $result === 'found') {
            // Promocao pode ter sido bloqueada por uma decisao antiga conflitante.
            return ['css' => 'unknown', 'label' => 'Encontrado · conferir vinculo', 'source' => 'conflict'];
        }

        return ['css' => 'unknown', 'label' => 'A verificar', 'source' => 'pending'];
    };
    $kindLabel = static fn (string $kind): string => match ($kind) {
        'mobile' => 'Celular', 'landline' => 'Fixo', default => 'Revisar formato',
    };
    $originLabel = static fn (string $source): string => match ($source) {
        'hubspot' => 'HubSpot', 'manual' => 'Manual', default => 'Receita Federal',
    };
    $verified = (int) $summary['yes'] + (int) $summary['no'];
    $valid = (int) $summary['mobile'] + (int) $summary['landline'];
    $percentage = $valid > 0 ? min(100, (int) round($verified * 100 / $valid)) : 0;
@endphp
<style id="ec941-wa-style">
    .ec941-wa-form {display:grid;gap:10px}
    .ec941-confirm {display:flex;gap:8px;align-items:flex-start;color:#35576b;font-size:12px;line-height:1.5}
    .ec941-confirm input {accent-color:#008f82;margin-top:3px}
    .ec941-wa-form .wpr-action-row {display:flex;gap:8px;flex-wrap:wrap}
    </style>
<style id="wa-web-result-v1-style">
    .wpr .wa-web-robot-positive {background:#eaf8f0;border-color:#9cddba;color:#135b3c}
    .wpr .wa-web-robot-positive :is(strong,p) {color:#135b3c}
    .wpr .wa-web-robot-negative {background:#fff5ed;border-color:#ecc9af;color:#89421f}
    .wpr .wa-web-robot-negative :is(strong,p) {color:#89421f}
    .wpr .wa-web-robot-manual {margin-top:13px;border:1px solid #d8e5e6;border-radius:10px;background:#f7faf9;padding:12px}
    .wpr .wa-web-robot-manual > summary {cursor:pointer;color:#215766;font-weight:700;font-size:13px}
    .wpr .wa-web-robot-manual[open] > form {margin-top:12px}
    .wpr .wa-web-robot-manual .ec941-wa-form {margin-top:12px}
    html.dark .wpr .wa-web-robot-positive {background:#153d33;color:#d6f7e5;border-color:#386c55}
    html.dark .wpr .wa-web-robot-positive :is(strong,p) {color:#d6f7e5}
    html.dark .wpr .wa-web-robot-negative {background:#4b3028;color:#ffe0cd;border-color:#96634e}
    html.dark .wpr .wa-web-robot-negative :is(strong,p) {color:#ffe0cd}
    html.dark .wpr .wa-web-robot-manual {background:#1f2d32;border-color:#3e5055}
    html.dark .wpr .wa-web-robot-manual > summary {color:#c6f4e9}
</style>
<div class="wpr wpr-v2 wpr-v3 wpr-v7 wpr-v8 wa12" wire:poll.30s data-test="whatsapp-phone-registry-v1" data-ec-whatsapp-design="v2" data-test-v12="central-por-tarefas"
    {{-- EC_WA_PAIR_HTTP_V1: ao voltar do POST, mostrar a aba do QR. --}}
    x-data="{ tab: window.location.hash === '#wa12-operation' || new URLSearchParams(window.location.search).get('aba') === 'operation' ? 'operation' : window.location.hash === '#wa11-empresa' ? 'company' : 'numbers' }"
>
    @include('contacts.whatsapp-registry-styles')
    @include('contacts.whatsapp-registry-light-theme')
    @include('contacts.whatsapp-registry-theme-overrides')
    @include('contacts.whatsapp-web-results-styles')
    @include('contacts.whatsapp-web-controls-styles')
    @include('contacts.whatsapp-verification-workspace-styles')
    @include('contacts.whatsapp-verification-operation-styles')
    @include('contacts.whatsapp-verification-schedule-styles')
    @include('contacts.whatsapp-verification-tabs-styles') {{-- EC_WA_VISUAL_V12 --}}
    <header class="wpr-heading">
        <div>
            <p class="wpr-kicker">RELACIONAMENTO <span> / </span> VERIFICAÇÃO WHATSAPP</p>
            <h1>Verificação WhatsApp</h1>
            <p>Uma central para consultar, confirmar e acompanhar os telefones dos seus contatos.</p>
        </div>
        <a class="wpr-button wpr-button-quiet" href="{{ route('contacts.index') }}" wire:navigate>← Voltar aos contatos</a>
    </header>

    {{-- EC_WA_UNIFIED_V7_VIEW: visão geral, execução e histórico em um único painel. --}}
    @include('contacts.whatsapp-summary')
    @include('contacts.whatsapp-tabs')

    @if (session('wa_registry_success'))
        <div class="wpr-alert" role="status">✓ {{ session('wa_registry_success') }}</div>
    @endif
    @error('wa_status')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('wa_note')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('confirmed')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('status')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('note')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror



    <section id="wa12-numbers" class="wa12-pane wa12-pane-numbers" role="tabpanel" x-show="tab==='numbers'" aria-label="Lista de números cadastrados">
    <section class="wa8-directory-head" id="wa8-lista" aria-label="Todos os telefones registrados">
        <div>
            <strong>Lista completa de números</strong>
            <small>Selecione um telefone da sua base para conferir o status e ver os contatos vinculados.</small>
        </div>
        <button type="button" class="wa12-primary" x-on:click="tab='operation'">Verificar números agora ↗</button>
    </section>
    <p class="wa12-hint">Os números com formato inconsistente continuam disponíveis para revisão, sem entrar nos lotes automáticos.</p>

    <form id="ec-native-wa-filter" method="GET" action="{{ route('contacts.whatsapp') }}" class="wpr-filters wa12-filters" aria-label="Filtrar lista de telefones">
        <label class="wpr-search"><span>Buscar número, contato ou empresa</span>
            <input type="search" name="busca" value="{{ $search }}" wire:model.live.debounce.400ms="search" placeholder="Pesquisar em todos os telefones..." autocomplete="off">
        </label>
        <label><span>Tipo de número</span><select name="tipo" wire:model.live="kind"><option value="all"@if($kind === 'all') selected @endif>Todos</option><option value="mobile"@if($kind === 'mobile') selected @endif>Celulares</option><option value="landline"@if($kind === 'landline') selected @endif>Fixos</option><option value="review"@if($kind === 'review') selected @endif>Revisar formato</option></select></label>
        <label><span>Status WhatsApp</span><select name="situacao" wire:model.live="status"><option value="all"@if($status === 'all') selected @endif>Todos</option><option value="unknown"@if($status === 'unknown') selected @endif>A verificar</option><option value="yes"@if($status === 'yes') selected @endif>Com WhatsApp</option><option value="no"@if($status === 'no') selected @endif>Não possui</option></select></label>
        <label><span>Origem</span><select name="origem" wire:model.live="origin"><option value="all"@if($origin === 'all') selected @endif>Todas</option><option value="hubspot"@if($origin === 'hubspot') selected @endif>HubSpot</option><option value="manual"@if($origin === 'manual') selected @endif>Manual</option><option value="receita"@if($origin === 'receita') selected @endif>Receita Federal</option></select></label>
        <a class="wpr-button wpr-button-quiet wa12-clear" href="{{ route('contacts.whatsapp') }}">Limpar</a>
        <button type="submit" class="wpr-button wpr-button-primary ec-native-wa-apply">Aplicar filtros</button>
        <details class="wa12-advanced">
            <summary>Mais filtros de pesquisa ⌄</summary>
            <div class="wa12-advanced-grid">
                <label><span>Divulgação pública</span><select name="publico" wire:model.live="publicFilter"><option value="all"@if($publicFilter === 'all') selected @endif>Todos</option><option value="published"@if($publicFilter === 'published') selected @endif>Divulgado em site</option><option value="not_published"@if($publicFilter === 'not_published') selected @endif>Sem link público</option></select></label>
                <label><span>Consulta WhatsApp Web</span><select name="consulta" wire:model.live="webFilter"><option value="all"@if($webFilter === 'all') selected @endif>Todas as consultas</option><option value="never"@if($webFilter === 'never') selected @endif>Não consultados</option><option value="consulted"@if($webFilter === 'consulted') selected @endif>Já consultados</option><option value="found"@if($webFilter === 'found') selected @endif>Encontrados</option><option value="not_found"@if($webFilter === 'not_found') selected @endif>Não localizados</option><option value="inconclusive"@if($webFilter === 'inconclusive') selected @endif>Inconclusivos / erros</option></select></label>
            </div>
        </details>
    </form>

    <style id="ec-native-wa-filters-css">
        .wpr form.wa12-filters .ec-native-wa-apply{min-height:40px;white-space:nowrap;color:#fff}
        .wpr .ec-native-wa-page-size{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .wpr .ec-native-wa-page-size button{border:1px solid #cbdde4;background:#e7f6f2;color:#086b62;border-radius:7px;padding:7px 11px;font-size:11px;font-weight:700;cursor:pointer}
        .wpr .ec-native-wa-pager{display:inline-flex;align-items:center;justify-content:center;min-width:55px;min-height:31px;border:1px solid #d8e6eb;border-radius:8px;background:#fff;color:#087b70;text-decoration:none;padding:6px 10px}
        .wpr .ec-native-wa-pager[aria-disabled="true"]{opacity:.5}
        .wpr .wpr-number-list a.wpr-number{display:flex;text-decoration:none}
        html.dark .wpr .ec-native-wa-page-size button,html.dark .wpr .ec-native-wa-pager{background:#172f34;color:#c4efe4;border-color:#45646a}
    </style>
    <div class="wpr-workspace" wire:loading.class="wpr-is-loading" wire:target="search,kind,status,origin,changePage,mark">
        <section class="wpr-list-panel" aria-label="Números encontrados">
            <div class="wpr-panel-header"><div><h2>Todos os números e contatos</h2><p>{{ $fmt($items->total()) }} números com os filtros atuais</p></div><form method="GET" action="{{ route('contacts.whatsapp') }}" class="ec-native-wa-page-size">
                <input type="hidden" name="busca" value="{{ $search }}">
                <input type="hidden" name="tipo" value="{{ $kind }}">
                <input type="hidden" name="situacao" value="{{ $status }}">
                <input type="hidden" name="origem" value="{{ $origin }}">
                <input type="hidden" name="consulta" value="{{ $webFilter }}">
                <input type="hidden" name="publico" value="{{ $publicFilter }}">
                <label class="wa8-per-page">Exibir <select name="porPagina" wire:model.live="pageSize" aria-label="Números por página"><option value="20"@if($pageSize === 20) selected @endif>20</option><option value="50"@if($pageSize === 50) selected @endif>50</option><option value="100"@if($pageSize === 100) selected @endif>100</option></select> por página</label>
                <button type="submit">Exibir</button>
            </form></div>
            @if ($rows === [])
                <div class="wpr-empty"><strong>Nenhum número encontrado</strong><p>Altere os filtros para visualizar outra categoria. A lista precisa ser sincronizada pelo comando de indexação.</p><button wire:click="resetFilters" type="button">Mostrar todos os números</button></div>
            @else
                <div class="wpr-number-list">
                    @foreach ($rows as $row)
                        @php
                            $active = (int) $selected?->id === (int) $row->id;
                            $number = BrazilPhoneNumber::display($row->e164, $row->digits);
                            $rowProbe = $this->webProbePage[(int) $row->id] ?? null;
                            $rowView = $webDisplayStatus($row, $rowProbe);
                            $rowStatus = $rowView['css'];
                        @endphp
                        <a href="{{ route('contacts.whatsapp', ['busca' => $search, 'tipo' => $kind, 'situacao' => $status, 'origem' => $origin, 'consulta' => $webFilter, 'publico' => $publicFilter, 'porPagina' => $pageSize, 'pagina' => $items->currentPage(), 'numero' => $row->id]) }}" wire:key="wpr-number-{{ $row->id }}" class="wpr-number {{ $active ? 'is-active' : '' }}" aria-current="{{ $active ? 'true' : 'false' }}">
                            <span class="wpr-number-icon">{{ $row->kind === 'mobile' ? '◉' : ($row->kind === 'landline' ? '☎' : '!') }}</span>
                            <span class="wpr-number-content"><strong>{{ $number }}</strong><small>{{ $kindLabel($row->kind) }} · {{ $fmt((int) $row->contacts_count) }} registro(s) vinculado(s) @if ($rowProbe) · <b class="wpr-web-v4-list-note">{{ match ($rowProbe->result) {'found' => 'Encontrado pelo robô', 'not_found' => 'Não localizado pelo robô', 'inconclusive' => 'Consulta inconclusiva', default => 'Falha na consulta'} }}</b> @endif</small></span>
                            @if(($publicLink = $this->publicEvidencePage[(int) $row->id] ?? null))
                                <span class="wa10-public-pill" title="Divulgação pública; conta pode não estar ativa">Divulgado no site</span>
                            @endif
                            <span class="wpr-status wpr-status-{{ $rowStatus }}" title="{{ $rowView['source'] === 'manual' ? 'Confirmado manualmente' : 'Resultado automático ou pendente, sem confirmação humana' }}">{{ $rowView['label'] }}</span>
                            <span class="wpr-number-chevron">›</span>
                        </a>
                    @endforeach
                </div>
            @endif
            <footer class="wpr-pagination"><span>{{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} de {{ $fmt($items->total()) }}</span><div>@if ($items->onFirstPage())<span class="ec-native-wa-pager" aria-disabled="true">Anterior</span>@else<a class="ec-native-wa-pager" href="{{ route('contacts.whatsapp', ['busca' => $search, 'tipo' => $kind, 'situacao' => $status, 'origem' => $origin, 'consulta' => $webFilter, 'publico' => $publicFilter, 'porPagina' => $pageSize, 'pagina' => $items->currentPage() - 1]) }}">Anterior</a>@endif<span>{{ $items->currentPage() }} / {{ $items->lastPage() }}</span>@if ($items->hasMorePages())<a class="ec-native-wa-pager" href="{{ route('contacts.whatsapp', ['busca' => $search, 'tipo' => $kind, 'situacao' => $status, 'origem' => $origin, 'consulta' => $webFilter, 'publico' => $publicFilter, 'porPagina' => $pageSize, 'pagina' => $items->currentPage() + 1]) }}">Próxima</a>@else<span class="ec-native-wa-pager" aria-disabled="true">Próxima</span>@endif</div></footer>
        </section>

        <aside class="wpr-detail-panel" aria-label="Verificação do telefone selecionado">
            @if ($selected)
                @php
                    $selectedProbe = $this->webProbePage[(int) $selected->id] ?? null;
                    $selectedView = $webDisplayStatus($selected, $selectedProbe);
                    $selectedStatus = $selectedView['css'];
                @endphp
                <div class="wpr-detail-top"><span class="wpr-eyebrow">TELEFONE SELECIONADO</span><span class="wpr-status wpr-status-{{ $selectedStatus }}">{{ $selectedView['label'] }}</span></div>
                <h2>{{ BrazilPhoneNumber::display($selected->e164, $selected->digits) }}</h2>
                <p class="wpr-muted">{{ $kindLabel($selected->kind) }} · {{ $fmt((int) $selected->contacts_count) }} registro(s), {{ $fmt((int) $selected->occurrences_count) }} ocorrência(s)</p>


                @if ($this->publicEvidenceSelected->isNotEmpty())
                    <div class="wa10-proof"><strong>Divulgado como WhatsApp em site empresarial</strong>
                        @foreach($this->publicEvidenceSelected as $publicSource)
                            <p>{{ $publicSource->company_label ?: $publicSource->domain }} · {{ \Carbon\Carbon::parse($publicSource->last_seen_at)->timezone('America/Sao_Paulo')->format('d/m/Y') }}</p>
                            <a href="{{ $publicSource->source_url }}" target="_blank" rel="noopener noreferrer">Ver publicação original ↗</a>
                        @endforeach
                        <p>A divulgação não substitui uma confirmação de conta ativa.</p>
                    </div>
                @endif
                @include('contacts.whatsapp-selected')
                {{-- A apresentação é automática, mas a fonte da evidência permanece explícita. --}}
                @if ($selectedView['source'] === 'inconclusive')
                    <div class="wpr-warning" role="status"><strong>Consulta inconclusiva</strong>
                        <p>O robô não concluiu a consulta. Não é uma resposta negativa. Não confirmado — não utilizar WhatsApp como verificado até nova consulta.</p>
                    </div>
                @elseif ($selectedView['source'] === 'web_found')
                    <div class="wpr-warning wa-web-robot-positive" role="status"><strong>✓ Tem WhatsApp · encontrado pelo robô</strong>
                        <p>O resultado positivo já foi registrado automaticamente no índice da central. Você não precisa confirmá-lo manualmente nesta página. Origem: integração Web não oficial, diferente de uma confirmação humana no aplicativo.</p>
                    </div>
                @elseif ($selectedView['source'] === 'web_not_found')
                    <div class="wpr-warning wa-web-robot-negative" role="status"><strong>Não possui WhatsApp · resultado do robô</strong>
                        <p>O robô não encontrou conta nesta consulta. A tela mostra esse resultado diretamente, mas a ausência não foi confirmada manualmente; o cadastro definitivo não é marcado como “não possui”, pois um resultado negativo pode falhar.</p>
                    </div>
                @elseif ($selectedView['source'] === 'conflict')
                    <div class="wpr-warning" role="status"><strong>Consultas divergentes ou vínculo não validado</strong>
                        <p>Há sinais técnicos conflitantes ou uma decisão anterior. O sistema preservou o cadastro e recomenda nova consulta.</p>
                    </div>
                @endif

                @if ($selected->kind === 'review')
                    <div class="wpr-warning"><strong>Formato precisa de revisão</strong><p>Confira o DDD, o prefixo e os dígitos diretamente no HubSpot ou no cadastro de origem. Este número não pode ser marcado como confirmado enquanto estiver inconsistente.</p></div>
                    @php $formatHint = BrazilPhoneNumber::hint($selected->digits); @endphp
                    @if ($formatHint)
                        <div class="wpr-v3-format-hint">
                            <strong>{{ $formatHint['title'] }}</strong>
                            <p>{{ $formatHint['message'] }}</p>
                            @if ($formatHint['candidate'])
                                <span class="wpr-v3-format-candidate">Possível correção: <b>{{ $formatHint['candidate'] }}</b><small>Sugestão não aplicada e não verificada. Confirme na origem.</small></span>
                            @endif
                        </div>
                    @endif
                    {{-- EC_WA_INLINE_REVIEW_V20 --}}
                    @include('contacts.whatsapp-inline-review')
                @else
                    <div class="wpr-action-panel">
                        <h3>{{ in_array($selectedView['source'], ['web_found', 'web_not_found'], true) ? 'Resultado do robô já disponível' : 'Conferir no aplicativo' }}</h3>
                        <p>{{ in_array($selectedView['source'], ['web_found', 'web_not_found'], true) ? 'A conferência manual é opcional para corrigir um resultado. Abrir WhatsApp não envia mensagem.' : 'Abra a conversa para verificar manualmente. A abertura do link não altera o status e não envia mensagem.' }}</p>
                        <a class="wpr-button wpr-button-primary" href="https://wa.me/{{ $selected->e164 }}" target="_blank" rel="noopener noreferrer">↗ Abrir no WhatsApp</a>
                        {{-- EC941_WA_MANUAL_POST: confirmação real por POST, não depende de wire:click. --}}
                        @if (in_array($selectedView['source'], ['web_found', 'web_not_found'], true))
                            <details class="wa-web-robot-manual"><summary>Corrigir resultado manualmente (opcional)</summary>
                        @endif
                        <form method="POST" action="{{ route('contacts.whatsapp.verify-v941', ['phone' => $selected->id]) }}" class="ec941-wa-form">
                            @csrf
                            <label class="wpr-note-label"><span>Observação da conferência (obrigatória para “Não possui”)</span>
                                <textarea name="note" rows="2" maxlength="300" placeholder="Observação da confirmação no aplicativo">{{ old('note') }}</textarea>
                            </label>
                            @error('note')<p class="ecc3-error" role="alert">{{ $message }}</p>@enderror
                            <label class="ec941-confirm"><input type="checkbox" name="confirmed" value="1" required> Conferi diretamente este número no aplicativo e confirmo o resultado escolhido.</label>
                            @error('confirmed')<p class="ecc3-error" role="alert">{{ $message }}</p>@enderror
                            <div class="wpr-action-row">
                                <button type="submit" name="status" value="yes" class="wpr-button wpr-button-positive">✓ Tem WhatsApp</button>
                                <button type="submit" name="status" value="no" class="wpr-button wpr-button-neutral">× Não possui</button>
                            </div>
                        </form>
                        @if ($hasManualConfirmation($selected->status, $selected->evidence, $selected->verified_by))
                            <form method="POST" action="{{ route('contacts.whatsapp.verify-v941', ['phone' => $selected->id]) }}">
                                @csrf <input type="hidden" name="status" value="unknown">
                                <label class="ec941-confirm"><input type="checkbox" name="confirmed" value="1" required> Confirmo que desejo desfazer a confirmação deste número e dos contatos vinculados.</label>
                                @error('confirmed')<p class="ecc3-error" role="alert">{{ $message }}</p>@enderror
                                <button type="submit" class="wpr-clear" onclick="return confirm('Desfazer a confirmação deste telefone e dos contatos vinculados?')">↶ Desfazer confirmação</button>
                            </form>
                        @endif
                        @if (in_array($selectedView['source'], ['web_found', 'web_not_found'], true))
                            </details>
                        @endif
                    </div>
                @endif

                @if ($selected->verified_at)
                    <p class="wpr-last-verified">{{ $selectedView['source'] === 'web_found' ? 'Última detecção do robô' : 'Última confirmação registrada' }} em {{ \Carbon\Carbon::parse($selected->verified_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}.</p>
                @endif

                <section class="wpr-linked"><div class="wpr-subhead"><h3>Contatos vinculados</h3><span>{{ $fmt((int) $selected->contacts_count) }}</span></div><p class="wpr-muted">A confirmação do número é compartilhada com os registros abaixo. São exibidos até 20 vínculos.</p>
                    @forelse ($this->associations as $association)
                        <div class="wpr-linked-row"><span class="wpr-origin wpr-origin-{{ $association->origin }}">{{ $originLabel($association->origin) }}</span><div><strong>{{ $association->contact_label }}</strong><small>{{ $association->company_label ?: 'Empresa não informada' }} · {{ $association->phone_field === 'mobile_phone' ? 'Celular / telefone 2' : 'Telefone' }}</small></div></div>
                    @empty
                        <p class="wpr-muted">Nenhum vínculo ativo.</p>
                    @endforelse
                </section>

                <section class="wpr-history"><div class="wpr-subhead"><h3>Histórico de verificações</h3></div>
                    @forelse ($this->history as $event)
                        <div class="wpr-event"><span class="wpr-event-point"></span><div><strong>{{ $statusLabel($event->new_status) }}</strong><small>{{ $event->actor_name ?: 'Usuário removido' }} · {{ \Carbon\Carbon::parse($event->created_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</small>@if ($event->note)<p>{{ $event->note }}</p>@endif</div></div>
                    @empty
                        <p class="wpr-muted">Ainda não há confirmação registrada nesta central.</p>
                    @endforelse
                </section>
            @else
                <div class="wpr-empty"><strong>Selecione um número</strong><p>Os detalhes e controles de verificação aparecerão aqui.</p></div>
            @endif
        </aside>
    </div>
    </section> {{-- EC92_WA_LABEL: status técnico aparece sem alterar confirmações humanas. --}} {{-- /wa12-numbers --}}
    {{-- EC_WA_PUBLIC_COMPANY_V11: o diretório vem ANTES da pesquisa complementar. --}}
    <section id="wa12-company" class="wa12-pane wa12-pane-company" role="tabpanel" x-show="tab==='company'" x-cloak aria-label="Pesquisar WhatsApp de uma empresa">
        {{-- V88: fluxo principal simples; o legado continua acessível apenas para ações já existentes. --}}
        @if($publicCompanyId !== null || $publicBusinessKeyV85 !== null || mb_strlen(trim($publicCompanyQuery)) >= 3)
            @include('contacts.whatsapp-company-public-research')
        @else
            @include('contacts.whatsapp-pesquisa-simples')
        @endif
    </section>
    {{-- EC_WA_FULL_DIRECTORY_V8: execução e histórico depois da lista completa. --}}
    <section id="wa12-operation" class="wa12-pane wa12-pane-operation" role="tabpanel" x-show="tab==='operation'" x-cloak aria-label="Lotes, programação e histórico">
        @include('contacts.whatsapp-operation')
    </section>
</div>
