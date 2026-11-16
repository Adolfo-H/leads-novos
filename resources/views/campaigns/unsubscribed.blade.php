{{-- V77 — registros oficiais de opt-out, somente leitura e restritos ao gestor. --}}
@php $ec77Suppressed = $this->unsubscribed; @endphp
<section class="ec77-unsubscribed-view" aria-label="Lista de e-mails descadastrados" data-campaign-unsubscribed-v77>
    <header class="ec77-unsubscribed-heading">
        <div>
            <h2>E-mails descadastrados</h2>
            <p>Endereços que solicitaram descadastro. O sistema impede novos envios comerciais para eles.</p>
        </div>
        <span class="ec77-unsubscribed-total">{{ number_format($ec77Suppressed->total(), 0, ',', '.') }} descadastrado(s)</span>
    </header>
    <div class="ec77-unsubscribed-table-scroll">
        <table class="ec77-unsubscribed-table">
            <thead><tr><th>E-mail</th><th>Motivo</th><th>Registrado em</th></tr></thead>
            <tbody>
                @forelse ($ec77Suppressed as $ec77Address)
                    <tr wire:key="unsub-v77-{{ $ec77Address->id }}">
                        <td>{{ $ec77Address->normalized_email }}</td>
                        <td><span class="ec77-unsub-pill">Descadastro solicitado</span></td>
                        <td>{{ $ec77Address->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? 'Data não registrada' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Nenhum e-mail descadastrado {{ $search !== '' ? 'encontrado nesta pesquisa' : 'registrado' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($ec77Suppressed->hasPages())
        <nav class="ec77-page-actions" aria-label="Paginação de e-mails descadastrados">
            <span>Página {{ $ec77Suppressed->currentPage() }} de {{ $ec77Suppressed->lastPage() }}</span>
            <div>
                <button type="button" wire:click="changeUnsubscribedPage({{ $ec77Suppressed->currentPage() - 1 }})" @disabled($ec77Suppressed->onFirstPage())>Anterior</button>
                <button type="button" wire:click="changeUnsubscribedPage({{ $ec77Suppressed->currentPage() + 1 }})" @disabled(! $ec77Suppressed->hasMorePages())>Próxima</button>
            </div>
        </nav>
    @endif
</section>
