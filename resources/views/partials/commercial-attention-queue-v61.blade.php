{{-- L04 / V61 — Fila gerencial baseada exclusivamente em managementAttentionQueue. --}}
<section class="ecgm-panel ecgm61-attention-panel ecgm-attention-queue" aria-labelledby="ecgm-attention-title">
    <header class="ecgm-panel-head ecgm61-panel-heading">
        <div>
            <span class="ecgm-eyebrow">FILA GERENCIAL</span>
            <h2 id="ecgm-attention-title">Empresas que precisam de atenção</h2>
            <p>Retornos atrasados, empresas sem acompanhamento e prioridades comerciais.</p>
        </div>
        <span class="ecgm61-count-pill">{{ number_format(count($attentionQueue), 0, ',', '.') }} na fila</span>
    </header>

    <div class="ecgm61-table-wrap" tabindex="0" role="region" aria-label="Fila de atenção da equipe">
        <table class="ecgm61-table ecgm61-attention-table">
            <thead>
                <tr>
                    <th scope="col">Empresa</th>
                    <th scope="col">Motivo</th>
                    <th scope="col">SDR</th>
                    <th scope="col">Responsável</th>
                    <th scope="col">Próxima ação</th>
                    <th scope="col">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attentionQueue as $attention)
                    <tr wire:key="ecgm61-attention-{{ $attention['company_id'] }}">
                        <th scope="row" class="ecgm61-attention-company">
                            {{ $attention['company_name'] }}
                        </th>
                        <td>
                            <span class="ecgm61-reason is-{{ $attention['reason_key'] }}">
                                {{ $attention['reason'] }}
                            </span>
                        </td>
                        <td><strong>{{ number_format((int) $attention['score'], 0, ',', '.') }}</strong></td>
                        <td>{{ $attention['owner_name'] }}</td>
                        <td class="ecgm61-action-detail" title="{{ $attention['detail'] }}">{{ $attention['detail'] }}</td>
                        <td class="ecgm61-row-actions">
                            <a href="{{ route('leads.index', $attention['filters']) }}" wire:navigate>Ver fila</a>
                            <a href="{{ route('companies.show', $attention['company_id']) }}" wire:navigate>Abrir dossiê</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="ecgm61-empty-cell">
                            <div class="ecgm-attention-empty">
                                <strong>Nenhuma pendência crítica agora</strong>
                                <span>Não há registros na fila gerencial de atenção deste momento.</span>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <footer class="ecgm61-table-footer">
        <span>Exibindo até {{ number_format(count($attentionQueue), 0, ',', '.') }} empresas priorizadas.</span>
        <a href="{{ route('leads.index') }}" wire:navigate>Ver todos os leads <span aria-hidden="true">→</span></a>
    </footer>
</section>
