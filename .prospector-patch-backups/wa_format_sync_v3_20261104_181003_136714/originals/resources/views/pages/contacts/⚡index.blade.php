<?php

// EC_RECEITA_UNIFIED_V10: integra canais da Receita na tabela e no seletor de campanhas.
use App\Models\CommercialContact;
use App\Models\ContactPhoneVerification;
use App\Models\HubSpotImportRun;
use App\Services\Contacts\ContactDirectory;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    #[Url(as: 'q')]
    public string $searchTerm = '';

    #[Url(as: 'filtro')]
    public string $segment = 'all';

    #[Url(as: 'empresa')]
    public ?string $selectedKey = null;

    public int $companyPage = 1;

    public int $peoplePage = 1;

    public string $peopleSearch = '';

    public string $peopleOrigin = 'all';

    public string $whatsappFilter = 'all';

    public ?string $selectedPersonKey = null;

    public bool $formOpen = false;

    public ?int $editingContactId = null;

    public string $formName = '';

    public string $formJob = '';

    public string $formEmail = '';

    public string $formPhone = '';

    public string $formMobile = '';

    public string $formNotes = '';

    // EC_PACKAGE18_CONTACTS_VISUAL_CLEAN: links legados ?filtro=fiscal voltam ao filtro geral.
    public function mount(): void
    {
        if ($this->segment === 'fiscal') {
            $this->segment = 'all';
        }
    }

    public function updatedSearchTerm(): void
    {
        $this->resetDirectory();
    }

    public function updatedSegment(): void
    {
        if (! in_array($this->segment, ['all', 'clients', 'deals', 'without_people', 'hubspot', 'receita', 'orphan', 'manual', 'unlinked', 'wa_yes', 'wa_unknown', 'with_email', 'with_phone'], true)) {
            $this->segment = 'all';
        }
        $this->resetDirectory();
    }

    public function updatedPeopleSearch(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
        $this->contactsPage = 1;
        unset($this->contactIndex);
    }

    public function updatedPeopleOrigin(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
        $this->contactsPage = 1;
        unset($this->contactIndex);
    }

    public function updatedWhatsappFilter(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
        $this->contactsPage = 1;
        $this->checkedContactKeys = [];
        unset($this->contactIndex);
    }

    private function resetDirectory(): void
    {
        $this->companyPage = 1;
        $this->peoplePage = 1;
        $this->selectedKey = null;
        $this->selectedPersonKey = null;
        $this->contactsPage = 1;
        $this->checkedContactKeys = [];
        unset($this->contactIndex);
    }

    public function clearFilters(): void
    {
        $this->searchTerm = '';
        $this->segment = 'all';
        $this->peopleSearch = '';
        $this->peopleOrigin = 'all';
        $this->whatsappFilter = 'all';
        $this->resetDirectory();
    }

    #[Computed]
    public function directory(): LengthAwarePaginator
    {
        return app(ContactDirectory::class)->companies($this->searchTerm, $this->segment, $this->companyPage);
    }

    public function activeKey(): string
    {
        if ($this->selectedKey !== null) {
            return $this->selectedKey;
        }
        if ($this->segment === 'orphan') {
            return 'orphan:0';
        }
        $first = $this->directory->items()[0] ?? null;

        return $first ? $first->directory_type.':'.$first->record_id : '';
    }

    #[Computed]
    public function detail(): ?array
    {
        return app(ContactDirectory::class)->selected(
            $this->activeKey(),
            $this->peoplePage,
            $this->peopleSearch,
            $this->peopleOrigin,
            $this->whatsappFilter,
        );
    }

    #[Computed]
    public function summary(): array
    {
        return app(ContactDirectory::class)->counts();
    }

    public function selectCompany(string $type, int $id): void
    {
        if (! in_array($type, ['local', 'hubspot', 'orphan'], true)) {
            return;
        }
        $key = $type.':'.$id;
        if (app(ContactDirectory::class)->selected($key) === null) {
            return;
        }
        $this->selectedKey = $key;
        $this->inspectorOpen = true;
        $this->peoplePage = 1;
        $this->peopleSearch = '';
        $this->selectedPersonKey = null;
        $this->formOpen = false;
        unset($this->contactIndex);
    }

    public function showPerson(string $key): void
    {
        $detail = $this->detail;
        if (! $detail) {
            return;
        }
        foreach ($detail['people']->items() as $item) {
            if ($item->source_key === $key) {
                $this->selectedPersonKey = $key;

                return;
            }
        }
    }

    public function changeCompanyPage(int $page): void
    {
        $this->companyPage = max(1, min(100000, $page));
        $this->selectedKey = null;
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
    }

    public function changePeoplePage(int $page): void
    {
        $this->peoplePage = max(1, min(100000, $page));
        $this->selectedPersonKey = null;
    }

    public function startNewContact(): void
    {
        if (! $this->detail || $this->detail['kind'] === 'orphan') {
            $this->addError('contact', 'Escolha uma empresa antes de cadastrar.');

            return;
        }
        $this->resetForm();
        $this->formOpen = true;
    }

    public function editManualContact(int $id, ContactDirectory $directory): void
    {
        $contact = $directory->manualInSelection($this->activeKey(), $id);
        abort_unless($contact !== null, 403);
        $this->resetForm();
        $this->editingContactId = (int) $contact->id;
        $this->formName = (string) $contact->name;
        $this->formJob = (string) $contact->job_title;
        $this->formEmail = (string) $contact->email;
        $this->formPhone = (string) $contact->phone;
        $this->formMobile = (string) $contact->mobile_phone;
        $this->formNotes = (string) $contact->notes;
        $this->formOpen = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->formOpen = false;
    }

    private function resetForm(): void
    {
        $this->editingContactId = null;
        $this->formName = '';
        $this->formJob = '';
        $this->formEmail = '';
        $this->formPhone = '';
        $this->formMobile = '';
        $this->formNotes = '';
        $this->resetValidation();
    }

    public function saveContact(ContactDirectory $directory): void
    {
        $validated = $this->validate([
            'formName' => ['required', 'string', 'min:2', 'max:180'],
            'formJob' => ['nullable', 'string', 'max:180'],
            'formEmail' => ['nullable', 'email:rfc', 'max:255'],
            'formPhone' => ['nullable', 'string', 'max:80'],
            'formMobile' => ['nullable', 'string', 'max:80'],
            'formNotes' => ['nullable', 'string', 'max:3000'],
        ]);
        $key = $this->activeKey();
        $target = $directory->manualTarget($key);
        $attrs = [
            'name' => trim($validated['formName']),
            'job_title' => trim($validated['formJob']) ?: null,
            'email' => trim($validated['formEmail']) ?: null,
            'phone' => trim($validated['formPhone']) ?: null,
            'mobile_phone' => trim($validated['formMobile']) ?: null,
            'notes' => trim($validated['formNotes']) ?: null,
        ];
        if ($this->editingContactId !== null) {
            $contact = $directory->manualInSelection($key, $this->editingContactId);
            abort_unless($contact !== null, 403);
            $contact->update($attrs);
        } else {
            $contact = CommercialContact::query()->create(array_merge($target, $attrs, [
                'created_by' => auth()->id(),
            ]));
        }
        $this->selectedPersonKey = 'manual:'.$contact->id;
        $this->peoplePage = 1;
        $this->resetForm();
        $this->formOpen = false;
        app(ContactPeopleDirectory::class)->clearCachedTotals();
        unset($this->detail, $this->directory, $this->summary, $this->contactIndex, $this->globalSummary);
        session()->flash('contacts_success', 'Contato salvo na base do Prospector. O HubSpot não foi alterado.');
    }

    public function setWhatsapp(string $type, int $id, string $field, string $status, ContactDirectory $directory): void
    {
        if (! in_array($status, ['yes', 'no', 'unknown'], true)) {
            abort(422);
        }
        $source = $directory->verifiablePhone($this->activeKey(), $type, $id, $field);
        abort_unless($source !== null, 403);
        $digits = ContactDirectory::digits($source['phone']);
        if (strlen($digits) < 8 || strlen($digits) > 20) {
            $this->addError('contact', 'O número informado não permite registrar a verificação.');

            return;
        }
        if ($status === 'unknown') {
            ContactPhoneVerification::query()
                ->where('source_key', $source['source_key'])
                ->where('phone_normalized', $digits)->delete();
        } else {
            ContactPhoneVerification::query()->updateOrCreate(
                ['source_key' => $source['source_key'], 'phone_normalized' => $digits],
                ['whatsapp_status' => $status, 'verified_by' => auth()->id(), 'verified_at' => now()],
            );
        }
        app(ContactPeopleDirectory::class)->clearCachedTotals();
        unset($this->detail, $this->contactIndex, $this->globalSummary);
        session()->flash('contacts_success', 'Verificação de WhatsApp atualizada.');
    }

    // --- Layout V3: lista unificada com filtros e inspetor lateral. ---
    public int $contactsPage = 1;

    public int $contactsPerPage = 12;

    public string $contactsSort = 'recent';

    /** @var list<string> */
    public array $checkedContactKeys = [];

    public bool $inspectorOpen = false;

    // Contadores pesados carregam depois da primeira pintura da listagem.
    public bool $totalsReady = false;

    public function loadTotals(): void
    {
        $this->totalsReady = true;
        unset($this->globalSummary);
    }

    public bool $companyChooserOpen = false;

    public string $companyChooserSearch = '';

    public function updatedContactsPerPage(): void
    {
        if (! in_array($this->contactsPerPage, [12, 25, 50], true)) {
            $this->contactsPerPage = 12;
        }
        $this->contactsPage = 1;
        $this->checkedContactKeys = [];
    }

    public function updatedContactsSort(): void
    {
        $this->contactsSort = in_array($this->contactsSort, ['recent', 'name'], true) ? $this->contactsSort : 'recent';
        $this->contactsPage = 1;
    }

    public function chooseSegment(string $value): void
    {
        if (! in_array($value, [
            'all', 'clients', 'deals', 'hubspot', 'manual', 'receita',
            'orphan', 'unlinked', 'wa_yes', 'wa_unknown',
            'with_email', 'with_phone', 'without_people',
        ], true)) {
            abort(422);
        }
        $this->segment = $value;
        $this->selectedKey = null;
        $this->selectedPersonKey = null;
        $this->contactsPage = 1;
        $this->checkedContactKeys = [];
        unset($this->contactIndex);
    }

    public function changeContactsPage(int $page): void
    {
        $this->contactsPage = max(1, min(100000, $page));
        $this->checkedContactKeys = [];
        $this->selectedPersonKey = null;
        unset($this->contactIndex);
    }

    #[Computed]
    public function contactIndex(): LengthAwarePaginator
    {
        return app(ContactPeopleDirectory::class)->paginateForWorkspace(
            $this->searchTerm,
            $this->segment,
            $this->peopleOrigin,
            $this->whatsappFilter,
            $this->selectedKey,
            $this->contactsPage,
            $this->contactsPerPage,
            $this->contactsSort,
        );
    }

    /** @return array<string, int|null> */
    #[Computed]
    public function globalSummary(): array
    {
        if (! $this->totalsReady) {
            // Placeholder, não zero: não apresenta valores falsos durante o carregamento.
            return array_fill_keys(
                ['all', 'hubspot', 'manual', 'receita', 'clients', 'deals', 'unlinked', 'wa_yes', 'wa_unknown'],
                null,
            );
        }

        return app(ContactPeopleDirectory::class)->totalsCached();
    }

    public function showGlobalPerson(string $sourceKey): void
    {
        foreach ($this->contactIndex->items() as $row) {
            if ((string) $row->source_key === $sourceKey) {
                $this->selectedPersonKey = $sourceKey;
                $this->inspectorOpen = true;

                return;
            }
        }
        abort(403);
    }

    public function toggleCurrentPage(): void
    {
        $pageKeys = array_map(
            static fn (object $row): string => (string) $row->source_key,
            $this->contactIndex->items(),
        );
        $current = array_values(array_intersect($pageKeys, $this->checkedContactKeys));
        if (count($current) === count($pageKeys)) {
            $this->checkedContactKeys = array_values(array_diff($this->checkedContactKeys, $pageKeys));
        } else {
            $this->checkedContactKeys = array_values(array_unique(array_merge($this->checkedContactKeys, $pageKeys)));
        }
    }

    public function clearChecked(): void
    {
        $this->checkedContactKeys = [];
    }

    public function startNewContactFromWorkspace(): void
    {
        $index = app(ContactPeopleDirectory::class);
        $active = collect($this->contactIndex->items())->first(
            fn (object $row): bool => (string) $row->source_key === $this->selectedPersonKey
        ) ?? ($this->contactIndex->items()[0] ?? null);
        $context = $this->selectedKey ?? ($active !== null ? $index->companyContext($active) : null);
        if ($context === null) {
            $this->companyChooserOpen = true;

            return;
        }
        if (preg_match('/^(local|hubspot):(\d+)$/D', $context, $m) !== 1) {
            $this->companyChooserOpen = true;

            return;
        }
        $this->selectCompany($m[1], (int) $m[2]);
        $this->startNewContact();
    }

    public function chooseCompanyForNewContact(string $kind, int $id): void
    {
        abort_unless(in_array($kind, ['local', 'hubspot'], true), 422);
        abort_unless(app(ContactDirectory::class)->selected($kind.':'.$id) !== null, 404);
        $this->selectCompany($kind, $id);
        $this->companyChooserOpen = false;
        $this->startNewContact();
    }

    public function editManualFromWorkspace(int $id): void
    {
        $row = collect($this->contactIndex->items())->first(
            static fn (object $p): bool => $p->person_type === 'manual' && (int) $p->person_id === $id
        );
        abort_unless($row !== null, 403);
        $context = app(ContactPeopleDirectory::class)->companyContext($row);
        abort_unless($context !== null && preg_match('/^(local|hubspot):(\d+)$/D', $context, $m) === 1, 403);
        $this->selectedKey = $context;
        $this->editManualContact($id, app(ContactDirectory::class));
    }

    /**
     * Registra a confirmação do gestor apenas para o telefone existente do contato
     * realmente exibido na página; nenhuma consulta automática é simulada aqui.
     */
    public function setWhatsappFromWorkspace(string $type, int $id, string $field, string $status): void
    {
        abort_unless(in_array($status, ['yes', 'no', 'unknown'], true), 422);
        abort_unless(in_array($field, ['phone', 'mobile_phone'], true), 422);
        abort_unless(in_array($type, ['hubspot', 'manual', 'receita'], true), 422);
        $row = collect($this->contactIndex->items())->first(
            static fn (object $p): bool => $p->person_type === $type && (int) $p->person_id === $id
        );
        abort_unless($row !== null, 403);
        $context = app(ContactPeopleDirectory::class)->companyContext($row);
        if ($context === null && $type === 'hubspot') {
            $context = 'orphan:0';
        }
        // Na Receita, phone e mobile_phone mapeiam para telefone 1 e telefone 2.
        $sourceField = $type === 'receita'
            ? ($field === 'phone' ? 'phone_1' : 'phone_2') : $field;
        $source = $context !== null
            ? app(ContactDirectory::class)->verifiablePhone($context, $type, $id, $sourceField)
            : null;
        abort_unless($source !== null, 403);
        $digits = ContactDirectory::digits($source['phone']);
        if (strlen($digits) < 8 || strlen($digits) > 20) {
            $this->addError('contact', 'Número inválido para confirmação.');

            return;
        }
        if ($status === 'unknown') {
            ContactPhoneVerification::query()
                ->where('source_key', $source['source_key'])
                ->where('phone_normalized', $digits)->delete();
        } else {
            ContactPhoneVerification::query()->updateOrCreate(
                ['source_key' => $source['source_key'], 'phone_normalized' => $digits],
                ['whatsapp_status' => $status, 'verified_by' => auth()->id(), 'verified_at' => now()],
            );
        }
        app(ContactPeopleDirectory::class)->clearCachedTotals();
        unset($this->contactIndex, $this->globalSummary, $this->detail);
        session()->flash('contacts_success', 'Confirmação de WhatsApp atualizada.');
    }

    // EC_EMAIL_CAMPAIGNS_V1_CONTACT_ACTION
    public function createCampaignFromSelected(): void
    {
        $keys = [];
        $selected = $this->checkedContactKeys;
        foreach ($this->contactIndex->items() as $row) {
            if (in_array((string) $row->source_key, $selected, true)
                && filter_var((string) $row->email, FILTER_VALIDATE_EMAIL) !== false) {
                $keys[] = (string) $row->source_key;
            }
        }
        $keys = array_values(array_unique($keys));
        if ($keys === []) {
            $this->addError('recipients', 'Selecione nesta página contatos com e-mail válido.');

            return;
        }
        $limit = max(1, (int) config('campaign_mail.max_recipients', 100));
        if (count($keys) > $limit) {
            $this->addError('recipients', 'A seleção excede o limite por campanha.');

            return;
        }
        session()->put('campaign_mail_selected', $keys);
        $this->redirect(route('campaigns.index'), navigate: true);
    }

    public function exportSelectedCsv(): StreamedResponse
    {
        $selected = array_values(array_filter($this->contactIndex->items(),
            fn (object $row): bool => in_array((string) $row->source_key, $this->checkedContactKeys, true)
        ));
        abort_if($selected === [], 422);

        return response()->streamDownload(static function () use ($selected): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Contato', 'Empresa', 'Cargo', 'E-mail', 'Telefone', 'Celular', 'WhatsApp', 'Origem'], ';', '"', '');
            foreach ($selected as $row) {
                $fields = [
                    $row->person_name, $row->company_label, $row->job_title,
                    $row->email, $row->phone, $row->mobile_phone,
                    $row->whatsapp_status, $row->person_type,
                ];
                // Proteção contra execução de fórmulas por programas de planilha.
                $safe = array_map(static function (mixed $field): string {
                    $value = (string) ($field ?? '');

                    return preg_match('/^\s*[=+@\-]/u', $value) === 1 ? "'".$value : $value;
                }, $fields);
                fputcsv($out, $safe, ';', '"', '');
            }
            fclose($out);
        }, 'contatos-exportcontrol-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
};
?>
@php
    $index = $this->contactIndex;
    $totals = $this->globalSummary;
    $rows = $index->items();
    $selected = collect($rows)->first(
        fn ($p) => (string) data_get($p, 'source_key', '') === $selectedPersonKey
    ) ?? ($rows[0] ?? null);
    $directory = app(\App\Services\Contacts\ContactPeopleDirectory::class);
    $contextKey = $inspectorOpen ? ($selectedKey ?? ($selected ? $directory->companyContext($selected) : null)) : null;
    $company = $contextKey ? (
        $contextKey === $selectedKey ? $this->detail : app(ContactDirectory::class)->selected($contextKey)
    ) : null;
    $fmt = static fn (?int $value): string => $value === null ? '…' : number_format($value, 0, ',', '.');
    $selectedKeys = array_values(array_filter(
        $checkedContactKeys,
        static fn ($key) => collect($rows)->contains(fn ($r) => $r->source_key === $key)
    ));
    $allPageSelected = $rows !== [] && count($selectedKeys) === count($rows);
    $selectedEmails = collect($rows)->filter(
        static fn ($r) => in_array($r->source_key, $selectedKeys, true) && trim((string) $r->email) !== ''
    )->pluck('email')->unique()->values()->all();
    $singleSelected = count($selectedKeys) === 1
        ? collect($rows)->first(fn ($r) => $r->source_key === $selectedKeys[0]) : null;
    $singleSelectedPhone = trim((string) ($singleSelected->mobile_phone ?? ''))
        ?: trim((string) ($singleSelected->phone ?? ''));
    $singleSelectedWhatsapp = ContactDirectory::whatsappLink($singleSelectedPhone);
    $selectedPhone = trim((string) ($selected->mobile_phone ?? '')) ?: trim((string) ($selected->phone ?? ''));
    $waLink = ContactDirectory::whatsappLink($selectedPhone);
    $telLink = ContactDirectory::dialLink($selectedPhone);
    $portalId = (string) config('services.hubspot.portal_id');
    $hubspotPersonId = (string) ($selected->external_id ?? '');
    $hubspotPersonUrl = ($selected->person_type ?? '') === 'hubspot' && ctype_digit($portalId) && ctype_digit($hubspotPersonId)
        ? 'https://app.hubspot.com/contacts/'.$portalId.'/record/0-1/'.$hubspotPersonId : null;
    $sideOptions = [
        ['all', 'Todos os contatos', $totals['all']],
        ['clients', 'Clientes', $totals['clients']],
        ['deals', 'Com negócios', $totals['deals']],
        ['unlinked', 'Sem vínculo', $totals['unlinked']],
        ['wa_yes', 'WhatsApp confirmado', $totals['wa_yes']],
        ['wa_unknown', 'Pendente de verificação', $totals['wa_unknown']],
    ];
    $originOptions = [
        ['hubspot', 'HubSpot', $totals['hubspot']],
        ['manual', 'Cadastro manual', $totals['manual']],
        ['receita', 'Receita Federal', $totals['receita']],
    ];
    $conditions = [
        ['with_email', 'Com e-mail'],
        ['with_phone', 'Com telefone'],
    ];
