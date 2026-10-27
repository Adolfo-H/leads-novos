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
<div class="ecm6-page ecm7-page ec21-page ec22-page ec21-replies ec22-replies ec69-replies" data-ec69-replies="M07-V69" data-ec21-screen="replies" data-ec22-screen="replies" wire:poll.90s>
    <header class="ec21-module-header"><div><span class="ec22-kicker">CAMPANHAS / RESPOSTAS</span><h1>Caixa de respostas</h1><p>Somente conversas relacionadas às suas campanhas.</p></div><span class="ec21-status">Respostas registradas</span></header>
    @include('campaigns.module-nav')
    <section class="ec69-reply-filter-card" aria-label="Encontre uma conversa">
        <h2>Encontre uma conversa</h2>
        <div class="ec69-reply-filter-grid">
            <label class="ec69-filter-field">
                <span>Pesquisa</span>
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Remetente ou assunto" maxlength="100" autocomplete="off" aria-label="Buscar respostas">
                <small>Pesquise por e-mail ou assunto da mensagem</small>
            </label>
            <div class="ec69-filter-field">
                <span>Categoria</span>
                <div class="ec69-reply-categories" role="group" aria-label="Tipo de resposta">
                    <button type="button" wire:click="chooseKind('commercial')" class="{{ $kind === 'commercial' ? 'is-active' : '' }}" aria-pressed="{{ $kind === 'commercial' ? 'true' : 'false' }}">Comerciais</button>
                    <button type="button" wire:click="chooseKind('pilot')" class="{{ $kind === 'pilot' ? 'is-active' : '' }}" aria-pressed="{{ $kind === 'pilot' ? 'true' : 'false' }}">Respostas de teste</button>
                </div>
                <small>Somente respostas vinculadas a campanhas</small>
            </div>
        </div>
    </section>
    <div class="ec21-inbox">
        <section class="ec21-panel ec21-inbox-list" aria-label="Conversas recebidas">
            <div class="ec21-panel-heading"><div><h3>Respostas recebidas</h3><p>{{ number_format($this->conversations->total(), 0, ',', '.') }} resultado(s)</p></div><span class="ec21-counter">{{ $this->conversations->total() }}</span></div>
            <div class="ec21-inbox-items">
                @forelse ($this->conversations as $entry)
                    @php
                        $sender = $kind === 'pilot' ? (string) $entry->test_to : (string) $entry->from_email;
                        $subject = $kind === 'pilot' ? (string) ($entry->reply_subject ?: 'Resposta ao teste') : (string) ($entry->subject ?: 'Sem assunto');
                        $when = $kind === 'pilot' ? $entry->replied_at : $entry->received_at;
                    @endphp
                    <button type="button" wire:key="ec21-reply-{{ $kind }}-{{ $entry->id }}" wire:click="open({{ $entry->id }})" class="ec21-inbox-item {{ $selectedId === $entry->id ? 'is-selected' : '' }}" aria-pressed="{{ $selectedId === $entry->id ? 'true' : 'false' }}"><span class="ec21-avatar">{{ mb_strtoupper(mb_substr($sender ?: '?', 0, 1)) }}</span><span class="ec21-inbox-copy"><strong>{{ $sender }}</strong><span>{{ $subject }}</span><small>{{ $entry->campaign?->name ?? 'Campanha não informada' }}</small></span><time datetime="{{ $when?->toIso8601String() }}">{{ $when?->format('d/m H:i') }}</time></button>
                @empty
                    <div class="ec21-inbox-empty"><span aria-hidden="true">✉</span><h3>{{ $search !== '' ? 'Nenhuma conversa encontrada' : 'Nenhuma resposta nesta categoria' }}</h3><p>{{ $search !== '' ? 'Tente outro e-mail ou assunto.' : 'Quando uma pessoa responder, a conversa vinculada aparecerá aqui.' }}</p></div>
                @endforelse
            </div>
            @if ($this->conversations->hasPages())<div class="ec21-pagination">{{ $this->conversations->links() }}</div>@endif
        </section>
        <section class="ec21-panel ec21-inbox-message" aria-label="Mensagem selecionada">
            @if ($this->selected)
                @php
                    $entry = $this->selected;
                    $test = $kind === 'pilot';
                    $sender = $test ? (string) $entry->test_to : (string) $entry->from_email;
                    $body = $test ? $entry->reply_body_text : $entry->body_text;
                    $subject = $test ? $entry->reply_subject : $entry->subject;
                    $when = $test ? $entry->replied_at : $entry->received_at;
                @endphp
                <header class="ec21-reader-heading"><div><span class="ec21-eyebrow">MENSAGEM RECEBIDA</span><h3>{{ $subject ?: 'Sem assunto' }}</h3><p>Campanha vinculada: <strong>{{ $entry->campaign?->name ?? 'Não informada' }}</strong></p></div><span class="ec21-tag">{{ $test ? 'Teste piloto' : 'Comercial' }}</span></header>
                <div class="ec21-reader-person"><span class="ec21-avatar">{{ mb_strtoupper(mb_substr($sender ?: '?', 0, 1)) }}</span><div><strong>{{ $sender }}</strong><small>Resposta de e-mail registrada no Prospector</small></div><time datetime="{{ $when?->toIso8601String() }}">{{ $when?->format('d/m/Y H:i') }}</time></div>
                @if ($body !== null)
                    @php
                        $chunks = preg_split('/(?=^\s*(?:-{4,}|De:\s|From:\s|Em\s.+escreveu:|On\s.+wrote:))/mi', (string) $body, 2);
                        $replyText = trim((string) ($chunks[0] ?? $body));
                        $previousThread = (string) ($chunks[1] ?? '');
                        if ($replyText === '') { $replyText = (string) $body; $previousThread = ''; }
                    @endphp
                    <div class="ec21-message-sheet"><span class="ec21-eyebrow">RESPOSTA</span><pre>{{ $replyText }}</pre></div>
                    @if ($previousThread !== '')<details class="ec21-history"><summary>Ver histórico citado no e-mail</summary><pre>{{ $previousThread }}</pre></details>@endif
                    <div class="ec21-note"><span aria-hidden="true">ⓘ</span><p>Texto armazenado de forma criptografada. Nenhum HTML remoto ou anexo é carregado.</p></div>
                @else
                    <div class="ec21-inbox-empty"><span aria-hidden="true">✉</span><h3>Mensagem pronta para consulta</h3><p>Abra apenas esta resposta pela identificação IMAP; não importamos sua caixa pessoal inteira.</p><button type="button" class="ec21-primary" wire:click="loadBody" wire:loading.attr="disabled" wire:target="loadBody">Ler mensagem completa</button><span wire:loading wire:target="loadBody">Consultando IMAP...</span></div>
                @endif
                <div class="ec21-reader-actions"><a class="ec21-outline" href="{{ route('campaigns.index') }}" wire:navigate>← Ver campanhas</a><a class="ec21-outline" href="{{ route('campaigns.replies') }}" wire:navigate>Ver outras respostas</a></div>
                @error('reply') <p class="ec21-error" role="alert">{{ $message }}</p> @enderror
            @else
                <div class="ec21-inbox-empty ec21-inbox-placeholder"><span aria-hidden="true">↩</span><h3>Selecione uma conversa</h3><p>Abra uma resposta à esquerda para conferir assunto, remetente e mensagem recebida.</p></div>
            @endif
        </section>
    </div>
</div>
