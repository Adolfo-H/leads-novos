<?php

use App\Models\CommercialContact;
use App\Models\ContactPhoneVerification;
use App\Models\HubSpotImportRun;
use App\Services\Contacts\ContactDirectory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

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

    public function updatedSearchTerm(): void
    {
        $this->resetDirectory();
    }

    public function updatedSegment(): void
    {
        if (! in_array($this->segment, ['all', 'clients', 'deals', 'without_people', 'hubspot', 'receita', 'orphan'], true)) {
            $this->segment = 'all';
        }
        $this->resetDirectory();
    }

    public function updatedPeopleSearch(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
    }

    public function updatedPeopleOrigin(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
    }

    public function updatedWhatsappFilter(): void
    {
        $this->peoplePage = 1;
        $this->selectedPersonKey = null;
    }

    private function resetDirectory(): void
    {
        $this->companyPage = 1;
        $this->peoplePage = 1;
        $this->selectedKey = null;
        $this->selectedPersonKey = null;
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
    public function directory(): \Illuminate\Pagination\LengthAwarePaginator
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
        $this->peoplePage = 1;
        $this->peopleSearch = '';
        $this->selectedPersonKey = null;
        $this->formOpen = false;
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
        unset($this->detail, $this->directory, $this->summary);
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
        unset($this->detail);
        session()->flash('contacts_success', 'Verificação de WhatsApp atualizada.');
    }
};
?>

@php
    $directory = $this->directory;
    $detail = $this->detail;
    $summary = $this->summary;
    $activeKey = $this->activeKey();
    $people = $detail['people'] ?? null;
    $peopleRows = $people?->items() ?? [];
    $person = collect($peopleRows)->first(
        fn ($entry) => (string) data_get($entry, 'source_key', '') === $selectedPersonKey
    ) ?? ($peopleRows[0] ?? null);
    $rootLink = $detail['companyUuid'] ?? null;
    $hubspotPortal = (string) config('services.hubspot.portal_id');
    $companyHubspotId = (string) ($detail['mirrorExternalIds'][0] ?? '');
    $companyHubspotUrl = ctype_digit($hubspotPortal) && ctype_digit($companyHubspotId)
        ? 'https://app.hubspot.com/contacts/'.$hubspotPortal.'/record/0-2/'.$companyHubspotId
        : null;
    $number = static fn ($value): string => number_format((int) $value, 0, ',', '.');
    $shownWithEmail = collect($peopleRows)->filter(
        static fn ($item): bool => trim((string) data_get($item, 'email', '')) !== ''
    )->count();
    $shownWithPhone = collect($peopleRows)->filter(
        static fn ($item): bool => trim((string) data_get($item, 'phone', '')) !== ''
            || trim((string) data_get($item, 'mobile_phone', '')) !== ''
    )->count();
    $shownWithWhatsapp = collect($peopleRows)->filter(function ($item) use ($detail): bool {
        $key = (string) data_get($item, 'source_key', '');
        foreach (['phone', 'mobile_phone'] as $field) {
            $digits = \App\Services\Contacts\ContactDirectory::digits((string) data_get($item, $field, ''));
            if ($digits !== '' && data_get($detail, 'verifications.'.$key.'|'.$digits) === 'yes') {
                return true;
            }
        }
        return false;
    })->count();
@endphp

<div
    class="ecc-page ecc2-page"
    data-test="contacts-module"
    data-test-ux="contacts-ux-v2"
    x-data="{ tab: 'contacts', drawer: false }"
    x-on:keydown.escape.window="drawer = false"
