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
    public string $kind = 'mobile';

    #[Url(as: 'situacao')]
    public string $status = 'unknown';

    #[Url(as: 'origem')]
    public string $origin = 'all';

    public int $page = 1;

    public ?int $selectedId = null;

    public string $note = '';

    public function boot(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isCommercialManager(), 403);
    }

    public function updatedSearch(): void { $this->resetList(); }
    public function updatedKind(): void { $this->resetList(); }
    public function updatedStatus(): void { $this->resetList(); }
    public function updatedOrigin(): void { $this->resetList(); }

    private function resetList(): void
    {
        $this->page = 1;
        $this->selectedId = null;
        $this->note = '';
        unset($this->items, $this->selectedNumber, $this->associations, $this->history);
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->kind = 'mobile';
        $this->status = 'unknown';
        $this->origin = 'all';
        $this->resetList();
    }

    public function quickFilter(string $kind, string $status): void
    {
        $this->kind = in_array($kind, ['all', 'mobile', 'landline', 'review'], true) ? $kind : 'mobile';
        $this->status = in_array($status, ['all', 'unknown', 'yes', 'no'], true) ? $status : 'unknown';
        $this->resetList();
    }

    public function changePage(int $page): void
    {
        $this->page = max(1, min($page, $this->items->lastPage()));
        $this->selectedId = null;
        $this->note = '';
        unset($this->items, $this->selectedNumber, $this->associations, $this->history);
    }

    public function selectNumber(int $id): void
    {
        abort_unless(collect($this->items->items())->contains(static fn (object $r): bool => (int) $r->id === $id), 404);
        $this->selectedId = $id;
        $this->note = '';
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

    #[Computed]
    public function summary(): array
    {
        return app(WhatsappPhoneRegistry::class)->totals();
    }

    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return app(WhatsappPhoneRegistry::class)->search(
            $this->search, $this->kind, $this->status, $this->origin, $this->page,
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
    $statusLabel = static fn (string $status): string => match ($status) {
        'yes' => 'Confirmado', 'no' => 'Não possui', default => 'Não verificado',
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
<div class="wpr wpr-v2" data-test="whatsapp-phone-registry-v1" data-ec-whatsapp-design="v2">
    @include('contacts.whatsapp-registry-styles')
    @include('contacts.whatsapp-registry-theme-v2')
    <header class="wpr-heading">
        <div>
            <p class="wpr-kicker">EXPORTCONTROL <span> / </span> RELACIONAMENTO <span> / </span> WHATSAPP</p>
            <h1>Central de verificação</h1>
            <p>Um número, uma conferência. Os resultados são compartilhados pelos contatos que utilizam o mesmo telefone.</p>
        </div>
        <a class="wpr-button wpr-button-quiet" href="{{ route('contacts.index') }}" wire:navigate>← Voltar aos contatos</a>
    </header>

    <section class="wpr-hero" aria-label="Situação das verificações">
        <div class="wpr-hero-main">
            <span class="wpr-hero-eyebrow">CONTROLE DE QUALIDADE DOS CONTATOS</span>
            <h2>Saiba quais números já foram conferidos.</h2>
            <p>Comece pelos celulares. Telefones fixos também podem ter WhatsApp Business, mas precisam de conferência separada.</p>
            <div class="wpr-progress-label"><span>Progresso dos números com formato plausível</span><strong>{{ $percentage }}%</strong></div>
            <div class="wpr-progress"><span style="width:{{ $percentage }}%"></span></div>
            <small>Resultado de conferências humanas, não de consultas automáticas à Meta.</small>
        </div>
        <div class="wpr-hero-side">
            <span>ÍNDICE LOCAL</span>
            <strong>{{ $fmt($summary['total']) }}</strong>
            <span>números distintos encontrados</span>
            <div class="wpr-hero-sync">
                @if ($this->lastSync)
                    <span class="wpr-sync-dot"></span>
                    Sincronizado em {{ \Carbon\Carbon::parse($this->lastSync->finished_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}
                @else
                    <span class="wpr-sync-dot wpr-sync-dot-off"></span>
                    Ainda sem sincronização local
                @endif
            </div>
        </div>
    </section>

    <section class="wpr-stats" aria-label="Indicadores de verificação">
        <button class="wpr-stat {{ $kind === 'mobile' ? 'is-active' : '' }}" type="button" wire:click="quickFilter('mobile','all')"><span>Celulares</span><strong>{{ $fmt($summary['mobile']) }}</strong><small>Prioridade comercial</small></button>
        <button class="wpr-stat {{ $kind === 'landline' ? 'is-active' : '' }}" type="button" wire:click="quickFilter('landline','all')"><span>Telefones fixos</span><strong>{{ $fmt($summary['landline']) }}</strong><small>Também podem ter WhatsApp</small></button>
        <button class="wpr-stat {{ $kind === 'review' ? 'is-active' : '' }}" type="button" wire:click="quickFilter('review','all')"><span>Revisar formato</span><strong>{{ $fmt($summary['review']) }}</strong><small>Sem correção automática</small></button>
        <button class="wpr-stat wpr-stat-green {{ $kind === 'all' && $status === 'yes' ? 'is-active' : '' }}" type="button" wire:click="quickFilter('all','yes')"><span>Confirmados</span><strong>{{ $fmt($summary['yes']) }}</strong><small>Verificação registrada</small></button>
        <button class="wpr-stat {{ $kind === 'all' && $status === 'unknown' ? 'is-active' : '' }}" type="button" wire:click="quickFilter('all','unknown')"><span>Pendentes válidos</span><strong>{{ $fmt($summary['pending']) }}</strong><small>Ainda sem prova</small></button>
    </section>

    <section class="wpr-info" role="note"><span class="wpr-info-icon">i</span><p><strong>Sem consulta automática:</strong> este módulo não usa API, não envia mensagens e não faz varredura no WhatsApp. Abrir uma conversa não prova que o número possui conta. Registre <strong>Confirmado</strong> ou <strong>Não possui</strong> apenas após conferir o resultado no aplicativo.</p></section>

    @if (session('wa_registry_success'))
        <div class="wpr-alert" role="status">✓ {{ session('wa_registry_success') }}</div>
    @endif
    @error('wa_status')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror
    @error('wa_note')<div class="wpr-alert wpr-alert-error" role="alert">{{ $message }}</div>@enderror

    {{-- EC_WHATSAPP_VISUAL_V2_SHORTCUTS: filtros rápidos; sem alteração no backend. --}}
    <nav class="wpr-shortcuts" aria-label="Atalhos para a fila de verificação">
        <span class="wpr-shortcuts-title">Atalhos de conferência</span>
        <div class="wpr-shortcuts-list">
            <button type="button" class="wpr-shortcut {{ $kind === 'mobile' && $status === 'unknown' ? 'is-active' : '' }}"
                wire:click="quickFilter('mobile','unknown')" aria-pressed="{{ $kind === 'mobile' && $status === 'unknown' ? 'true' : 'false' }}">Celulares pendentes</button>
            <button type="button" class="wpr-shortcut {{ $kind === 'landline' && $status === 'unknown' ? 'is-active' : '' }}"
                wire:click="quickFilter('landline','unknown')" aria-pressed="{{ $kind === 'landline' && $status === 'unknown' ? 'true' : 'false' }}">Fixos pendentes</button>
            <button type="button" class="wpr-shortcut {{ $kind === 'all' && $status === 'yes' ? 'is-active' : '' }}"
                wire:click="quickFilter('all','yes')" aria-pressed="{{ $kind === 'all' && $status === 'yes' ? 'true' : 'false' }}">Com WhatsApp</button>
            <button type="button" class="wpr-shortcut {{ $kind === 'all' && $status === 'no' ? 'is-active' : '' }}"
                wire:click="quickFilter('all','no')" aria-pressed="{{ $kind === 'all' && $status === 'no' ? 'true' : 'false' }}">Sem WhatsApp</button>
            <button type="button" class="wpr-shortcut {{ $kind === 'review' ? 'is-active' : '' }}"
                wire:click="quickFilter('review','all')" aria-pressed="{{ $kind === 'review' ? 'true' : 'false' }}">Revisar formato</button>
        </div>
    </nav>

    <section class="wpr-filters" aria-label="Filtrar lista de telefones">
        <label class="wpr-search"><span>Buscar número, contato ou empresa</span><input type="search" wire:model.live.debounce.400ms="search" placeholder="Número, pessoa ou empresa..." autocomplete="off"></label>
        <label><span>Tipo</span><select wire:model.live="kind"><option value="mobile">Celulares</option><option value="landline">Fixos</option><option value="review">Revisar formato</option><option value="all">Todos</option></select></label>
        <label><span>WhatsApp</span><select wire:model.live="status"><option value="unknown">Não verificados</option><option value="yes">Confirmados</option><option value="no">Não possuem</option><option value="all">Todos os estados</option></select></label>
        <label><span>Origem</span><select wire:model.live="origin"><option value="all">Todas</option><option value="hubspot">HubSpot</option><option value="manual">Manual</option><option value="receita">Receita Federal</option></select></label>
        <button type="button" class="wpr-button wpr-button-quiet" wire:click="resetFilters">Limpar</button>
    </section>

    <div class="wpr-workspace" wire:loading.class="wpr-is-loading" wire:target="search,kind,status,origin,changePage,mark">
        <section class="wpr-list-panel" aria-label="Números encontrados">
            <div class="wpr-panel-header"><div><h2>Fila de conferência</h2><p>{{ $fmt($items->total()) }} números com os filtros atuais</p></div><span class="wpr-mini-label">20 por página</span></div>
            @if ($rows === [])
                <div class="wpr-empty"><strong>Nenhum número encontrado</strong><p>Altere os filtros para visualizar outra categoria. A lista precisa ser sincronizada pelo comando de indexação.</p><button wire:click="resetFilters" type="button">Ver celulares pendentes</button></div>
            @else
                <div class="wpr-number-list">
                    @foreach ($rows as $row)
                        @php
                            $active = (int) $selected?->id === (int) $row->id;
                            $number = BrazilPhoneNumber::display($row->e164, $row->digits);
                        @endphp
                        <button type="button" wire:key="wpr-number-{{ $row->id }}" class="wpr-number {{ $active ? 'is-active' : '' }}" wire:click="selectNumber({{ $row->id }})" aria-pressed="{{ $active ? 'true' : 'false' }}">
                            <span class="wpr-number-icon">{{ $row->kind === 'mobile' ? '◉' : ($row->kind === 'landline' ? '☎' : '!') }}</span>
                            <span class="wpr-number-content"><strong>{{ $number }}</strong><small>{{ $kindLabel($row->kind) }} · {{ $fmt((int) $row->contacts_count) }} registro(s) vinculado(s)</small></span>
                            <span class="wpr-status wpr-status-{{ $row->status }}">{{ $statusLabel($row->status) }}</span>
                            <span class="wpr-number-chevron">›</span>
                        </button>
                    @endforeach
                </div>
            @endif
            <footer class="wpr-pagination"><span>{{ $items->firstItem() ?? 0 }}–{{ $items->lastItem() ?? 0 }} de {{ $fmt($items->total()) }}</span><div><button type="button" wire:click="changePage({{ $items->currentPage() - 1 }})" @disabled($items->onFirstPage())>Anterior</button><span>{{ $items->currentPage() }} / {{ $items->lastPage() }}</span><button type="button" wire:click="changePage({{ $items->currentPage() + 1 }})" @disabled(! $items->hasMorePages())>Próxima</button></div></footer>
        </section>

        <aside class="wpr-detail-panel" aria-label="Verificação do telefone selecionado">
            @if ($selected)
                <div class="wpr-detail-top"><span class="wpr-eyebrow">TELEFONE SELECIONADO</span><span class="wpr-status wpr-status-{{ $selected->status }}">{{ $statusLabel($selected->status) }}</span></div>
                <h2>{{ BrazilPhoneNumber::display($selected->e164, $selected->digits) }}</h2>
                <p class="wpr-muted">{{ $kindLabel($selected->kind) }} · {{ $fmt((int) $selected->contacts_count) }} registro(s), {{ $fmt((int) $selected->occurrences_count) }} ocorrência(s)</p>

                @if ($selected->kind === 'review')
                    <div class="wpr-warning"><strong>Formato precisa de revisão</strong><p>Confira o DDD, o prefixo e os dígitos diretamente no HubSpot ou no cadastro de origem. Este número não pode ser marcado como confirmado enquanto estiver inconsistente.</p></div>
                @else
                    <div class="wpr-action-panel">
                        <h3>Conferir no aplicativo</h3>
                        <p>Abra a conversa para verificar manualmente. A abertura do link não altera o status e não envia mensagem.</p>
                        <a class="wpr-button wpr-button-primary" href="https://wa.me/{{ $selected->e164 }}" target="_blank" rel="noopener noreferrer">↗ Abrir no WhatsApp</a>
                        <label class="wpr-note-label"><span>Observação da conferência <small>{{ $selected->status === 'unknown' ? '(obrigatória para registrar “Não possui”)' : '(opcional)' }}</small></span>
                            <textarea wire:model="note" rows="2" maxlength="300" placeholder="Ex.: aplicativo confirmou a conta / informou que o número não está no WhatsApp"></textarea>
                        </label>
                        <div class="wpr-action-row"><button type="button" class="wpr-button wpr-button-positive" wire:click="mark('yes')" wire:confirm="Você conferiu no aplicativo e comprovou que o número possui WhatsApp?">✓ Tem WhatsApp</button><button type="button" class="wpr-button wpr-button-neutral" wire:click="mark('no')" wire:confirm="O aplicativo confirmou que este número NÃO possui WhatsApp?">× Não possui</button></div>
                        @if ($selected->status !== 'unknown')
                            <button type="button" class="wpr-clear" wire:click="mark('unknown')" wire:confirm="Remover a confirmação para este número e todos os contatos vinculados?">↶ Desfazer confirmação</button>
                        @endif
                    </div>
                @endif

                @if ($selected->verified_at)
                    <p class="wpr-last-verified">Última confirmação registrada em {{ \Carbon\Carbon::parse($selected->verified_at)->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}.</p>
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
</div>
