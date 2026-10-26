{{-- E05-RESEARCH · Pesquisa de exportações. Backend existente e confirmação explícita. --}}
<div class="e53-modal-backdrop" x-show="e53ResearchOpen" x-cloak x-on:keydown.escape.window="e53ResearchOpen=false" x-on:click.self="e53ResearchOpen=false" role="presentation">
    <div class="e53-modal" role="dialog" aria-modal="true" aria-label="Pesquisar exportações">
        <button type="button" class="e53-modal-close" x-on:click="e53ResearchOpen=false">× <span>Fechar</span></button>
        <span class="e53-kicker">EMPRESAS</span><h2>Pesquisar exportações</h2>
        <p>{{ $company->corporate_name }} · solicitação de pesquisa de fontes públicas.</p>
        <div class="e53-modal-actions">
            <button class="e53-button e53-button--outline" type="button" x-on:click="e53ResearchOpen=false">Cancelar</button>
            @if ($e53ResearchConfigured && !$e53ResearchRunning && ($e53ResearchEligibility['eligible'] ?? false))
                <button class="e53-button e53-button--primary" type="button" wire:click="researchExports" wire:loading.attr="disabled" x-on:click="e53ResearchOpen=false">Confirmar pesquisa</button>
            @elseif ($e53ResearchConfigured && !$e53ResearchRunning)
                @can('forceResearchExports', $company)
                    <button class="e53-button e53-button--primary" type="button" wire:click="researchExportsForce" wire:loading.attr="disabled" x-on:click="e53ResearchOpen=false">Pesquisar manualmente</button>
                @endcan
            @else
                <button class="e53-button e53-button--primary" type="button" disabled>{{ $e53ResearchRunning ? 'Pesquisa em andamento' : 'Pesquisa indisponível' }}</button>
            @endif
        </div>
        <h3>Antes de iniciar</h3>
        <div class="e53-grid-2">
            <div class="e53-panel e53-mini"><span class="e53-chip e53-chip--green">{{ $e53ResearchConfigured ? 'Configurado' : 'Desativado' }}</span><h3>Provedor disponível</h3><p>{{ $e53ResearchConfigured ? 'Pesquisa externa habilitada neste ambiente.' : 'Nenhum provedor está configurado para pesquisa externa.' }}</p></div>
            <div class="e53-panel e53-mini"><span class="e53-chip {{ ($e53ResearchEligibility['eligible'] ?? false) ? 'e53-chip--green' : 'e53-chip--amber' }}">{{ ($e53ResearchEligibility['eligible'] ?? false) ? 'Elegível' : 'Verificação necessária' }}</span><h3>Empresa elegível</h3><p>{{ $e53ResearchEligibility['message'] ?? 'Critérios de pesquisa não disponíveis.' }}</p></div>
        </div>
        <div class="e53-notice e53-notice--amber"><strong>Uma execução assíncrona</strong><p>O pedido entra na fila e pode consumir créditos do provedor. A navegação continua disponível durante o processamento.</p></div>
        <article class="e53-panel e53-table-panel"><h3>Estados de acompanhamento</h3><div class="e53-table-wrap"><table class="e53-table"><thead><tr><th>Estado</th><th>Como será apresentado</th></tr></thead><tbody><tr><td>Na fila</td><td>Pedido aguardando execução</td></tr><tr><td>Em execução</td><td>Consulta em andamento</td></tr><tr><td>Concluída</td><td>Evidências e limites da conclusão</td></tr><tr><td>Falhou</td><td>Motivo registrado para recuperação</td></tr></tbody></table></div></article>
        @error('exportResearch')<p class="e53-error">{{ $message }}</p>@enderror
    </div>
</div>

{{-- E08-UNIT · Um único modal Alpine, inclusive quando o grupo tem milhares de filiais.
     O JSON contém somente os campos necessários, sem objetos Eloquent completos. --}}