@endphp

<div class="ecc-page ecc3-page ecc66-page" data-test="contacts-module" data-ec-contacts-figma="C01-C05-V66" data-ec-contacts-v661="fixes" data-ec-contacts-v662="no-receita-panel-copy"
     data-test-ux="contacts-ux-v2" data-contacts-design="v3-reference"
     wire:init="loadTotals"
     x-data="{ inspectorTab: 'overview', mobileFilters: false, showHelp: false, filterKeyword: '' }"
     x-on:keydown.escape.window="mobileFilters = false"
>
    <header class="ecc3-header">
        <div class="ecc3-heading">
            <div class="ecc3-breadcrumb">CONTATOS</div>
            <span class="c66-accessible">Central de Contatos</span>
            <span class="c66-accessible">Detalhes do contato</span>
            <h1>@if ($selectedKeys !== []){{ count($selectedKeys) }} contatos selecionados @else Pessoas e canais de contato @endif</h1>
            <p>@if ($selectedKeys !== [])A seleção é limitada aos contatos da página atual. @else Encontre o contato certo sem perder a origem da informação. @endif</p>
        </div>
        <div class="ecc3-heading-actions">
            <button type="button" class="ecc3-button ecc3-button-ghost ecc3-only-small c661-filter-toggle" x-on:click="mobileFilters = !mobileFilters">☷ Filtros</button>
            <button type="button" class="ecc3-button ecc3-button-primary" wire:click="startNewContactFromWorkspace">
                <span aria-hidden="true">＋</span> Novo contato
            </button>
        </div>
    </header>

    @if (session('contacts_success'))
        <div class="ecc3-notice" role="status">{{ session('contacts_success') }}</div>
    @endif
    @error('contact')
        <div class="ecc3-notice ecc3-error" role="alert">{{ $message }}</div>
    @enderror


    {{-- FIGMA C01 – segmentos e indicadores reais, sem consultas extras. --}}
    <div class="c66-quick-segments">
            <nav class="ecc3-chips" aria-label="Segmentos rápidos">
                @foreach ([
                    ['all', 'Todos', 'all'],
                    ['clients', 'Clientes', 'clients'],
                    ['deals', 'Com negócios', 'deals'],
                    ['hubspot', 'HubSpot', 'hubspot'],
                    ['receita', 'Receita', 'receita'],
                    ['unlinked', 'Sem vínculo', 'unlinked'],
                ] as [$code, $label, $countKey])
                    <button type="button" class="ecc3-chip {{ $segment === $code ? 'is-active' : '' }}"
                            wire:click="chooseSegment('{{ $code }}')"
                            aria-pressed="{{ $segment === $code ? 'true' : 'false' }}">
                        {{ $label }}
                        @if ($countKey !== null)<b>{{ $fmt($totals[$countKey]) }}</b>@endif
                    </button>
                @endforeach
                <button type="button" class="ecc3-chip ecc3-chip-filter" x-on:click="mobileFilters = !mobileFilters">☷ Mais filtros</button>
            </nav>
    </div>
    <section class="c66-metrics" aria-label="Indicadores de contatos">
        <article class="c66-metric"><span>Contatos no diretório</span><strong>{{ $fmt($totals['all'] ?? null) }}</strong></article>
        <article class="c66-metric"><span>Com e-mail nesta página</span><strong>{{ $fmt(collect($rows)->filter(fn ($row) => filter_var(trim((string) ($row->email ?? '')), FILTER_VALIDATE_EMAIL) !== false)->count()) }}</strong><small>Registros exibidos</small></article>
        <article class="c66-metric"><span>WhatsApp confirmado</span><strong>{{ $fmt($totals['wa_yes'] ?? null) }}</strong></article>
        <article class="c66-metric"><span>Pendentes de verificação</span><strong>{{ $fmt($totals['wa_unknown'] ?? null) }}</strong><small>Estado de verificação cadastrado</small></article>
    </section>
    @if ($selectedKeys !== [])
        <div class="c66-selected-actions" role="status">
            <strong>{{ count($selectedKeys) }} selecionado(s) nesta página</strong>
            <span>Ao mudar de página, sua seleção será limpa. Criar campanha abre o fluxo de rascunho; não envia mensagens.</span>
            <div class="c66-selected-buttons">
                <button type="button" class="ecc3-button" wire:click="clearChecked">Limpar seleção</button>
                <button type="button" class="ecc3-button" wire:click="exportSelectedCsv">Exportar seleção CSV</button>
                <button type="button" class="ecc3-button ecc3-button-primary" wire:click="createCampaignFromSelected" @disabled($selectedEmails === [])>Criar campanha</button>
            </div>
        </div>
    @endif

    <div class="ecc3-shell {{ $inspectorOpen ? '' : 'is-inspector-collapsed' }}">
        {{-- Coluna 1: filtros agrupados, como na referência. --}}
        <aside class="ecc3-filters" aria-label="Filtros da Central de Contatos" :class="mobileFilters ? 'is-open' : ''" x-on:click.outside="if (!$event.target.closest('.c661-filter-toggle, .ecc3-chip-filter')) mobileFilters = false">
            <header class="ecc3-filters-head">
                <h2>Filtros</h2>
                <button type="button" wire:click="clearFilters" x-on:click="mobileFilters=false; filterKeyword=''">Limpar tudo</button>
            </header>
            <div class="ecc3-filter-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
                <input type="search" x-model="filterKeyword"
                       placeholder="Buscar nos filtros..." aria-label="Buscar tipos de filtro">
            </div>
            <div class="ecc3-filter-scroll">
                <div class="ecv75-filter-column">
    <details class="ecc3-filter-group" open>
                        <summary>Segmentos <span aria-hidden="true">⌃</span></summary>
                        <div class="ecc3-filter-list">
                            @foreach ($sideOptions as [$code, $label, $total])
                                <button type="button" class="ecc3-filter-option {{ $segment === $code ? 'is-active' : '' }}"
                                        data-filter-label="{{ mb_strtolower($label) }}"
                                        x-show="filterKeyword.trim() === '' || $el.dataset.filterLabel.includes(filterKeyword.trim().toLocaleLowerCase('pt-BR'))"
                                        wire:click="chooseSegment('{{ $code }}')"
                                        aria-pressed="{{ $segment === $code ? 'true' : 'false' }}">
                                    <span class="ecc3-fake-check" aria-hidden="true">{{ $segment === $code ? '✓' : '' }}</span>
                                    <span>{{ $label }}</span><strong>{{ $fmt($total) }}</strong>
                                </button>
                            @endforeach
                        </div>
                    </details>
    <details class="ecc3-filter-group" open>
                        <summary>Situação <span aria-hidden="true">⌃</span></summary>
                        <div class="ecc3-filter-list">
                            @foreach ($conditions as [$code, $label])
                                <button type="button" class="ecc3-filter-option {{ $segment === $code ? 'is-active' : '' }}"
                                        data-filter-label="{{ mb_strtolower($label) }}"
                                        x-show="filterKeyword.trim() === '' || $el.dataset.filterLabel.includes(filterKeyword.trim().toLocaleLowerCase('pt-BR'))"
                                        wire:click="chooseSegment('{{ $code }}')"
                                        aria-pressed="{{ $segment === $code ? 'true' : 'false' }}">
                                    <span class="ecc3-fake-check" aria-hidden="true">{{ $segment === $code ? '✓' : '' }}</span>
                                    <span>{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>
                    </details>
    <details class="ecc3-filter-group">
                        <summary>Fonte de contato <span aria-hidden="true">⌄</span></summary>
                        <div class="ecc3-filter-list">
                            @foreach (['all' => 'Todas as origens', 'hubspot' => 'Somente HubSpot', 'manual' => 'Somente manual', 'receita' => 'Somente Receita Federal'] as $code => $label)
                                <button type="button" class="ecc3-filter-option {{ $peopleOrigin === $code ? 'is-active' : '' }}"
                                        wire:click="$set('peopleOrigin','{{ $code }}')">
                                    <span class="ecc3-fake-check" aria-hidden="true">{{ $peopleOrigin === $code ? '✓' : '' }}</span><span>{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>
                    </details>
                </div>
                <div class="ecv75-filter-column">
    <details class="ecc3-filter-group" open>
                        <summary>Origem e cadastro <span aria-hidden="true">⌃</span></summary>
                        <div class="ecc3-filter-list">
                            @foreach ($originOptions as [$code, $label, $total])
                                <button type="button" class="ecc3-filter-option {{ $segment === $code ? 'is-active' : '' }}"
                                        data-filter-label="{{ mb_strtolower($label) }}"
                                        x-show="filterKeyword.trim() === '' || $el.dataset.filterLabel.includes(filterKeyword.trim().toLocaleLowerCase('pt-BR'))"
                                        wire:click="chooseSegment('{{ $code }}')"
                                        aria-pressed="{{ $segment === $code ? 'true' : 'false' }}">
                                    <span class="ecc3-fake-check" aria-hidden="true">{{ $segment === $code ? '✓' : '' }}</span>
                                    <span>{{ $label }}</span>
                                    @if ($total !== null)<strong>{{ $fmt($total) }}</strong>@endif
                                </button>
                            @endforeach
                        </div>
                    </details>
    <details class="ecc3-filter-group">
                        <summary>Confirmação de WhatsApp <span aria-hidden="true">⌄</span></summary>
                        <div class="ecc3-filter-list">
                            @foreach (['all' => 'Todos os estados', 'yes' => 'Confirmado', 'no' => 'Não possui', 'unknown' => 'Não verificado'] as $code => $label)
                                <button type="button" class="ecc3-filter-option {{ $whatsappFilter === $code ? 'is-active' : '' }}"
                                        wire:click="$set('whatsappFilter','{{ $code }}')">
                                    <span class="ecc3-fake-check" aria-hidden="true">{{ $whatsappFilter === $code ? '✓' : '' }}</span><span>{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>
                    </details>
                </div>
                <div class="ecc3-filters-foot">
                    <span>Dados locais do HubSpot e do Prospector</span>
                    <small>Os filtros respeitam as origens e os vínculos registrados na base do Prospector.</small>
                </div>
            </div>
        </aside>

        {{-- Coluna 2: tabela central de todas as pessoas, não apenas de uma empresa. --}}
        <section class="ecc3-center" aria-label="Contatos encontrados">

            {{-- FIGMA C01 – filtros existentes apresentados em duas linhas. --}}
            <section class="c66-refine" aria-labelledby="c66-refine-title">
                <h2 id="c66-refine-title">Pesquisar e refinar</h2>
                <div class="c66-refine-grid">
                    <label><span>Contato, empresa, cargo, CNPJ ou e-mail</span>
                        <input type="search" wire:model.live.debounce.350ms="searchTerm" placeholder="Buscar contato, empresa ou CNPJ..." autocomplete="off">
                    </label>
                    <label><span>Fonte</span>
                        <select wire:model.live="peopleOrigin" aria-label="Filtrar por origem">
                            <option value="all">Todas</option><option value="hubspot">HubSpot</option>
                            <option value="manual">Manual</option><option value="receita">Receita Federal</option>
                        </select>
                    </label>
                    <label><span>Situação</span>
                        <select wire:model.live="segment" aria-label="Filtrar situação dos contatos">
                            <option value="all">Todas</option><option value="with_email">Com e-mail</option>
                            <option value="with_phone">Com telefone</option><option value="unlinked">Sem vínculo</option>
                            <option value="clients">Clientes</option><option value="deals">Com negócios</option>
                        </select>
                    </label>
                    <label><span>WhatsApp</span>
                        <select wire:model.live="whatsappFilter" aria-label="Filtrar WhatsApp">
                            <option value="all">Todos</option><option value="yes">Confirmado</option>
                            <option value="no">Não possui</option><option value="unknown">Não verificado</option>
                        </select>
                    </label>
                    <label><span>Ordenação</span>
                        <select wire:model.live="contactsSort" aria-label="Ordenar contatos">
                            <option value="recent">Mais recentes</option><option value="name">Nome A–Z</option>
                        </select>
                    </label>
                    <label><span>Por página</span>
                        <select wire:model.live="contactsPerPage" aria-label="Contatos por página">
                            <option value="12">12</option><option value="25">25</option><option value="50">50</option>
                        </select>
                    </label>
                </div>
            </section>

            <div class="ecc3-table-panel">
                <div class="ecc3-list-header">
                    <div class="ecc3-list-title">
                        <strong>Diretório de contatos</strong>
                        <span>{{ $fmt($index->total()) }} contatos · {{ $selectedKey ? 'Vinculados à empresa selecionada' : 'Encontrados com os filtros atuais' }}</span>
                        @if ($selectedKey)
                            <button type="button" class="ecc3-text-link" wire:click="$set('selectedKey', null)">× Ver todos</button>
                        @endif
                    </div>
                    <div class="ecc3-controls">
                        <label for="ecc3-order">Ordenar:</label>
                        <select id="ecc3-order" wire:model.live="contactsSort">
                            <option value="recent">Mais recentes</option>
                            <option value="name">Nome A–Z</option>
                        </select>
                        @if (!$inspectorOpen)
                            <button type="button" class="ecc3-button ecc3-button-mini" wire:click="$set('inspectorOpen', true)">Abrir detalhes</button>
                        @endif
                    </div>
                </div>
                <div class="ecc3-bulk {{ $selectedKeys !== [] ? 'has-selection' : '' }}">
                    <button type="button" class="ecc3-bulk-check {{ $allPageSelected ? 'is-active' : '' }}"
                            wire:click="toggleCurrentPage" aria-label="Selecionar todos os contatos desta página"
                            title="Selecionar a página">{{ $allPageSelected ? '☑' : '□' }}</button>
                    <span><strong>{{ count($selectedKeys) }}</strong> selecionado(s)</span>
                    @if ($selectedEmails !== [])
                        <button type="button" class="ecc3-button ecc3-button-mini" wire:click="createCampaignFromSelected"
                            title="Criar campanha no Prospector com os contatos selecionados">✉ Criar campanha</button>
                    @else
                        <button type="button" class="ecc3-button ecc3-button-mini" disabled title="Selecione contatos com e-mail">✉ Criar campanha</button>
                    @endif
                    @if ($singleSelectedWhatsapp)
                        <a href="{{ $singleSelectedWhatsapp }}" target="_blank" rel="noopener noreferrer"
                           class="ecc3-button ecc3-button-mini c661-bulk-whatsapp" title="Abre a conversa; não envia automaticamente">
                            @include('partials.contacts.integration-icon-v661', ['service' => 'whatsapp']) <span>Abrir WhatsApp</span>
                        </a>
                    @else
                        <button type="button" class="ecc3-button ecc3-button-mini" disabled
                                title="Selecione um contato com telefone. Envio em lote não integrado.">◉ Enviar WhatsApp</button>
                    @endif
                    <button type="button" class="ecc3-button ecc3-button-mini" disabled
                            title="A associação em lote a negócios ainda não está integrada">▣ Adicionar a negócio</button>
                    <details class="ecc3-more-actions">
                        <summary class="ecc3-button ecc3-button-mini">Mais ações ⌄</summary>
                        <div class="ecc3-more-menu">
                            <button type="button" wire:click="exportSelectedCsv" @disabled($selectedKeys === [])>↓ Exportar seleção CSV</button>
                            <button type="button" wire:click="clearChecked" @disabled($selectedKeys === [])>× Limpar seleção</button>
                        </div>
                    </details>
                </div>

                <div class="ecc3-table-scroll" wire:loading.class="is-loading" wire:target="searchTerm,segment,contactsPage,peopleOrigin,whatsappFilter">
                    <table class="ecc3-table">
                        <thead><tr>
                            <th scope="col" aria-label="Selecionar contatos da página">
                                <button type="button" class="c661-select-all" wire:click="toggleCurrentPage"
                                    wire:loading.attr="disabled" wire:target="toggleCurrentPage"
                                    aria-label="{{ $allPageSelected ? 'Desmarcar todos os contatos desta página' : 'Selecionar todos os contatos desta página' }}"
                                    aria-pressed="{{ $allPageSelected ? 'true' : 'false' }}"
                                    title="{{ $allPageSelected ? 'Desmarcar página' : 'Selecionar página' }}">
                                    <span aria-hidden="true">{{ $allPageSelected ? '✓' : '' }}</span>
                                </button>
                            </th>
                            <th>Contato</th>
                            <th>Empresa / cargo</th>
                            <th>Cargo</th>
                            <th>Telefone / WhatsApp</th>
                            <th>WhatsApp</th>
                            <th>Origem</th>
                            <th>Negócios</th>
                            <th>Última atividade</th>
                            <th>Ações</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($rows as $row)
                            @php
                                $key = (string) $row->source_key;
                                $shortName = trim((string) $row->person_name) ?: 'Contato sem nome';
                                $abbr = implode('', array_map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)), array_slice(preg_split('/\s+/u', $shortName) ?: [], 0, 2)));
                                $phone = trim((string) $row->mobile_phone) ?: trim((string) $row->phone);
                                $phoneLink = ContactDirectory::dialLink($phone);
                                $wa = (string) $row->whatsapp_status;
                                $active = $selected && $selected->source_key === $key;
                            @endphp
                            <tr wire:key="ecc3-row-{{ $key }}" class="{{ $active ? 'is-selected' : '' }}">
                                <td>
                                    <input type="checkbox" wire:model.live="checkedContactKeys" value="{{ $key }}"
                                           aria-label="Selecionar {{ $shortName }}">
                                </td>
                                <td>
                                    <button type="button" class="ecc3-person-cell" wire:click="showGlobalPerson(@js($key))"
                                            aria-label="Abrir detalhes de {{ $shortName }}">
                                        <span class="ecc3-avatar">{{ $abbr ?: '?' }}</span>
                                        <span class="ecc3-person-words"><strong title="{{ $shortName }}">{{ $shortName }}</strong>
                                            <small title="{{ $row->email }}">{{ $row->email ?: 'E-mail não informado' }}</small>
                                        </span>
                                    </button>
                                </td>
                                <td><span class="ecc3-company-name" title="{{ $row->company_label }}">{{ $row->company_label }}</span>
                                    <small class="c66-company-role">{{ $row->job_title ?: 'Cargo não informado' }}</small></td>
                                <td><span class="ecc3-clamp" title="{{ $row->job_title }}">{{ $row->job_title ?: 'Não informado' }}</span></td>
                                <td>
                                    @if ($phoneLink)
                                        <a href="{{ $phoneLink }}" class="ecc3-tel">{{ $phone }}</a>
                                    @else
                                        <span class="ecc3-muted">{{ $phone ?: '—' }}</span>
                                    @endif
                                    <small class="c66-phone-status">{{ match ($wa) { 'yes' => 'WhatsApp confirmado', 'no' => 'Sem WhatsApp', 'none' => 'Sem número', default => 'Não verificado' } }}</small>
                                </td>
                                <td>
                                    <span class="ecc3-wa ecc3-wa-{{ $wa }}">
                                        <span aria-hidden="true">{{ match ($wa) { 'yes' => '●', 'no' => '×', 'none' => '—', default => '◷' } }}</span>
                                        {{ match ($wa) { 'yes' => 'Confirmado', 'no' => 'Não possui', 'none' => 'Sem número', default => 'Pendente' } }}
                                    </span>
                                </td>
                                <td><span class="ecc3-source ecc3-source-{{ $row->person_type }}">{{ match ($row->person_type) { 'hubspot' => 'HubSpot', 'receita' => 'Receita', default => 'Manual' } }}</span></td>
                                <td class="ecc3-deals-cell">{{ (int) $row->deals_count }}</td>
                                <td class="ecc3-date">
                                    {{ $row->last_activity_at ? \Carbon\Carbon::parse($row->last_activity_at)->timezone('America/Sao_Paulo')->format('d/m/Y') : '—' }}
                                </td>
                                <td><button type="button" class="ecc3-detail-button"
                                            wire:click="showGlobalPerson(@js($key))">Abrir detalhes ›</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="ecc3-no-results">
                                <strong>Nenhum contato encontrado</strong><p>Altere os filtros ou tente pesquisar outra empresa, nome ou telefone.</p>
                                <button type="button" wire:click="clearFilters">Limpar filtros</button>
                            </td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="ecc3-pagination">
                    <span>Exibindo {{ $index->firstItem() ?? 0 }}–{{ $index->lastItem() ?? 0 }} de {{ $fmt($index->total()) }} contatos</span>
                    <div class="ecc3-pagination-actions">
                        <button type="button" wire:click="changeContactsPage({{ max(1, $index->currentPage() - 1) }})"
                                @disabled($index->onFirstPage()) aria-label="Página anterior">‹</button>
                        <strong>{{ $index->currentPage() }}</strong>
                        <span>/ {{ $index->lastPage() }}</span>
                        <button type="button" wire:click="changeContactsPage({{ $index->currentPage() + 1 }})"
                                @disabled(! $index->hasMorePages()) aria-label="Próxima página">›</button>
                    </div>
                    <label>Por página
                        <select wire:model.live="contactsPerPage">
                            <option value="12">12</option><option value="25">25</option><option value="50">50</option>
                        </select>
                    </label>
                </footer>
            </div>
        </section>

        {{-- Coluna 3: inspetor sempre visível, seleção com dados reais. --}}
        @if ($inspectorOpen)
            <div class="c66-inspector-backdrop" wire:click="$set('inspectorOpen', false)" aria-hidden="true"></div>
            <aside class="ecc3-inspector" aria-label="Detalhes do contato">
                <div class="ecc3-inspector-title">
                    <h2>Detalhes do contato</h2>
                    <button type="button" wire:click="$set('inspectorOpen', false)" aria-label="Fechar detalhes">×</button>
                </div>
                @if ($selected)
                    @php
                        $abbr = mb_strtoupper(mb_substr(trim((string) $selected->person_name), 0, 1));
                        $primaryStatus = (string) $selected->whatsapp_status;
                    @endphp
                    <section class="ecc3-profile-card">
                        <div class="ecc3-profile-row">
                            <span class="ecc3-avatar ecc3-avatar-large">{{ $abbr ?: '?' }}</span>
                            <div class="ecc3-profile-name">
                                <h3>{{ $selected->person_name }}</h3>
                                <p>{{ $selected->job_title ?: 'Cargo não informado' }}</p>
                                <small>{{ $selected->company_label }}</small>
                            </div>
                            @if ((int) $selected->is_client === 1)<span class="ecc3-client-chip">Cliente</span>@endif
                        </div>
                        <div class="ecc3-profile-buttons">
                            @if ($telLink)<a class="ecc3-button ecc3-button-primary" href="{{ $telLink }}">☎ Ligar</a>@else<button class="ecc3-button" disabled>☎ Ligar</button>@endif
                            @if ($selected->email)<a class="ecc3-button" href="mailto:{{ $selected->email }}">✉ E-mail</a>@else<button class="ecc3-button" disabled>✉ E-mail</button>@endif
                            @if ($waLink)<a href="{{ $waLink }}" target="_blank" rel="noopener noreferrer" class="ecc3-button c661-whatsapp-link" title="Abrir WhatsApp; isso não confirma o cadastro">
                                @include('partials.contacts.integration-icon-v661', ['service' => 'whatsapp']) <span>WhatsApp</span>
                            </a>@endif
                            @if ($hubspotPersonUrl)<a class="ecc3-button ecc3-button-icon c661-hubspot-link" href="{{ $hubspotPersonUrl }}" target="_blank" rel="noopener noreferrer" title="Abrir contato no HubSpot" aria-label="Abrir contato no HubSpot">
                                @include('partials.contacts.integration-icon-v661', ['service' => 'hubspot']) <span>HubSpot</span>
                            </a>@endif
                        </div>
                    </section>
                    <nav class="ecc3-inspector-tabs" aria-label="Abas do contato">
                        <button type="button" x-on:click="inspectorTab='overview'" :class="inspectorTab==='overview'?'is-active':''">Visão geral</button>
                        <button type="button" x-on:click="inspectorTab='company'" :class="inspectorTab==='company'?'is-active':''">Empresa</button>
                        <button type="button" x-on:click="inspectorTab='activity'" :class="inspectorTab==='activity'?'is-active':''">Atividades</button>
                        <button type="button" x-on:click="inspectorTab='deals'" :class="inspectorTab==='deals'?'is-active':''">Negócios</button>
                    </nav>
                    <div class="ecc3-inspector-scroll">
                        <div x-show="inspectorTab==='overview'">
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Informações do contato</h3>
                                    @if ($selected->person_type === 'manual')
                                        <button type="button" wire:click="editManualFromWorkspace({{ $selected->person_id }})">✎ Editar</button>
                                    @endif
                                </div>
                                <div class="ecc3-info-line"><span>✉</span><div><small>E-mail</small>
                                    @if ($selected->email)<a href="mailto:{{ $selected->email }}">{{ $selected->email }}</a>@else<em>Não informado</em>@endif
                                </div></div>
                                @foreach (['phone' => 'Telefone', 'mobile_phone' => ($selected->person_type === 'receita' ? 'Telefone 2' : 'Celular')] as $field => $label)
                                    @php
                                        $val = trim((string) $selected->{$field});
                                        $st = $selected->phone_statuses[$field] ?? 'unknown';
                                        $dial = ContactDirectory::dialLink($val);
                                    @endphp
                                    @if ($val !== '')
                                        <div class="ecc3-info-line"><span>☎</span><div>
                                            <small>{{ $label }}</small>
                                            @if ($dial)<a href="{{ $dial }}">{{ $val }}</a>@else<strong>{{ $val }}</strong>@endif
                                            <span class="ecc3-substatus ecc3-substatus-{{ $st }}">
                                                {{ match ($st) {'yes' => 'WhatsApp confirmado', 'no' => 'Confirmado: não possui WhatsApp', default => 'WhatsApp não verificado'} }}
                                            </span>
                                            <div class="ecc3-verify-buttons" aria-label="Confirmar WhatsApp do número">
                                                <button type="button" wire:click="setWhatsappFromWorkspace('{{ $selected->person_type }}', {{ $selected->person_id }}, '{{ $field }}', 'yes')">Tem Whats</button>
                                                <button type="button" wire:click="setWhatsappFromWorkspace('{{ $selected->person_type }}', {{ $selected->person_id }}, '{{ $field }}', 'no')">Não tem</button>
                                                @if ($st !== 'unknown')<button type="button" wire:click="setWhatsappFromWorkspace('{{ $selected->person_type }}', {{ $selected->person_id }}, '{{ $field }}', 'unknown')">Limpar</button>@endif
                                            </div>
                                        </div></div>
                                    @endif
                                @endforeach
                                @if ($selectedPhone === '')
                                    <div class="ecc3-info-line"><span>☎</span><div><small>Telefone</small><em>Não informado</em></div></div>
                                @endif
                                <div class="ecc3-info-line"><span>♧</span><div><small>Cargo</small><strong>{{ $selected->job_title ?: 'Não informado' }}</strong></div></div>
                                @if ($selected->notes)
                                    <div class="ecc3-notes"><strong>Observações</strong><p>{{ $selected->notes }}</p></div>
                                @endif
                            </section>
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Empresa vinculada</h3></div>
                                <strong class="ecc3-company-strong">{{ $selected->company_label }}</strong>
                                @if ($company && $company['cnpj'])<p>CNPJ raiz: {{ $company['cnpj'] }}</p>@endif
                                @if ($company && $company['companyUuid'])
                                    <a href="{{ route('companies.show', ['company' => $company['companyUuid']]) }}" wire:navigate>Abrir dossiê ↗</a>
                                @elseif ($contextKey)
                                    <p>Vínculo fiscal não confirmado. Consulta comercial no HubSpot.</p>
                                @else
                                    <p>Nenhuma empresa vinculada com segurança.</p>
                                @endif
                            </section>
                            {{-- CONTACTS_RECEITA_V4_PREVIEW: so empresa com vinculo fiscal confiavel --}}
                            @if ($company && !empty($company['receipt']) && $company['companyUuid'])
                                <section class="ecc3-info-card c661-receita-card">
                                    <div class="ecc3-card-title"><h3>Canais cadastrais da Receita</h3></div>
                                    <p>E-mails e telefones dos estabelecimentos da empresa (nao sao contatos pessoais).</p>
                                    @foreach (array_slice($company['receipt'], 0, 3) as $est)
                                        <div class="ecc3-receipt">
                                            <strong>{{ $est['kind'] === 'matrix' ? 'Matriz' : 'Filial' }} — {{ $est['cnpj'] }}</strong>
                                            @if ($est['email'])<a href="mailto:{{ trim($est['email']) }}">{{ $est['email'] }}</a>@endif
                                            @if ($est['phone_1'])<span>☎ {{ $est['phone_1'] }}</span>@endif
                                            @if ($est['phone_2'])<span>☎ {{ $est['phone_2'] }}</span>@endif
                                        </div>
                                    @endforeach
                                    <a href="{{ route('contacts.receita', ['q' => $company['name']]) }}" wire:navigate>Consultar matriz e todas as filiais ↗</a>
                                </section>
                            @endif
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Origem e dados</h3></div>
                                <div class="ecc3-source-line"><span class="ecc3-source ecc3-source-{{ $selected->person_type }}">{{ match ($selected->person_type) { 'hubspot' => 'HubSpot', 'receita' => 'Receita', default => 'Manual' } }}</span>
                                    <small>{{ match ($selected->person_type) { 'hubspot' => 'Espelho local do CRM', 'receita' => 'Dados cadastrais do estabelecimento', default => 'Cadastro no Prospector' } }}</small></div>
                                @if ($selected->person_type === 'receita')
                                    <p>Canal institucional da Receita Federal; não representa uma pessoa ou um telefone validado para WhatsApp.</p>
                                    <p>CNPJ do estabelecimento: <strong>{{ $selected->external_id }}</strong></p>
                                @else
                                    <p>Dados da Receita também aparecem na listagem, identificados como canais da empresa.</p>
                                @endif
                            </section>
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Última atividade</h3></div>
                                @if ($selected->last_activity_at)
                                    <p>{{ match ($selected->person_type) { 'hubspot' => 'Última atividade registrada no HubSpot', 'receita' => 'Atualização da fonte cadastral', default => 'Última atualização do cadastro manual' } }}</p>
                                    <strong>{{ \Carbon\Carbon::parse($selected->last_activity_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</strong>
                                @else
                                    <p>Não há data de atividade no espelho local.</p>
                                @endif
                            </section>
                        </div>
                        <div x-show="inspectorTab==='company'" x-cloak>
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Empresa</h3></div>
                                <strong class="ecc3-company-strong">{{ $company['name'] ?? $selected->company_label }}</strong>
                                <p>{{ $company['status'] ?? 'Situação comercial não informada' }}</p>
                                @if ($company && $company['companyUuid'])<a href="{{ route('companies.show', ['company' => $company['companyUuid']]) }}" wire:navigate>Abrir dossiê ↗</a>@endif
                            </section>
                            <section class="ecc3-info-card c661-receita-card">
                                <div class="ecc3-card-title"><h3>Canais cadastrais da Receita</h3></div>
                                <p>Dados do estabelecimento, separados de contatos pessoais. Somente quando o vínculo fiscal foi confirmado.</p>
                                @forelse (($company['receipt'] ?? []) as $est)
                                    <div class="ecc3-receipt">
                                        <strong>{{ $est['cnpj'] ?: 'Estabelecimento cadastrado' }}</strong>
                                        <small>{{ $est['city'] }}{{ $est['uf'] ? ' / '.$est['uf'] : '' }}</small>
                                        @if ($est['email'])<a href="mailto:{{ $est['email'] }}">{{ $est['email'] }}</a>@endif
                                        @if ($est['phone_1'])<span>Telefone: {{ $est['phone_1'] }}</span>@endif
                                        @if ($est['phone_2'])<span>Outro: {{ $est['phone_2'] }}</span>@endif
                                    </div>
                                @empty
                                    <p>Não há canais da Receita associados a esta empresa.</p>
                                @endforelse
                            </section>
                        </div>
                        <div x-show="inspectorTab==='activity'" x-cloak>
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Atividades</h3></div>
                                <p>Esta versão consulta as datas registradas no espelho HubSpot. Ainda não reproduz um histórico completo de reuniões, e-mails ou mensagens.</p>
                                @if ($selected->last_activity_at)
                                    <div class="ecc3-receipt"><strong>Última atualização de atividade</strong><span>{{ \Carbon\Carbon::parse($selected->last_activity_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span></div>
                                @endif
                                @if ($hubspotPersonUrl)<a href="{{ $hubspotPersonUrl }}" target="_blank" rel="noopener noreferrer">Consultar histórico no HubSpot ↗</a>@endif
                            </section>
                        </div>
                        <div x-show="inspectorTab==='deals'" x-cloak>
                            <section class="ecc3-info-card">
                                <div class="ecc3-card-title"><h3>Negócios vinculados</h3></div>
                                <p>{{ (int) $selected->deals_count }} negócio(s) associado(s) à pessoa ou empresa no espelho comercial.</p>
                                @forelse (($company['deals'] ?? []) as $deal)
                                    <div class="ecc3-receipt"><strong>{{ $deal->name }}</strong><small>{{ $deal->stage_label ?: 'Etapa não informada' }}</small></div>
                                @empty
                                    <p>Nenhum negócio relacionado encontrado na empresa selecionada.</p>
                                @endforelse
                            </section>
                        </div>
                    </div>
                @elseif ($company)
                    <div class="ecc3-inspector-scroll">
                        <section class="ecc3-info-card"><h3>Empresa selecionada</h3><strong>{{ $company['name'] }}</strong><p>Não há contato pessoal com os filtros atuais.</p></section>
                        <section class="ecc3-info-card c661-receita-card"><h3>Canais cadastrais da Receita</h3>
                            @forelse ($company['receipt'] as $est)
                                <div class="ecc3-receipt"><strong>{{ $est['cnpj'] }}</strong>
                                    @if ($est['email'])<a href="mailto:{{ $est['email'] }}">{{ $est['email'] }}</a>@endif
                                    @if ($est['phone_1'])<span>{{ $est['phone_1'] }}</span>@endif
                                </div>
                                            @empty
                                <p>Sem dados cadastrais de contato.</p>
                            @endforelse
                        </section>
                    </div>
                @else
                    <div class="ecc3-empty-inspector"><span>◉</span><h3>Selecione um contato</h3><p>Escolha uma pessoa na tabela para consultar telefone, e-mail, empresa e origem.</p></div>
                @endif
            </aside>
        @endif
    </div>

    {{-- Escolha explícita de empresa para criar um contato manual sem associação presumida. --}}
    @if ($companyChooserOpen)
        <div class="ecc3-modal-overlay" role="presentation">
            <section class="ecc3-modal" role="dialog" aria-modal="true" aria-labelledby="ecc3-pick-title">
                <header><div><small>EXPORTCONTROL PROSPECTOR</small><h2 id="ecc3-pick-title">Selecionar empresa</h2>
                    <p>Escolha em qual empresa o contato manual será cadastrado.</p></div>
                    <button type="button" wire:click="$set('companyChooserOpen', false)" aria-label="Fechar">×</button></header>
                <div class="ecc3-pick-search"><input type="search" wire:model.live.debounce.300ms="companyChooserSearch"
                     placeholder="Buscar empresa por nome ou CNPJ..."></div>
                <div class="ecc3-choose-list">
                    @foreach (app(ContactDirectory::class)->companies($companyChooserSearch, 'all', 1) as $pick)
                        <button type="button" wire:key="pick-{{ $pick->directory_type }}-{{ $pick->record_id }}"
                          wire:click="chooseCompanyForNewContact('{{ $pick->directory_type }}', {{ $pick->record_id }})">
                            <strong>{{ $pick->company_name }}</strong><small>{{ $pick->cnpj_root ?: 'Empresa do HubSpot – sem vínculo fiscal confirmado' }}</small>
                        </button>
                    @endforeach
                </div>
                <footer><button type="button" class="ecc3-button" wire:click="$set('companyChooserOpen', false)">Cancelar</button></footer>
            </section>
        </div>
    @endif

    {{-- Mesmo formulário e as mesmas validações do módulo original. --}}
    @if ($formOpen)
        <div class="ecc-modal-mask" role="presentation">
            <section class="ecc-modal" role="dialog" aria-modal="true" aria-labelledby="ecc-form-title">
                <header><div><span class="ecc-eyebrow">BASE DO PROSPECTOR</span>
                    <h2 id="ecc-form-title">{{ $editingContactId ? 'Editar contato manual' : 'Novo contato manual' }}</h2>
                    <p>Os dados serão salvos no Prospector, sem alterar o espelho do HubSpot.</p></div>
                    <button type="button" wire:click="cancelForm" aria-label="Fechar">×</button></header>
                <form wire:submit="saveContact">
                    <label>Nome completo <input type="text" wire:model="formName" maxlength="180" required>@error('formName')<small class="ecc-error">{{ $message }}</small>@enderror</label>
                    <label>Cargo <input type="text" wire:model="formJob" maxlength="180"></label>
                    <label>E-mail <input type="email" wire:model="formEmail" maxlength="255">@error('formEmail')<small class="ecc-error">{{ $message }}</small>@enderror</label>
                    <div class="ecc-form-grid"><label>Telefone <input type="tel" wire:model="formPhone" maxlength="80"></label>
                        <label>Celular <input type="tel" wire:model="formMobile" maxlength="80"></label></div>
                    <label>Observações <textarea wire:model="formNotes" maxlength="3000" rows="4"></textarea></label>
                    <footer><button type="button" class="ecc3-button" wire:click="cancelForm">Cancelar</button>
                        <button type="submit" class="ecc3-button ecc3-button-primary" wire:loading.attr="disabled" wire:target="saveContact">Salvar contato</button></footer>
                </form>
            </section>
        </div>
    @endif
    <p class="ecc3-disclaimer">Somente e-mails válidos e não bloqueados são elegíveis para rascunhos de campanhas. O uso de WhatsApp continua exigindo confirmação manual.</p>
</div>
