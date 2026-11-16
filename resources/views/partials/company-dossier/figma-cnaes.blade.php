{{-- E09 · Manutenção de CNAEs APENAS da matriz, usando ações existentes. --}}
<section class="e53-screen" x-show="dossierTab === 'cnaes'" x-cloak aria-label="Atividades econômicas">
    <h2 class="e53-section-title">Atividade principal</h2>
    <article class="e53-panel e53-cnae-main">
        @if ($this->primaryCnae)
            <span class="e53-chip e53-chip--green">Principal</span>
            <h3>{{ $this->primaryCnae->code }}</h3>
            <p>{{ $this->primaryCnae->description ?: 'Sem descrição' }}</p>
        @else
            <h3>Não há CNAE principal cadastrado</h3>
            <p>Selecione uma atividade secundária como principal ou adicione um novo CNAE.</p>
        @endif
    </article>

    <article class="e53-panel e53-table-panel">
        <h2>Atividades secundárias</h2>
        <div class="e53-table-wrap"><table class="e53-table"><thead><tr><th>Código</th><th>Descrição</th><th>Ações</th></tr></thead><tbody>
            @forelse ($this->secondaryCnaes as $cnae)
                <tr wire:key="e53-cnae-{{ $cnae->id }}">
                    <td><strong>{{ $cnae->code }}</strong></td>
                    <td>{{ $cnae->description ?: 'Sem descrição' }}</td>
                    <td class="e53-cnae-actions">@can('manageData', $company)<button type="button" class="e53-text-link" wire:click="makeCnaePrimary({{ $cnae->id }})" wire:loading.attr="disabled">Tornar principal</button><span>·</span><button type="button" class="e53-text-link e53-text-link--danger" x-on:click="e53DeleteCnae = {{ (int) $cnae->id }}; e53DeleteCnaeLabel=@js($cnae->code)">Remover</button>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="3" class="e53-empty">Nenhuma atividade secundária cadastrada na matriz.</td></tr>
            @endforelse
        </tbody></table></div>
    </article>

    <div class="e53-origin-note"><strong>Escopo da manutenção</strong><p>Esta aba administra os CNAEs do estabelecimento de referência (matriz). O consolidado de outras unidades permanece em Empresa &amp; grupo.</p></div>
</section>