@php
    $e53UnitMap = $company->establishments->take(20)->mapWithKeys(fn ($unit) => [(string) $unit->id => [
        'title' => ($unit->type === 'matrix' ? 'Matriz' : 'Filial').' · '.($unit->fantasy_name ?: $unit->municipality_name ?: $unit->cnpj),
        'cnpj' => \App\Support\Cnpj::format($unit->cnpj),
        'type' => $unit->type === 'matrix' ? 'Matriz' : 'Filial',
        'location' => ($unit->municipality_name ?: '—').' / '.($unit->state ?: '—'),
        'status' => $unit->registration_status ?: 'Não informada',
        'email' => $unit->email ?: '—',
        'phone' => collect([$unit->phone_1, $unit->phone_2])->filter()->implode(' · ') ?: '—',
        'address' => collect([$unit->address_type, $unit->street, $unit->number, $unit->neighborhood])->filter()->implode(' ') ?: 'Não informado',
        'zip' => $unit->zip_code ?: '—',
    ]]);
@endphp
<div class="e53-modal-backdrop" x-data="{units: @js($e53UnitMap)}" x-show="e53SelectedUnit !== null" x-cloak x-on:keydown.escape.window="e53SelectedUnit=null" x-on:click.self="e53SelectedUnit=null">
    <div class="e53-modal" role="dialog" aria-modal="true" aria-label="Dados do estabelecimento">
        <button type="button" class="e53-modal-close" x-on:click="e53SelectedUnit=null">× <span>Fechar</span></button>
        <span class="e53-kicker">EMPRESA &amp; GRUPO</span>
        <h2 x-text="units[e53SelectedUnit]?.title || 'Unidade'">Unidade</h2>
        <p x-text="units[e53SelectedUnit]?.cnpj || 'CNPJ não informado'"></p>
        <div class="e53-fields-grid e53-modal-fields">
            <div class="e53-field"><span>CNPJ</span><strong x-text="units[e53SelectedUnit]?.cnpj || '—'"></strong></div>
            <div class="e53-field"><span>Tipo</span><strong x-text="units[e53SelectedUnit]?.type || '—'"></strong></div>
            <div class="e53-field"><span>Cidade / UF</span><strong x-text="units[e53SelectedUnit]?.location || '—'"></strong></div>
            <div class="e53-field"><span>Situação cadastral</span><strong x-text="units[e53SelectedUnit]?.status || '—'"></strong></div>
            <div class="e53-field"><span>E-mail cadastral</span><strong x-text="units[e53SelectedUnit]?.email || '—'"></strong></div>
            <div class="e53-field"><span>Telefone</span><strong x-text="units[e53SelectedUnit]?.phone || '—'"></strong></div>
            <div class="e53-field"><span>Endereço</span><strong x-text="units[e53SelectedUnit]?.address || '—'"></strong></div>
            <div class="e53-field"><span>CEP</span><strong x-text="units[e53SelectedUnit]?.zip || '—'"></strong></div>
        </div>
        <button type="button" class="e53-button e53-button--outline" x-on:click="e53SelectedUnit=null">Voltar às unidades</button>
    </div>
</div>

