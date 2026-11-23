<x-proposals.shell :title="$title" subtitle="Escolha o cliente, serviços e volumes; salve o rascunho e revise o preço antes da emissão.">
    @php
        $servicesSelected = (array) old('service_codes', $proposal?->service_codes ?? ['LICENCIAMENTO']);
        $fmtDate = static fn($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value ?: '');
        $action = $proposal ? route('proposals.update',$proposal) : route('proposals.store');
        $fill = static fn($name, $default = '') => old($name, $proposal?->{$name} ?? $default);
    @endphp
    <div class="grid two">
        <form action="{{ $action }}" method="post" enctype="multipart/form-data" class="card">
            @csrf @if($proposal) @method('PUT') @endif
            <div class="cardhead"><div><h2>1. Cliente e responsável</h2><small>Dados mínimos do modelo ExportControl</small></div><span class="tag draft">{{ $proposal ? 'Rascunho #'.$proposal->id : 'Novo rascunho' }}</span></div>
            <div class="formgrid">
                <label class="full">Razão social *<input required name="client_name" maxlength="220" value="{{ $fill('client_name') }}" placeholder="Empresa cliente"></label>
                <label>Responsável principal<input name="client_contact" maxlength="160" value="{{ $fill('client_contact') }}" placeholder="Nome do contato"></label>
                <label>E-mail do responsável<input type="email" name="client_email" maxlength="200" value="{{ $fill('client_email') }}"></label>
                <label>CNPJ (opcional nesta etapa)<input name="client_cnpj" value="{{ $fill('client_cnpj') }}"></label>
                <label>Logo do cliente — PNG/JPG<input type="file" name="client_logo" accept="image/png,image/jpeg"><small>{{ $proposal?->client_logo_path ? 'Logo já anexado; envie outro para substituir.' : 'Obrigatório somente para a emissão do PDF.' }}</small></label>
                <label class="full">Vínculo com empresa HubSpot (somente espelho local)
                    <select name="hubspot_company_id"><option value="">Sem vínculo neste rascunho</option>
                        @foreach($companies as $company)<option value="{{ $company->id }}" @selected((string) $fill('hubspot_company_id') === (string)$company->id)>{{ $company->name }} (HubSpot {{ $company->hubspot_id }})</option>@endforeach
                        @if($proposal?->hubspot_company_id && ! $companies->contains('id', $proposal->hubspot_company_id))<option selected value="{{ $proposal->hubspot_company_id }}">Empresa vinculada #{{ $proposal->hubspot_company_id }}</option>@endif
                    </select>
                    <small><a class="link" href="{{ route('proposals.create',['cliente'=>request('cliente','')]) }}">Buscar no cadastro atual</a> — ou informe o nome no campo de busca abaixo. Nenhum dado é criado no HubSpot.</small>
                </label>
                <label class="full">Buscar empresa no espelho local
                    <input form="ecp-company-find" name="cliente" value="{{ $q }}" placeholder="Digite parte do nome e clique em Procurar" autocomplete="off">
                    <button class="btn" type="submit" form="ecp-company-find" style="margin-top:8px">Procurar empresa</button>
                </label>
                <label>Número interno da empresa (opcional)<input type="number" min="1" name="company_id" value="{{ $fill('company_id') }}"></label>
                <label>ID interno do negócio HubSpot (opcional)<input type="number" min="1" name="hubspot_deal_id" value="{{ $fill('hubspot_deal_id') }}"><small>Deve estar associado à empresa escolhida.</small></label>
            </div>
            <div class="divider"></div>
            <h2>2. Serviços e volumes</h2><p class="sub">É possível reunir mais de um serviço na mesma apresentação. Cada proposta final receberá número próprio.</p>
            <div class="options" style="margin:12px 0 15px">
                @foreach($services as $service)
                <label class="choice {{ $service->ready_to_issue ? '' : 'disabled' }}"><input type="checkbox" name="service_codes[]" value="{{ $service->service }}" @checked(in_array($service->service,$servicesSelected,true))><span><strong>{{ $service->name }}</strong><small>{{ $service->ready_to_issue ? 'Tabela e modelo oficial aprovados' : 'Cadastro/arquivo disponível; emissão ainda não habilitada' }}</small></span></label>
                @endforeach
            </div>
            <div class="formgrid">
                <label>Total de notas nos últimos 12 meses<input type="number" name="recurring_total" min="1" value="{{ $fill('recurring_total') }}"><small>Licenciamento/Acompanhamento: média = total ÷ 12 (meio para cima).</small></label>
                <label>Data-base da precificação<input type="date" required name="price_date" value="{{ $fmtDate($fill('price_date', now('America/Sao_Paulo')->toDateString())) }}"><small>Reajustes aprovados vigentes até essa data.</small></label>
                <label>Período recorrente — início<input type="date" name="recurring_from" value="{{ $fmtDate($fill('recurring_from')) }}"></label>
                <label>Período recorrente — fim<input type="date" name="recurring_to" value="{{ $fmtDate($fill('recurring_to')) }}"></label>
                <label>Total de notas retroativas<input type="number" min="1" name="retro_total" value="{{ $fill('retro_total') }}"></label>
                <label>Situação do Licenciamento<select name="license_status">@foreach(['NAO_ATIVO'=>'Não ativo','ATIVO'=>'Já está ativo','SERA_CONTRATADO_NESTE_PEDIDO'=>'Será contratado neste pedido'] as $code=>$label)<option value="{{ $code }}" @selected($fill('license_status','NAO_ATIVO') === $code)>{{ $label }}</option>@endforeach</select></label>
                <label>Período retroativas — início<input type="date" name="retro_from" value="{{ $fmtDate($fill('retro_from')) }}"></label>
                <label>Período retroativas — fim<input type="date" name="retro_to" value="{{ $fmtDate($fill('retro_to')) }}"></label>
                <label class="full">Prazo retroativas (sem inventar prazo)<input name="retro_deadline" value="{{ $fill('retro_deadline') }}" placeholder="A confirmar após validação da base"></label>
            </div>
            <div class="divider"></div>
            <h2>3. Condições comerciais</h2><p class="sub">Os documentos oficiais preservam as condições da emissão.</p>
            <div class="formgrid" style="margin-top:12px">
                <label>Validade (dias)<input type="number" min="1" max="365" required name="validity_days" value="{{ $fill('validity_days',30) }}"></label>
                <label>Pagamento (dias corridos)<input type="number" min="1" max="365" required name="payment_days" value="{{ $fill('payment_days',10) }}"></label>
                <label class="full">Responsável comercial ExportControl<input name="sales_responsible" value="{{ $fill('sales_responsible') }}" placeholder="Escolha um nome cadastrado no gerador oficial"></label>
                <label class="full">Observações da proposta<textarea name="notes" maxlength="4000" rows="4" placeholder="Esclarecimentos específicos de escopo, prazos e negociação">{{ $fill('notes') }}</textarea></label>
            </div>
            <div class="divider"></div>
            <div class="actions"><button class="btn primary" type="submit">Salvar rascunho</button><a class="btn" href="{{ route('proposals.index') }}">Voltar à lista</a></div>
            <p class="sub" style="margin-top:10px">Salvar não gera PDF, não consome número e não envia e-mail.</p>
        </form>
        <aside class="grid" style="align-content:start">
            <div class="card"><h3>Resumo para revisão</h3><div class="divider"></div>
                @if($proposal?->price_snapshot)
                    @php $quote = $proposal->price_snapshot; @endphp
                    <div class="summary"><small>Mensalidade total (com tributos)</small><strong class="amount">R$ {{ number_format((float)($quote['monthly_final']??0),2,',','.') }}</strong><div class="line"><span>Implantação + avulsos</span><b>R$ {{ number_format((float)($quote['one_time_final']??0),2,',','.') }}</b></div></div>
                    <p class="sub" style="margin-top:10px">Memória de cálculo disponível no detalhe. Atualize o rascunho para recalcular.</p>
                @else <div class="help">Informe serviços e volumes para visualizar uma cotação oficial. DF-e e NFS-e aguardam modelo e tabela aprovados.</div>@endif
            </div>
            <div class="card"><h3>Sequência e proteção</h3><div class="list" style="margin-top:12px"><div class="listrow">Rascunho <span class="tag">Sem número</span></div><div class="listrow">Revisão de dados <span class="tag">Editável</span></div><div class="listrow">Emissão <span class="tag">Gestor</span></div><div class="listrow">PDF original <span class="tag">Arquivo privado</span></div></div><p class="sub" style="margin-top:10px">A numeração global permanece bloqueada até conciliar o histórico.</p></div>
        </aside>
    </div>
    <form action="{{ request()->routeIs('proposals.edit') ? route('proposals.edit',$proposal) : route('proposals.create') }}" id="ecp-company-find" method="GET"></form>
</x-proposals.shell>
