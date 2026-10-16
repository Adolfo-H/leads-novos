<?php

use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignReplyReader;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $kind = 'commercial';
    public ?int $selectedId = null;
    public string $search = '';

    public function mount(): void
    {
        $this->ensureManager();
    }

    private function ensureManager(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    public function chooseKind(string $kind): void
    {
        $this->ensureManager();
        abort_unless(in_array($kind, ['commercial', 'pilot'], true), 422);
        $this->kind = $kind;
        $this->selectedId = null;
        $this->resetPage('replyPage');
        unset($this->selected, $this->conversations);
    }

    public function updatedSearch(): void
    {
        $this->ensureManager();
        $this->search = mb_substr(trim($this->search), 0, 100);
        $this->resetPage('replyPage');
        $this->selectedId = null;
        unset($this->selected, $this->conversations);
    }

    #[Computed]
    public function conversations(): LengthAwarePaginator
    {
        $this->ensureManager();
        // Escape de curingas SQL para que a busca seja literal, inclusive em e-mails.
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($this->search), 0, 100));
        if ($this->kind === 'pilot') {
            return EmailCampaignTestMessage::query()
                ->whereNotNull('replied_at')
                ->when($term !== '', static function ($query) use ($term): void {
                    $query->where(static function ($filter) use ($term): void {
                        $filter->where('test_to', 'like', '%'.$term.'%')
                            ->orWhere('reply_subject', 'like', '%'.$term.'%');
                    });
                })
                ->with('campaign')
                ->orderByDesc('replied_at')->paginate(14, ['*'], 'replyPage');
        }

        return EmailCampaignReply::query()
            ->when($term !== '', static function ($query) use ($term): void {
                $query->where(static function ($filter) use ($term): void {
                    $filter->where('from_email', 'like', '%'.$term.'%')
                        ->orWhere('subject', 'like', '%'.$term.'%');
                });
            })
            ->with(['campaign', 'recipient'])
            ->orderByDesc('received_at')->paginate(14, ['*'], 'replyPage');
    }

    #[Computed]
    public function selected(): EmailCampaignReply|EmailCampaignTestMessage|null
    {
        $this->ensureManager();
        if ($this->selectedId === null) {
            return null;
        }
        return $this->kind === 'pilot'
            ? EmailCampaignTestMessage::query()->whereNotNull('replied_at')->with('campaign')->find($this->selectedId)
            : EmailCampaignReply::query()->with(['campaign', 'recipient'])->find($this->selectedId);
    }

    public function open(int $id): void
    {
        $this->ensureManager();
        $exists = $this->kind === 'pilot'
            ? EmailCampaignTestMessage::query()->whereNotNull('replied_at')->whereKey($id)->exists()
            : EmailCampaignReply::query()->whereKey($id)->exists();
        abort_unless($exists, 404);
        $this->selectedId = $id;
        unset($this->selected);
        $this->resetErrorBag();
    }

    public function loadBody(CampaignReplyReader $reader): void
    {
        $this->ensureManager();
        abort_if($this->selectedId === null, 422);
        if ($this->kind === 'pilot') {
            $reader->readPilotReply($this->selectedId);
        } else {
            $reader->readCampaignReply($this->selectedId);
        }
        unset($this->selected);
    }
};
?>
<div class="ecm6-page ecm7-page" wire:poll.90s>
    <header class="ecm6-heading ecm7-page-heading">
        <div>
            <span class="ecm6-eyebrow">RELACIONAMENTO / CAMPANHAS</span>
            <h1>Caixa de respostas</h1>
            <p>Leia as conversas vinculadas às suas campanhas, com segurança e contexto.</p>
        </div>
        <span class="ecm7-status"><span class="ecm7-status-dot"></span> IMAP · leitura restrita</span>
    </header>
    @include('campaigns.module-nav')

    <div class="ecm7-section-heading">
        <div>
            <h2>Mensagens recebidas</h2>
            <p>Selecione uma conversa para consultar seu conteúdo. Nenhuma mensagem é enviada nesta tela.</p>
        </div>
        <div class="ecm7-switch" role="group" aria-label="Tipo de resposta">
            <button type="button" wire:click="chooseKind('commercial')" class="{{ $kind === 'commercial' ? 'is-active' : '' }}" aria-pressed="{{ $kind === 'commercial' ? 'true' : 'false' }}">Comerciais</button>
            <button type="button" wire:click="chooseKind('pilot')" class="{{ $kind === 'pilot' ? 'is-active' : '' }}" aria-pressed="{{ $kind === 'pilot' ? 'true' : 'false' }}">Testes piloto</button>
        </div>
    </div>

    <div class="ecm6-inbox ecm7-inbox">
        <section class="ecm6-panel ecm6-inbox-list ecm7-inbox-list" aria-label="Mensagens vinculadas">
            <header class="ecm7-list-heading">
                <div><h2>Conversas</h2><p>{{ number_format($this->conversations->total(), 0, ',', '.') }} resultado(s)</p></div>
                <span class="ecm7-count">{{ $this->conversations->total() }}</span>
            </header>
            <div class="ecm7-inbox-search">
                <label for="ecm7-reply-search" class="ecm7-search-label">Buscar por remetente ou assunto</label>
                <div class="ecm7-search-field">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
                    <input id="ecm7-reply-search" type="search" wire:model.live.debounce.400ms="search" placeholder="Ex.: cliente@empresa.com" autocomplete="off">
                </div>
            </div>
            <div class="ecm6-inbox-scroll">
                @forelse ($this->conversations as $entry)
                    @php
                        $sender = $kind === 'pilot' ? (string) $entry->test_to : (string) $entry->from_email;
                        $subject = $kind === 'pilot' ? (string) ($entry->reply_subject ?: 'Resposta ao teste') : (string) ($entry->subject ?: 'Sem assunto');
                        $when = $kind === 'pilot' ? $entry->replied_at : $entry->received_at;
                    @endphp
                    <button wire:key="ecm7-reply-{{ $kind }}-{{ $entry->id }}" wire:click="open({{ $entry->id }})" type="button" class="ecm6-inbox-item {{ $selectedId === $entry->id ? 'is-selected' : '' }}" aria-pressed="{{ $selectedId === $entry->id ? 'true' : 'false' }}">
                        <span class="ecm6-letter">{{ mb_strtoupper(mb_substr($sender, 0, 1)) }}</span>
                        <span class="ecm6-inbox-item-content"><strong title="{{ $sender }}">{{ $sender }}</strong><span>{{ $subject }}</span><small>{{ $entry->campaign?->name ?? 'Campanha' }}</small></span>
                        <time datetime="{{ $when?->toIso8601String() }}">{{ $when?->format('d/m H:i') }}</time>
                    </button>
                @empty
                    <div class="ecm7-empty-state">
                        <span aria-hidden="true">✉</span>
                        <strong>{{ $search !== '' ? 'Nenhuma conversa encontrada' : 'Nenhuma resposta nesta categoria' }}</strong>
                        <p>{{ $search !== '' ? 'Experimente outro remetente ou assunto.' : 'Quando alguém responder a uma campanha vinculada, a conversa aparecerá aqui.' }}</p>
                    </div>
                @endforelse
            </div>
            @if ($this->conversations->hasPages())
                <footer class="ecm6-pagination">{{ $this->conversations->links() }}</footer>
            @endif
        </section>

        <section class="ecm6-panel ecm6-message ecm7-reader" aria-label="Leitura da resposta">
            @if ($this->selected)
                @php
                    $entry = $this->selected;
                    $test = $kind === 'pilot';
                    $sender = $test ? (string) $entry->test_to : (string) $entry->from_email;
                    $body = $test ? $entry->reply_body_text : $entry->body_text;
                    $subject = $test ? $entry->reply_subject : $entry->subject;
                    $when = $test ? $entry->replied_at : $entry->received_at;
                @endphp
                <header>
                    <div><span class="ecm6-eyebrow">MENSAGEM RECEBIDA</span><h2>{{ $subject ?: 'Sem assunto' }}</h2></div>
                    <span class="ecm6-pill">{{ $test ? 'Piloto' : 'Comercial' }}</span>
                </header>
                <div class="ecm7-reader-meta">
                    <div class="ecm7-reader-avatar">{{ mb_strtoupper(mb_substr($sender, 0, 1)) }}</div>
                    <div class="ecm7-reader-sender"><strong>{{ $sender }}</strong><span>{{ $entry->campaign?->name ?? 'Campanha sem nome' }}</span></div>
                    <time datetime="{{ $when?->toIso8601String() }}">{{ $when?->format('d/m/Y H:i') }}</time>
                </div>
                @if ($body !== null)
                    @php
                        // Apenas apresentação: mantém íntegro o texto original criptografado.
                        $chunks = preg_split('/(?=^\s*(?:-{4,}|De:\s|From:\s|Em\s.+escreveu:|On\s.+wrote:))/mi', (string) $body, 2);
                        $replyText = trim((string) ($chunks[0] ?? $body));
                        $previousThread = (string) ($chunks[1] ?? '');
                        if ($replyText === '') { $replyText = (string) $body; $previousThread = ''; }
                    @endphp
                    <div class="ecm7-message-sheet">
                        <p class="ecm7-message-kicker">Resposta</p>
                        <pre>{{ $replyText }}</pre>
                    </div>
                    @if ($previousThread !== '')
                        <details class="ecm7-thread"><summary>Ver histórico citado no e-mail <span aria-hidden="true">⌄</span></summary><pre>{{ $previousThread }}</pre></details>
                    @endif
                    <div class="ecm7-reader-foot"><span>Texto armazenado criptografado. HTML remoto e anexos não são carregados.</span></div>
                @else
                    <div class="ecm6-message-placeholder ecm7-message-placeholder">
                        <span class="ecm7-placeholder-icon" aria-hidden="true">✉</span>
                        <h3>Mensagem pronta para consulta</h3>
                        <p>Por segurança, recuperamos somente esta resposta pelo identificador IMAP. Sua caixa pessoal não será importada.</p>
                        <button type="button" class="ecm6-primary" wire:click="loadBody" wire:loading.attr="disabled" wire:target="loadBody">Ler mensagem completa</button>
                        <span class="ecm6-progress" wire:loading wire:target="loadBody" role="status">Consultando o servidor IMAP…</span>
                    </div>
                @endif
                @error('reply') <p role="alert" class="ecm6-error">{{ $message }}</p> @enderror
            @else
                <div class="ecm6-message-placeholder ecm7-message-placeholder">
                    <span class="ecm7-placeholder-icon" aria-hidden="true">↩</span>
                    <h3>Selecione uma conversa</h3>
                    <p>Abra uma resposta à esquerda para consultar o remetente, o assunto e o texto recebido.</p>
                </div>
            @endif
        </section>
    </div>
</div>