>
    <header class="ecc2-header">
        <div>
            <span class="ecc2-eyebrow">RELACIONAMENTO / EXPORTCONTROL</span>
            <h1>Central de Contatos</h1>
            <p>Encontre empresas, visualize contatos e consulte as fontes comerciais em um só lugar.</p>
        </div>
        <div class="ecc2-header-actions">
            <button type="button" class="ecc2-button ecc2-button-secondary" wire:click="$refresh" wire:loading.attr="disabled">
                <span aria-hidden="true">↻</span> Atualizar visualização
            </button>
            <button type="button" class="ecc2-button ecc2-button-primary" wire:click="startNewContact"
                @disabled(! $detail || $detail['kind'] === 'orphan')>
                <span aria-hidden="true">＋</span> Novo contato
            </button>
        </div>
    </header>

    <section class="ecc2-overview" aria-label="Resumo dos dados disponíveis">
        <div class="ecc2-stat"><span class="ecc2-stat-icon">♙</span><div><small>Contatos do HubSpot</small><strong>{{ $number($summary['hubspot_contacts']) }}</strong><span>Espelho local do CRM</span></div></div>
        <div class="ecc2-stat"><span class="ecc2-stat-icon ecc2-green">▣</span><div><small>Contatos manuais</small><strong>{{ $number($summary['manual_contacts']) }}</strong><span>Cadastrados no Prospector</span></div></div>
        <div class="ecc2-stat"><span class="ecc2-stat-icon ecc2-amber">⌁</span><div><small>Sem empresa vinculada</small><strong>{{ $number($summary['orphan_contacts']) }}</strong><span>Contatos a relacionar</span></div></div>
        <div class="ecc2-stat"><span class="ecc2-stat-icon">▤</span><div><small>Empresas no filtro</small><strong>{{ $number($directory->total()) }}</strong><span>Resultado da seleção atual</span></div></div>
    </section>

    @if (session('contacts_success'))
        <div class="ecc2-alert ecc2-alert-ok" role="status">{{ session('contacts_success') }}</div>
    @endif
    @error('contact')
        <div class="ecc2-alert ecc2-alert-error" role="alert">{{ $message }}</div>
    @enderror

    <nav class="ecc2-segments" aria-label="Filtros de relacionamento">
        @foreach ([
            'all' => 'Todas',
            'clients' => 'Clientes',
            'deals' => 'Com negócios',
            'hubspot' => 'Vinculadas ao HubSpot',
            'receita' => 'Com dados cadastrais',
            'without_people' => 'Sem pessoas',
            'orphan' => 'Sem empresa',
        ] as $key => $label)
            <button type="button" wire:click="$set('segment', '{{ $key }}')"
                x-on:click="drawer = false; tab = 'contacts'"
                class="{{ $segment === $key ? 'is-active' : '' }}"
                aria-pressed="{{ $segment === $key ? 'true' : 'false' }}">
                {{ $label }}
            </button>
        @endforeach
        <button class="ecc2-clear" type="button" wire:click="clearFilters" x-on:click="drawer=false;tab='contacts'">Limpar filtros</button>
    </nav>

    <div class="ecc2-workspace">
        <section class="ecc2-panel ecc2-companies" aria-labelledby="ecc2-companies-title">
            <div class="ecc2-panel-title">
                <div><h2 id="ecc2-companies-title">Empresas</h2><p>Selecione uma empresa para abrir sua central.</p></div>
                <span class="ecc2-count">{{ $number($directory->total()) }}</span>
            </div>
            <div class="ecc2-company-search">
                <label for="ecc2-company-input" class="ecc-sr">Buscar empresa ou contato</label>
                <span aria-hidden="true">⌕</span>
                <input id="ecc2-company-input" type="search" wire:model.live.debounce.400ms="searchTerm"
                    x-on:input="drawer = false" placeholder="Empresa, CNPJ, pessoa, telefone..." autocomplete="off">
                @if ($searchTerm !== '')
                    <button type="button" wire:click="$set('searchTerm', '')" x-on:click="drawer=false" aria-label="Limpar busca">×</button>
                @endif
            </div>
            <div class="ecc2-company-list" wire:loading.class="is-loading" wire:target="searchTerm,segment,changeCompanyPage">
                @if ($segment === 'orphan')
                    <button type="button" class="ecc2-company-item {{ $activeKey === 'orphan:0' ? 'is-selected' : '' }}"
                        wire:click="selectCompany('orphan', 0)" x-on:click="drawer=false;tab='contacts'">
                        <span class="ecc2-company-symbol ecc2-unlinked">?</span>
                        <span class="ecc2-company-text"><strong>Sem empresa no HubSpot</strong><small>Contatos sem associação empresarial</small></span>
                        <span class="ecc2-company-pill">{{ $number($summary['orphan_contacts']) }}</span>
                    </button>
                @endif
                @forelse ($directory as $company)
                    @php $companyKey = $company->directory_type.':'.$company->record_id; @endphp
                    <button type="button" wire:key="ecc2-company-{{ $companyKey }}"
                        class="ecc2-company-item {{ $activeKey === $companyKey ? 'is-selected' : '' }}"
                        wire:click="selectCompany('{{ $company->directory_type }}', {{ $company->record_id }})"
                        x-on:click="drawer=false;tab='contacts'">
                        <span class="ecc2-company-symbol">{{ mb_strtoupper(mb_substr((string) ($company->company_name ?: '?'), 0, 1)) }}</span>
                        <span class="ecc2-company-text"><strong title="{{ $company->company_name }}">{{ $company->company_name ?: 'Empresa sem nome' }}</strong>
                            <small>{{ $company->cnpj_root ? 'CNPJ raiz '.$company->cnpj_root : 'HubSpot · vínculo fiscal não confirmado' }}</small>
                        </span>
                        <span class="ecc2-source-badge {{ $company->directory_type === 'hubspot' ? 'is-hubspot' : '' }}">{{ $company->directory_type === 'hubspot' ? 'HS' : 'BASE' }}</span>
                    </button>
                @empty
                    @if ($segment !== 'orphan')
                        <div class="ecc2-empty ecc2-empty-small">Nenhuma empresa encontrada. Tente outro filtro ou nome.</div>
                    @endif
                @endforelse
            </div>
            <footer class="ecc2-pagination">
                <span>Página {{ $directory->currentPage() }} de {{ $directory->lastPage() }} · {{ $number($directory->total()) }} empresas</span>
                <div>
                    <button type="button" wire:click="changeCompanyPage({{ max(1, $directory->currentPage() - 1) }})" x-on:click="drawer=false;tab='contacts'" @disabled($directory->onFirstPage()) aria-label="Empresas: página anterior">←</button>
                    <button type="button" wire:click="changeCompanyPage({{ $directory->currentPage() + 1 }})" x-on:click="drawer=false;tab='contacts'" @disabled(! $directory->hasMorePages()) aria-label="Empresas: próxima página">→</button>
                </div>
            </footer>
        </section>

        <section class="ecc2-panel ecc2-company-center" aria-labelledby="ecc2-detail-company">
            @if ($detail)
                <header class="ecc2-selected-company">
                    <span class="ecc2-selected-icon">{{ mb_strtoupper(mb_substr((string) $detail['name'], 0, 1)) }}</span>
                    <div class="ecc2-selected-info">
                        <span class="ecc2-eyebrow">EMPRESA SELECIONADA</span>
                        <h2 id="ecc2-detail-company">{{ $detail['name'] }}</h2>
                        <p>{{ $detail['originLabel'] }} @if ($detail['cnpj']) · CNPJ raiz {{ $detail['cnpj'] }} @endif</p>
                        <div class="ecc2-company-tags">
                            <span class="ecc2-tag {{ str_contains(mb_strtolower((string) $detail['status']), 'cliente') ? 'is-client' : '' }}">{{ $detail['status'] }}</span>
                            @if (! empty($detail['mirrorIds']))<span class="ecc2-tag">HubSpot associado</span>@endif
                            @if ($detail['kind'] === 'local')<span class="ecc2-tag is-receita">Base cadastral</span>@endif
                        </div>
                    </div>
                    <div class="ecc2-company-links">
                        @if ($rootLink && Route::has('companies.show'))
                            <a href="{{ route('companies.show', $rootLink) }}" wire:navigate class="ecc2-button ecc2-button-secondary">Abrir dossiê ↗</a>
                        @endif
                        @if ($companyHubspotUrl)
                            <a href="{{ $companyHubspotUrl }}" target="_blank" rel="noopener noreferrer" class="ecc2-button ecc2-button-secondary">Ver no HubSpot ↗</a>
                        @endif
                    </div>
                </header>

                <nav class="ecc2-tabs" aria-label="Seções da empresa" role="tablist">
                    <button id="ecc2-tab-contacts" type="button" role="tab" x-on:click="tab='contacts';drawer=false"
                        x-bind:aria-selected="tab==='contacts'" x-bind:class="tab==='contacts' ? 'is-active' : ''" aria-controls="ecc2-tab-content-contacts">
                        Contatos <span>{{ $number($people->total()) }}</span>
                    </button>
                    <button id="ecc2-tab-deals" type="button" role="tab" x-on:click="tab='deals';drawer=false"
                        x-bind:aria-selected="tab==='deals'" x-bind:class="tab==='deals' ? 'is-active' : ''" aria-controls="ecc2-tab-content-deals">
                        Negócios <span>{{ count($detail['deals']) }}</span>
                    </button>
                    <button id="ecc2-tab-receita" type="button" role="tab" x-on:click="tab='receita';drawer=false"
                        x-bind:aria-selected="tab==='receita'" x-bind:class="tab==='receita' ? 'is-active' : ''" aria-controls="ecc2-tab-content-receita">
                        Dados cadastrais <span>{{ count($detail['receipt']) }}</span>
                    </button>
                </nav>

                <div class="ecc2-tab-panel" id="ecc2-tab-content-contacts" role="tabpanel" aria-labelledby="ecc2-tab-contacts" x-show="tab==='contacts'">
                    <div class="ecc2-page-metrics">
                        <div><span class="ecc2-metric-icon">♙</span><small>Contatos encontrados</small><strong>{{ $number($people->total()) }}</strong><em>Com os filtros atuais</em></div>
                        <div><span class="ecc2-metric-icon">✉</span><small>Com e-mail</small><strong>{{ $number($shownWithEmail) }}</strong><em>Nesta página</em></div>
                        <div><span class="ecc2-metric-icon">☎</span><small>Com telefone</small><strong>{{ $number($shownWithPhone) }}</strong><em>Nesta página</em></div>
                        <div><span class="ecc2-metric-icon">✓</span><small>WhatsApp confirmado</small><strong>{{ $number($shownWithWhatsapp) }}</strong><em>Nesta página</em></div>
                        <button type="button" class="ecc2-button ecc2-button-primary" wire:click="startNewContact"
                            @disabled($detail['kind'] === 'orphan')>＋ Adicionar</button>
                    </div>
                    <div class="ecc2-people-filters">
                        <label class="ecc-sr" for="ecc2-people-search">Buscar contatos desta empresa</label>
                        <input id="ecc2-people-search" type="search" wire:model.live.debounce.350ms="peopleSearch"
                            x-on:input="drawer=false" placeholder="Buscar nome, cargo, telefone ou e-mail...">
                        <select wire:model.live="peopleOrigin" x-on:change="drawer=false" aria-label="Filtrar origem dos contatos">
                            <option value="all">Todas as origens</option><option value="hubspot">HubSpot</option>
                            <option value="manual">Manual</option><option value="email">Com e-mail</option>
                        </select>
                        <select wire:model.live="whatsappFilter" x-on:change="drawer=false" aria-label="Filtrar WhatsApp">
                            <option value="all">Todos os WhatsApp</option><option value="yes">Confirmados</option>
                            <option value="no">Sem WhatsApp</option><option value="unknown">Não verificados</option>
                        </select>
                    </div>
                    <div class="ecc2-table-wrap" role="region" tabindex="0" aria-label="Tabela de contatos da empresa">
                        <table class="ecc2-table">
                            <thead><tr>
                                <th scope="col">Contato / E-mail</th><th scope="col">Cargo</th><th scope="col">Telefone</th>
                                <th scope="col">WhatsApp</th><th scope="col">Origem</th><th scope="col">Última atividade</th><th scope="col">Ações</th>
                            </tr></thead>
                            <tbody>
                                @forelse ($peopleRows as $entry)
                                    @php
                                        $entryName = trim((string) $entry->person_name) ?: 'Contato sem nome';
                                        $entryEmail = trim((string) ($entry->email ?? ''));
                                        $entryPhone = trim((string) ($entry->phone ?: $entry->mobile_phone ?: ''));
                                        $waMobileStatus = $detail['verifications'][$entry->source_key.'|'.\App\Services\Contacts\ContactDirectory::digits((string) $entry->mobile_phone)] ?? null;
                                        $waPhoneStatus = $detail['verifications'][$entry->source_key.'|'.\App\Services\Contacts\ContactDirectory::digits((string) $entry->phone)] ?? null;
                                        $waStatus = in_array('yes', [$waPhoneStatus, $waMobileStatus], true) ? 'yes' : (in_array('no', [$waPhoneStatus, $waMobileStatus], true) ? 'no' : 'unknown');
                                        $lastActivity = $entry->last_activity_at ? \Illuminate\Support\Carbon::parse((string) $entry->last_activity_at)->format('d/m/Y') : '—';
                                    @endphp
                                    <tr wire:key="ecc2-person-{{ $entry->source_key }}" class="{{ $selectedPersonKey === $entry->source_key ? 'is-selected' : '' }}">
                                        <td><div class="ecc2-person-cell"><span class="ecc2-avatar">{{ mb_strtoupper(mb_substr($entryName, 0, 1)) }}</span>
                                            <div><strong>{{ $entryName }}</strong><small>{{ $entryEmail ?: 'E-mail não informado' }}</small></div></div></td>
                                        <td>{{ $entry->job_title ?: 'Não informado' }}</td>
                                        <td class="ecc2-nowrap">{{ $entryPhone ?: '—' }}</td>
                                        <td>@if ($waStatus === 'yes')<span class="ecc2-whatsapp is-yes">✓ Confirmado</span>
                                            @elseif ($waStatus === 'no')<span class="ecc2-whatsapp is-no">Não possui</span>
                                            @else<span class="ecc2-whatsapp">Não verificado</span>@endif</td>
                                        <td><span class="ecc2-source {{ $entry->person_type === 'hubspot' ? 'is-hubspot' : '' }}">{{ $entry->person_type === 'hubspot' ? 'HubSpot' : 'Manual' }}</span></td>
                                        <td class="ecc2-nowrap">{{ $lastActivity }}</td>
                                        <td><button type="button" class="ecc2-view-button"
                                            wire:click="showPerson(@js((string) $entry->source_key))"
                                            x-on:click="drawer=true;$nextTick(() => $refs.drawerClose?.focus())"
                                            aria-label="Ver dados de {{ $entryName }}">Detalhes ↗</button></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="ecc2-no-table-rows">
                                        <strong>Nenhum contato pessoal neste filtro</strong>
                                        <span>Veja os dados cadastrais da empresa na aba “Dados cadastrais” ou adicione um contato manual.</span>
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <footer class="ecc2-table-foot">
                        <span>Mostrando {{ $people->firstItem() ?? 0 }}–{{ $people->lastItem() ?? 0 }} de {{ $number($people->total()) }} contatos</span>
                        <div><button type="button" wire:click="changePeoplePage({{ max(1, $people->currentPage() - 1) }})" x-on:click="drawer=false" @disabled($people->onFirstPage()) aria-label="Contatos: página anterior">←</button>
                            <span>{{ $people->currentPage() }} / {{ $people->lastPage() }}</span>
                            <button type="button" wire:click="changePeoplePage({{ $people->currentPage() + 1 }})" x-on:click="drawer=false" @disabled(! $people->hasMorePages()) aria-label="Contatos: próxima página">→</button>
                        </div>
                    </footer>
                    <div class="ecc2-bottom-cards">
                        <div><h3>Origem dos contatos</h3><p>As pessoas acima vêm do HubSpot ou do cadastro local. Consulte os dados de estabelecimentos separadamente.</p>
                            <button type="button" class="ecc2-text-button" x-on:click="tab='receita'">Consultar Receita →</button></div>
                        <div><h3>Negócios vinculados</h3><strong>{{ count($detail['deals']) }} <small>exibidos no espelho</small></strong>
                            <button type="button" class="ecc2-text-button" x-on:click="tab='deals'">Ver negócios →</button></div>
                        <div><h3>Contato em destaque</h3>
                            @if ($person)<strong>{{ $person->person_name ?: 'Contato sem nome' }}</strong>
                                <p>{{ $person->job_title ?: 'Cargo não informado' }}</p>
                                <button type="button" class="ecc2-text-button" x-on:click="drawer=true" aria-label="Abrir detalhes do contato">Abrir detalhes →</button>
                            @else<p>Nenhuma pessoa para destacar.</p>@endif
                        </div>
                    </div>
                </div>

                <div class="ecc2-tab-panel ecc2-secondary-panel" id="ecc2-tab-content-deals" role="tabpanel" aria-labelledby="ecc2-tab-deals" x-show="tab==='deals'" x-cloak>
                    <div class="ecc2-content-heading"><div><h3>Negócios vinculados no HubSpot</h3><p>Até 12 registros exibidos. A associação comercial não prova identidade fiscal.</p></div></div>
                    <div class="ecc2-deals-grid">
                        @forelse ($detail['deals'] as $deal)
                            <article class="ecc2-deal-card"><span class="ecc2-eyebrow">NEGÓCIO DO HUBSPOT</span>
                                <h4>{{ $deal->name ?: 'Negócio sem nome' }}</h4>
                                <p>{{ $deal->stage_label ?: 'Etapa não informada' }}</p>
                                <strong>{{ $deal->amount !== null ? (($deal->currency === 'BRL' ? 'R$ ' : (($deal->currency ?: 'Moeda não informada').' ')).number_format((float) $deal->amount, 2, ',', '.')) : 'Valor não informado' }}</strong>
                            </article>
                        @empty
                            <div class="ecc2-empty">Nenhum negócio diretamente associado a esta empresa no espelho do HubSpot.</div>
                        @endforelse
                    </div>
                </div>

                <div class="ecc2-tab-panel ecc2-secondary-panel" id="ecc2-tab-content-receita" role="tabpanel" aria-labelledby="ecc2-tab-receita" x-show="tab==='receita'" x-cloak>
                    <div class="ecc2-content-heading"><div><h3>Canais cadastrais da Receita</h3><p>Telefones e e-mails de estabelecimentos. Não representam necessariamente pessoas ou decisores.</p></div></div>
                    <div class="ecc2-receita-grid">
                        @forelse ($detail['receipt'] as $est)
                            <article class="ecc2-receita-card">
                                <div><strong>{{ $est['kind'] === 'matrix' ? 'Matriz' : 'Filial' }} · {{ $est['city'] ?: 'Município não informado' }} / {{ $est['uf'] ?: '—' }}</strong><small>{{ \App\Support\Cnpj::isWellFormed((string) $est['cnpj']) ? \App\Support\Cnpj::format((string) $est['cnpj']) : $est['cnpj'] }}</small></div>
                                @if ($est['email'])<p>E-mail: <a href="mailto:{{ $est['email'] }}">{{ $est['email'] }}</a></p>@endif
                                @foreach (['phone_1' => 'Telefone 1', 'phone_2' => 'Telefone 2'] as $field => $label)
                                    @if ($est[$field])
                                        @php
                                            $check = $detail['verifications'][$est['source_key'].'|'.\App\Services\Contacts\ContactDirectory::digits((string) $est[$field])] ?? null;
                                            $dial = \App\Services\Contacts\ContactDirectory::dialLink((string) $est[$field]);
                                        @endphp
                                        <div class="ecc2-receita-line"><span>{{ $label }}</span>
                                            @if ($dial)<a href="{{ $dial }}">{{ $est[$field] }}</a>@else<strong>{{ $est[$field] }}</strong>@endif
                                            <small>{{ match ($check) { 'yes' => 'WhatsApp confirmado', 'no' => 'Não possui WhatsApp', default => 'WhatsApp não verificado' } }}</small>
                                            <div class="ecc2-inline-verification" role="group" aria-label="Confirmar WhatsApp deste número cadastral">
                                                <button type="button" wire:click="setWhatsapp('receita',{{ $est['id'] }},'{{ $field }}','yes')" class="{{ $check === 'yes' ? 'is-positive' : '' }}">Tem Whats</button>
                                                <button type="button" wire:click="setWhatsapp('receita',{{ $est['id'] }},'{{ $field }}','no')" class="{{ $check === 'no' ? 'is-negative' : '' }}">Não tem</button>
                                                @if ($check)<button type="button" wire:click="setWhatsapp('receita',{{ $est['id'] }},'{{ $field }}','unknown')">Limpar</button>@endif
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </article>
                        @empty
                            <div class="ecc2-empty">Nenhum estabelecimento com canais cadastrais disponível para esta seleção. Empresas HubSpot sem vínculo fiscal confirmado não recebem contatos da Receita por semelhança de nome.</div>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="ecc2-initial-empty"><span class="ecc2-initial-icon">⌕</span><h2 id="ecc2-detail-company">Selecione uma empresa</h2><p>Escolha um nome à esquerda para abrir os contatos, negócios e os canais cadastrais disponíveis.</p></div>
            @endif
        </section>
    </div>

    @if ($detail && $person)
        @php
            $pname = trim((string) $person->person_name) ?: 'Contato sem nome';
            $externalId = (string) ($person->external_id ?? '');
            $contactHubspotUrl = ctype_digit($hubspotPortal) && ctype_digit($externalId)
                ? 'https://app.hubspot.com/contacts/'.$hubspotPortal.'/record/0-1/'.$externalId : null;
        @endphp
        <div class="ecc2-drawer-overlay" x-show="drawer" x-cloak x-transition.opacity x-on:click="drawer=false" aria-hidden="true"></div>
        <aside class="ecc2-drawer" x-show="drawer" x-cloak x-transition x-on:click.stop role="dialog" aria-modal="true" aria-labelledby="ecc2-contact-name">
            <header class="ecc2-drawer-head"><div><span class="ecc2-eyebrow">DETALHES DO CONTATO</span><h2>Contato e canais</h2></div>
                <button type="button" x-ref="drawerClose" x-on:click="drawer=false" aria-label="Fechar detalhes">×</button></header>
            <div class="ecc2-drawer-body">
                <div class="ecc-person-profile"><span class="ecc-person-avatar ecc-person-avatar-large">{{ mb_strtoupper(mb_substr($pname, 0, 1)) }}</span>
                    <div><h3 id="ecc2-contact-name">{{ $pname }}</h3><p>{{ $person->job_title ?: 'Cargo não informado' }}</p>
                        <span class="ecc2-source {{ $person->person_type === 'hubspot' ? 'is-hubspot' : '' }}">{{ $person->person_type === 'hubspot' ? 'HubSpot' : 'Cadastro manual' }}</span>
                    </div>
                </div>
                <div class="ecc-field"><span>Empresa</span><strong>{{ $detail['name'] }}</strong></div>
                <div class="ecc-field"><span>E-mail</span>
                    @if ($person->email)<a href="mailto:{{ $person->email }}">{{ $person->email }}</a>@else<strong>Não informado</strong>@endif
                </div>
                @foreach (['phone' => 'Telefone', 'mobile_phone' => 'Celular / campo WhatsApp'] as $field => $fieldLabel)
                    @php
                        $raw = trim((string) ($person->{$field} ?? ''));
                        $digits = \App\Services\Contacts\ContactDirectory::digits($raw);
                        $verified = $detail['verifications'][$person->source_key.'|'.$digits] ?? null;
                        $tel = \App\Services\Contacts\ContactDirectory::dialLink($raw);
                        $wa = \App\Services\Contacts\ContactDirectory::whatsappLink($raw);
                    @endphp
                    @if ($raw)
                        <div class="ecc-field ecc-phone-field"><span>{{ $fieldLabel }}</span>
                            <strong>@if ($tel)<a href="{{ $tel }}">{{ $raw }}</a>@else{{ $raw }}@endif</strong>
                            <small class="{{ $verified === 'yes' ? 'ecc-whats-yes' : '' }}">{{ match ($verified) {
                                'yes' => 'WhatsApp confirmado',
                                'no' => 'Confirmado: não possui WhatsApp',
                                default => ($field === 'mobile_phone' && $person->person_type === 'hubspot' ? 'Celular do HubSpot; WhatsApp não verificado' : 'WhatsApp não verificado')
                            } }}</small>
                            <div class="ecc2-inline-verification" role="group" aria-label="Verificar WhatsApp deste telefone">
                                <button type="button" class="{{ $verified === 'yes' ? 'is-positive' : '' }}" wire:click="setWhatsapp('{{ $person->person_type }}', {{ $person->person_id }}, '{{ $field }}', 'yes')">Tem Whats</button>
                                <button type="button" class="{{ $verified === 'no' ? 'is-negative' : '' }}" wire:click="setWhatsapp('{{ $person->person_type }}', {{ $person->person_id }}, '{{ $field }}', 'no')">Não tem</button>
                                @if ($verified)<button type="button" wire:click="setWhatsapp('{{ $person->person_type }}', {{ $person->person_id }}, '{{ $field }}', 'unknown')">Limpar</button>@endif
                            </div>
                            @if ($wa && ($verified === 'yes' || ($field === 'mobile_phone' && $verified !== 'no' && $person->person_type === 'hubspot')))
                                <a class="ecc2-external-link" href="{{ $wa }}" target="_blank" rel="noopener noreferrer">{{ $verified === 'yes' ? 'Abrir WhatsApp' : 'Tentar WhatsApp (não verificado)' }} ↗</a>
                            @endif
                        </div>
                    @endif
                @endforeach
                @if ($person->notes)<div class="ecc-note"><strong>Observações locais</strong><p>{{ $person->notes }}</p></div>@endif
                <div class="ecc2-drawer-actions">
                    @if ($contactHubspotUrl)<a href="{{ $contactHubspotUrl }}" target="_blank" rel="noopener noreferrer" class="ecc2-button ecc2-button-secondary">Abrir contato no HubSpot ↗</a>@endif
                    @if ($person->person_type === 'manual')<button type="button" class="ecc2-button ecc2-button-secondary" wire:click="editManualContact({{ $person->person_id }})" x-on:click="drawer=false">Editar contato manual</button>@endif
                </div>
            </div>
        </aside>
    @endif

    @if ($formOpen)
        <div class="ecc-modal-mask" role="presentation">
            <section class="ecc-modal" role="dialog" aria-modal="true" aria-labelledby="ecc-form-title">
                <header><div><span class="ecc-eyebrow">BASE DO PROSPECTOR</span><h2 id="ecc-form-title">{{ $editingContactId ? 'Editar contato manual' : 'Novo contato manual' }}</h2>
                    <p>O HubSpot não será alterado. Os dados serão salvos apenas no Prospector.</p></div>
                    <button type="button" wire:click="cancelForm" aria-label="Fechar">×</button></header>
                <form wire:submit="saveContact">
                    <label>Nome completo <input type="text" wire:model="formName" maxlength="180" required>@error('formName')<small class="ecc-error">{{ $message }}</small>@enderror</label>
                    <label>Cargo <input type="text" wire:model="formJob" maxlength="180"></label>
                    <label>E-mail <input type="email" wire:model="formEmail" maxlength="255">@error('formEmail')<small class="ecc-error">{{ $message }}</small>@enderror</label>
                    <div class="ecc-form-grid"><label>Telefone <input type="tel" wire:model="formPhone" maxlength="80"></label>
                        <label>Celular <input type="tel" wire:model="formMobile" maxlength="80"></label></div>
                    <label>Observações <textarea wire:model="formNotes" maxlength="3000" rows="4"></textarea></label>
                    <footer><button type="button" class="ecc2-button ecc2-button-secondary" wire:click="cancelForm">Cancelar</button>
                        <button type="submit" class="ecc2-button ecc2-button-primary" wire:loading.attr="disabled" wire:target="saveContact">Salvar contato</button></footer>
                </form>
            </section>
        </div>
    @endif
    <div class="ecc2-footer-note">As informações vêm do espelho local do HubSpot, do cadastro fiscal e dos contatos manuais. A atualização da página não sincroniza o HubSpot.</div>
</div>
