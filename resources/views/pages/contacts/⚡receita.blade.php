<?php

use App\Models\User;
use App\Services\Contacts\ContactDirectory;
use App\Services\Contacts\ContactReceitaDirectory;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'q')]
    public string $searchTerm = '';

    #[Url(as: 'tipo')]
    public string $kind = 'all';

    #[Url(as: 'canal')]
    public string $channel = 'all';

    public int $page = 1;

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->assertManager();
    }

    private function assertManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && ($user->isCommercialManager() || $user->isCommercialSeller()), 403);
    }

    public function updatedSearchTerm(): void
    {
        $this->resetList();
    }

    public function updatedKind(): void
    {
        $this->kind = in_array($this->kind, ['all', 'matrix', 'branch'], true) ? $this->kind : 'all';
        $this->resetList();
    }

    public function updatedChannel(): void
    {
        $this->channel = in_array($this->channel, ['all', 'email', 'phone'], true) ? $this->channel : 'all';
        $this->resetList();
    }

    private function resetList(): void
    {
        $this->page = 1;
        $this->selectedId = null;
        unset($this->records);
    }

    public function changePage(int $page): void
    {
        $this->assertManager();
        $this->page = max(1, min($page, 100000));
        $this->selectedId = null;
        unset($this->records);
    }

    public function showEstablishment(int $id): void
    {
        $this->assertManager();
        // Só é possível inspecionar estabelecimentos da página atualmente consultada.
        abort_unless(collect($this->records->items())
            ->contains(static fn (object $r): bool => (int) $r->id === $id), 404);
        $this->selectedId = $id;
    }

    public function resetSearch(): void
    {
        $this->assertManager();
        $this->searchTerm = '';
        $this->kind = 'all';
        $this->channel = 'all';
        $this->resetList();
    }

    #[Computed]
    public function records(): LengthAwarePaginator
    {
        $this->assertManager();

        return app(ContactReceitaDirectory::class)->search(
            $this->searchTerm,
            $this->kind,
            $this->channel,
            $this->page,
        );
    }
};
?>
@php
    $records = $this->records;
    $rows = $records->items();
    $selected = collect($rows)->first(static fn ($item): bool => (int) $item->id === $selectedId)
        ?? ($rows[0] ?? null);
    $hasSearch = mb_strlen(trim($searchTerm)) >= 2;
    $fmt = static fn (int $n): string => number_format($n, 0, ',', '.');
    $value = static fn ($raw): string => trim((string) ($raw ?? ''));
    $cnpjFormat = static function ($raw): string {
        $digits = (string) preg_replace('/\D/', '', (string) $raw);
        if (strlen($digits) !== 14) {
            return (string) $raw;
        }
        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
    };