{{-- E09-ADD · usa o addCnae do Volt, sujeito à policy manageData. --}}
@can('manageData', $company)
<div class="e53-modal-backdrop" x-show="e53AddCnaeOpen" x-cloak x-on:keydown.escape.window="e53AddCnaeOpen=false" x-on:click.self="e53AddCnaeOpen=false" x-on:e53-cnae-saved.window="e53AddCnaeOpen=false">
    <div class="e53-modal e53-modal--compact" role="dialog" aria-modal="true" aria-label="Adicionar CNAE">
        <button type="button" class="e53-modal-close" x-on:click="e53AddCnaeOpen=false">× <span>Fechar</span></button><span class="e53-kicker">ATIVIDADES ECONÔMICAS</span><h2>Adicionar CNAE</h2><p>Vincule uma nova atividade à matriz cadastrada.</p>
        <form wire:submit="addCnae" class="e53-form">
            <label for="e53-cnae-code">Código CNAE</label><input id="e53-cnae-code" wire:model="newCnaeCode" maxlength="20" placeholder="Ex.: 4622200" required>@error('newCnaeCode')<small class="e53-error">{{ $message }}</small>@enderror
            <label for="e53-cnae-description">Descrição</label><input id="e53-cnae-description" wire:model="newCnaeDescription" maxlength="255" placeholder="Ex.: Comércio atacadista de soja">@error('newCnaeDescription')<small class="e53-error">{{ $message }}</small>@enderror
            <label class="e53-checkbox"><input type="checkbox" wire:model="newCnaePrimary"> Definir como CNAE principal da matriz</label>
            <div class="e53-modal-actions"><button type="button" class="e53-button e53-button--outline" x-on:click="e53AddCnaeOpen=false">Cancelar</button><button type="submit" class="e53-button e53-button--primary" wire:loading.attr="disabled" wire:target="addCnae">Salvar CNAE</button></div>
        </form>
    </div>
</div>

{{-- E09-REMOVE · confirmação explícita antes de excluir. --}}
<div class="e53-modal-backdrop" x-show="e53DeleteCnae !== null" x-cloak x-on:keydown.escape.window="e53DeleteCnae=null" x-on:click.self="e53DeleteCnae=null">
    <div class="e53-modal e53-modal--compact" role="dialog" aria-modal="true" aria-label="Remover CNAE">
        <button type="button" class="e53-modal-close" x-on:click="e53DeleteCnae=null">× <span>Fechar</span></button><span class="e53-kicker">ATIVIDADES ECONÔMICAS</span><h2>Remover CNAE</h2>
        <p>Você vai remover o CNAE <strong x-text="e53DeleteCnaeLabel"></strong> da matriz. Essa ação altera o cadastro local.</p>
        <div class="e53-modal-actions"><button type="button" class="e53-button e53-button--outline" x-on:click="e53DeleteCnae=null">Cancelar</button>
            <button type="button" class="e53-button e53-button--danger" wire:loading.attr="disabled" x-on:click="if (Number.isInteger(e53DeleteCnae)) { $wire.removeCnae(e53DeleteCnae); e53DeleteCnae=null; }">Confirmar remoção</button>
        </div>
    </div>
</div>

@endcan

{{-- E06-PROGRESS · usa o estado já existente no backend do HubSpot. --}}
@if ($showHubSpotOpportunityProgress)
    @php $e53Progress = $this->hubSpotManualOpportunityState; @endphp
    <div class="e53-modal-backdrop e53-modal-backdrop--server" @if(($e53Progress['active'] ?? false)) wire:poll.2s="refreshHubSpotOpportunity" @endif>
        <div class="e53-modal e53-modal--compact" role="dialog" aria-modal="true" aria-label="Criação de oportunidade no HubSpot">
            <span class="e53-kicker">HUBSPOT</span><h2>Criação de oportunidade</h2>
            <p>{{ $e53Progress['message'] ?? 'Consultando processamento no HubSpot.' }}</p>
            <div class="e53-progress"><i style="width:{{ min(100, max(0, (int) ($e53Progress['progress'] ?? 0))) }}%"></i></div>
            <p>{{ (int) ($e53Progress['progress'] ?? 0) }}% · {{ $e53Progress['progress_message'] ?? 'Aguardando atualização' }}</p>
            @if (!empty($e53Progress['error']))<div class="e53-notice e53-notice--amber">{{ $e53Progress['error'] }}</div>@endif
            <div class="e53-modal-actions"><button type="button" class="e53-button e53-button--outline" wire:click="refreshHubSpotOpportunity">Atualizar</button><button type="button" class="e53-button e53-button--primary" wire:click="closeHubSpotOpportunityProgress">Fechar</button></div>
        </div>
    </div>
@endif
