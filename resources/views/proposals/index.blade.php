<x-proposals.shell title="Propostas Comerciais" subtitle="Crie, acompanhe e consulte todas as propostas, valores e documentos no mesmo lugar.">

@if(auth()->user() instanceof \App\Models\User && auth()->user()->isCommercialManager() && !request()->boolean('visao_original') && \Illuminate\Support\Facades\Schema::hasTable('ec_proposal_history_metadata'))
@include('proposals.historical.index')
@else

    <div class="grid kpis">
        <div class="card kpi"><label>Propostas cadastradas</label><strong>{{ number_format($counts['total'],0,',','.') }}</strong><small>Inclui histórico importado</small></div>
        <div class="card kpi"><label>Rascunhos</label><strong>{{ number_format($counts['draft'],0,',','.') }}</strong><small>Em preparação</small></div>
        <div class="card kpi"><label>Emitidas / enviadas</label><strong>{{ number_format($counts['issued'],0,',','.') }}</strong><small>Documentos salvos</small></div>
        <div class="card kpi"><label>Aceitas</label><strong>{{ number_format($counts['accepted'],0,',','.') }}</strong><small>Aceite registrado pelo gestor</small></div>
    </div>
    <section class="card">
        <div class="cardhead"><div><h2>Todas as propostas</h2><small>Histórico preservado, sem recalcular valores antigos</small></div><a href="{{ route('proposals.import.page') }}" class="btn">Importar PDF antigo</a></div>
        <form method="get" action="{{ route('proposals.index') }}" class="filters">
            <label>Cliente, número ou código<input name="q" value="{{ $search }}" placeholder="Pesquisar proposta..."></label>
            <label>Serviço<select name="service"><option value="all">Todos</option>@foreach(\App\Services\Proposals\ProposalPricing::ALL as $code)<option value="{{ $code }}" @selected($service === $code)>{{ $code }}</option>@endforeach</select></label>
            <label>Situação<select name="status"><option value="all">Todas</option>@foreach(['draft'=>'Rascunho','issued'=>'Emitida','sent'=>'Enviada','accepted'=>'Aceita','partially_accepted'=>'Aceite parcial','declined'=>'Recusada','imported'=>'Importada','generation_failed'=>'Erro de PDF'] as $key=>$label)<option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>@endforeach</select></label>
            <label>Ano<input name="year" type="number" min="2020" max="2100" placeholder="Todos" value="{{ $year }}"></label>
            <button class="btn primary" type="submit">Buscar</button>
        </form>
        <div class="divider"></div>
        <div class="table-wrap"><table><thead><tr><th>Número / código</th><th>Cliente</th><th>Serviços</th><th>Mensalidade</th><th>Situação</th><th>Atualização</th><th>Ações</th></tr></thead><tbody>
        @forelse($list as $item)
            <tr>
                <td>{{ $item->proposal_numbers ? implode(' / ', (array) $item->proposal_numbers) : ($item->legacy_code ?: 'Rascunho #'.$item->id) }}</td>
                <td><a class="link" href="{{ route('proposals.show',$item) }}">{{ $item->client_name }}</a><small style="display:block">{{ $item->client_email ?: 'Responsável não informado' }}</small></td>
                <td>{{ implode(' + ', (array) ($item->service_codes ?? [])) }}</td>
                <td class="amount">{{ $item->monthly_final !== null ? 'R$ '.number_format((float)$item->monthly_final,2,',','.') : 'A calcular' }}</td>
                <td><span class="tag {{ $item->status }}">{{ ['draft'=>'Rascunho','issued'=>'Emitida','sent'=>'Enviada','accepted'=>'Aceita','partially_accepted'=>'Aceite parcial','declined'=>'Recusada','imported'=>'Importada','generation_failed'=>'Erro de PDF','generating'=>'Gerando'][ $item->status ] ?? $item->status }}</span></td>
                <td>{{ $item->updated_at?->format('d/m/Y H:i') }}</td>
                <td><a class="link" href="{{ route('proposals.show', $item) }}">Ver detalhes</a></td>
            </tr>
        @empty <tr><td colspan="7"><div class="help">Nenhuma proposta encontrada. Crie um rascunho ou importe um documento antigo.</div></td></tr>
        @endforelse
        </tbody></table></div>
        <div class="pagination">@if($list->previousPageUrl())<a href="{{ $list->previousPageUrl() }}">Anterior</a>@endif<span class="active">{{ $list->currentPage() }} / {{ $list->lastPage() }}</span>@if($list->nextPageUrl())<a href="{{ $list->nextPageUrl() }}">Próxima</a>@endif</div>
    </section>

@endif
</x-proposals.shell>