@endphp
<div class="eccr-page ecc66-receita" data-test="contacts-receita-v4" data-ec-contacts-figma="C05-V66">
    <header class="eccr-heading">
        <div>
            <div class="eccr-eyebrow">RELACIONAMENTO <span>›</span> CENTRAL DE CONTATOS</div>
            <h1>Canais cadastrais da Receita</h1>
            <p>Telefones e e-mails dos estabelecimentos, organizados por empresa, matriz e filiais.</p>
        </div>
        <a class="eccr-back" href="{{ route('contacts.index', ['q' => $searchTerm]) }}" wire:navigate>← Voltar aos contatos pessoais</a>
    </header>

    <nav class="eccr-tabs" aria-label="Origem dos contatos">
        <a href="{{ route('contacts.index', ['q' => $searchTerm]) }}" wire:navigate>Contatos pessoais <span>HubSpot / manuais</span></a>
        <span class="eccr-tab-active" aria-current="page">Canais cadastrais <span>Receita / estabelecimentos</span></span>
    </nav>

    <div class="eccr-intro">
        <span aria-hidden="true">▣</span>
        <div><strong>Informação cadastral ≠ pessoa de contato</strong>
        <p>Os números e e-mails são obtidos da base de estabelecimentos vinculados pelo CNPJ. Não são adicionados automaticamente como pessoas, nem enviados ao HubSpot ou às campanhas.</p></div>
    </div>

    <section class="eccr-filters" aria-label="Pesquisar contatos cadastrais">
        <label class="eccr-query">
            <span>Buscar empresa, CNPJ, telefone ou e-mail</span>
            <input type="search" wire:model.live.debounce.400ms="searchTerm" placeholder="Ex.: C.Vale, 77.863.223, contato@empresa.com.br" autofocus autocomplete="off">
        </label>
        <label><span>Estabelecimento</span>
            <select wire:model.live="kind"><option value="all">Matriz e filiais</option><option value="matrix">Somente matriz</option><option value="branch">Somente filiais</option></select>
        </label>
        <label><span>Canal disponível</span>
            <select wire:model.live="channel"><option value="all">E-mail ou telefone</option><option value="email">Com e-mail</option><option value="phone">Com telefone</option></select>
        </label>
        <button type="button" wire:click="resetSearch">Limpar</button>
    </section>

    <div class="eccr-summary">
        <div><small>Estabelecimentos encontrados</small><strong>{{ $fmt($records->total()) }}</strong></div>
        <div><small>Exibidos nesta página</small><strong>{{ $fmt(count($rows)) }}</strong></div>
        <div><small>Com e-mail nesta página</small><strong>{{ $fmt(collect($rows)->filter(static fn ($r) => $value($r->email) !== '')->count()) }}</strong></div>
        <div><small>Com telefone nesta página</small><strong>{{ $fmt(collect($rows)->filter(static fn ($r) => $value($r->phone_1) !== '' || $value($r->phone_2) !== '')->count()) }}</strong></div>
    </div>

    <div class="eccr-workspace" wire:loading.class="is-loading" wire:target="searchTerm,kind,channel,changePage">
        <section class="eccr-results" aria-label="Estabelecimentos encontrados">
            <div class="eccr-section-head"><h2>Base de estabelecimentos</h2><span>{{ $hasSearch ? 'Resultados da pesquisa' : 'Informe uma empresa para começar' }}</span></div>
            @if (! $hasSearch)
                <div class="eccr-empty"><strong>Pesquise uma empresa</strong><p>Digite “C.Vale” ou um CNPJ para consultar os contatos cadastrais das matrizes e filiais.</p></div>
            @elseif ($rows === [])
                <div class="eccr-empty"><strong>Nenhum canal cadastral encontrado</strong><p>A empresa pode não estar na base local de estabelecimentos, ou seus e-mails e telefones podem estar vazios. Confira a importação da Receita.</p></div>
            @else
                <div class="eccr-list">
                    @foreach ($rows as $row)
                        @php
                            $active = (int) ($selected?->id ?? 0) === (int) $row->id;
                            $email = $value($row->email);
                            $phone = $value($row->phone_1) ?: $value($row->phone_2);
                        @endphp
                        <button type="button" wire:key="eccr-{{ $row->id }}" class="eccr-entry {{ $active ? 'is-active' : '' }}" wire:click="showEstablishment({{ $row->id }})">
                            <span class="eccr-entry-title"><strong>{{ $row->fantasy_name ?: $row->corporate_name }}</strong><span>{{ $row->type === 'matrix' ? 'MATRIZ' : 'FILIAL' }}</span></span>
                            <span class="eccr-company">{{ $row->corporate_name }}</span>
                            <span class="eccr-entry-meta">CNPJ {{ $cnpjFormat($row->cnpj) }} · {{ $row->municipality_name ?: 'Cidade não informada' }}{{ $row->state ? ' / '.$row->state : '' }}</span>
                            <span class="eccr-entry-channels">{{ $email !== '' ? '✉ '.$email : 'E-mail não informado' }} <span>·</span> {{ $phone !== '' ? '☎ '.$phone : 'Telefone não informado' }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
            @if ($records->lastPage() > 1)
                <footer class="eccr-pagination"><span>Página {{ $records->currentPage() }} de {{ $records->lastPage() }}</span>
                    <div><button type="button" wire:click="changePage({{ $records->currentPage() - 1 }})" @disabled($records->onFirstPage())>Anterior</button><button type="button" wire:click="changePage({{ $records->currentPage() + 1 }})" @disabled(! $records->hasMorePages())>Próxima</button></div>
                </footer>
            @endif
        </section>

        <aside class="eccr-inspector" aria-label="Detalhes cadastrais do estabelecimento">
            @if ($selected)
                <div class="eccr-inspector-title"><span>{{ $selected->type === 'matrix' ? 'Matriz' : 'Filial' }} · Receita Federal</span><h2>{{ $selected->corporate_name }}</h2><small>{{ $cnpjFormat($selected->cnpj) }}</small></div>
                <div class="eccr-card"><h3>Informações cadastrais</h3>
                    <div class="eccr-info"><small>Nome fantasia</small><strong>{{ $selected->fantasy_name ?: 'Não informado' }}</strong></div>
                    <div class="eccr-info"><small>Município / UF</small><strong>{{ $selected->municipality_name ?: 'Não informado' }}{{ $selected->state ? ' / '.$selected->state : '' }}</strong></div>
                    <div class="eccr-info"><small>Situação cadastral</small><strong>{{ $selected->registration_status ?: $selected->registration_status_code ?: 'Não informada' }}</strong></div>
                </div>
                <div class="eccr-card"><h3>Canais da Receita</h3>
                    <p class="eccr-note">Canais da empresa; não representam uma pessoa ou WhatsApp confirmado.</p>
                    @if ($value($selected->email) !== '')
                        <div class="eccr-info"><small>E-mail cadastral</small><strong>{{ $selected->email }}</strong><a href="mailto:{{ trim($selected->email) }}">Escrever e-mail ↗</a></div>
                    @else
                        <div class="eccr-info"><small>E-mail cadastral</small><em>Não informado</em></div>
                    @endif
                    @foreach (['phone_1' => 'Telefone principal', 'phone_2' => 'Segundo telefone'] as $col => $label)
                        @php $phone = $value($selected->{$col}); $dial = ContactDirectory::dialLink($phone); @endphp
                        @if ($phone !== '')
                            <div class="eccr-info"><small>{{ $label }}</small><strong>{{ $phone }}</strong>@if ($dial)<a href="{{ $dial }}">Ligar ↗</a>@endif</div>
                        @endif
                    @endforeach
                </div>
                <div class="eccr-card"><h3>Origem e vínculo</h3>
                    <p class="eccr-note">Vínculo por <strong>company_id</strong> na base local, não por semelhança de nome, domínio ou negócio HubSpot.</p>
                    <div class="eccr-info"><small>Fonte informada</small><strong>{{ $selected->source ?: 'Base local de estabelecimentos' }}</strong></div>
                    @if ($selected->source_updated_at)<div class="eccr-info"><small>Atualização da fonte</small><strong>{{ \Carbon\Carbon::parse($selected->source_updated_at)->timezone('America/Sao_Paulo')->format('d/m/Y') }}</strong></div>@endif
                    <a class="eccr-dossier" href="{{ route('companies.show', ['company' => $selected->company_uuid]) }}" wire:navigate>Abrir dossiê da empresa ↗</a>
                </div>
            @else
                <div class="eccr-empty eccr-empty-inspector"><strong>Detalhes do estabelecimento</strong><p>Selecione um registro à esquerda para visualizar e-mail, telefones, CNPJ e origem dos dados.</p></div>
            @endif
        </aside>
    </div>
</div>
