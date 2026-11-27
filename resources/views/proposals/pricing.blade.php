{{-- EC_PROPOSALS_V27 --}}
<x-proposals.shell title="Preços, impostos e numeração" subtitle="Tabelas imutáveis por versão, reajustes cumulativos e controle central de emissão.">
    @php $manager = auth()->user()?->isCommercialManager(); @endphp
    <div class="grid two">
        <div class="grid" style="align-content:start">
            <section class="card"><div class="cardhead"><div><h2>Tabelas de preços — valores líquidos</h2><small>Valores-base imutáveis e líquidos vigentes em {{ \Illuminate\Support\Carbon::parse($priceDate)->format('d/m/Y') }}. Tributos calculados separadamente.</small></div></div>
                <p class="sub" style="margin-top:8px">Reajustes aplicados: @forelse($priceTables['adjustment_audit']['reajustes_aplicados'] as $event) <b>+{{ number_format((float)$event['percentual'],2,',','.') }}%</b> desde {{ \Illuminate\Support\Carbon::parse($event['vigencia_inicio'])->format('d/m/Y') }}; @empty nenhum vigente @endforelse</p>
                @foreach(\App\Services\Proposals\ProposalPricing::OFFICIAL as $code)
                    @php $rows=$versions->where('service',$code); $current=$rows->where('approved',true)->where('effective_from','<=',$priceDate)->sortByDesc('effective_from')->first(); $cfg=$current ? json_decode($current->configuration,true) : null; $effective=$priceTables['effective'][$code]??null; $minimum=$priceTables['minimums'][$code]??null; @endphp
                    <details style="margin:12px 0" @if($loop->first) open @endif>
                        <summary class="link">{{ $code }} · {{ $current?->version }} · {{ $rows->count() }} versão(ões)</summary>
                        @if($cfg && $effective)
                        <p class="sub" style="margin-top:8px">{{ $code==='DFE' ? 'Fixo por faixa de CNPJs (uma única faixa)' : ($code==='RETROATIVAS' ? 'Faixas marginais por volume total do período' : 'Faixas progressivas marginais por média mensal') }}</p>
                        <div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Faixa</th>@if($code==='DFE')<th>XMLs ref.</th>@endif<th>Fixo base</th><th>Fixo vigente</th>@if($code!=='DFE')<th>Unitário base</th><th>Unitário vigente</th>@endif</tr></thead><tbody>
                        @foreach($cfg['faixas'] as $i=>$tier)
                        @php $et=$effective['faixas'][$i]; @endphp
                        <tr><td>{{ number_format($tier['min'],0,',','.') }}–{{ $tier['max']===null?'∞':number_format($tier['max'],0,',','.') }}</td>@if($code==='DFE')<td>{{ number_format($tier['xml_reference'],0,',','.') }}</td>@endif<td>R$ {{ number_format((float)$tier['fixed'],2,',','.') }}</td><td><b>R$ {{ number_format((float)$et['fixed'],2,',','.') }}</b></td>@if($code!=='DFE')<td>R$ {{ number_format((float)$tier['unit'],2,',','.') }}</td><td><b>R$ {{ number_format((float)$et['unit'],2,',','.') }}</b></td>@endif</tr>
                        @endforeach
                        </tbody></table></div>
                        @if($code==='RETROATIVAS')<p class="sub">Implantação líquida condicional: R$ {{ number_format((float)$effective['implantacao_sem_licenciamento']['valor_liquido'],2,',','.') }} (sem Licenciamento ativo ou conjunto).</p>@endif
                        @if($code==='DFE')<p class="sub">XMLs de referência: periodicidade e excedentes a definir na contratação. Não são multiplicadores do preço.</p>@endif
                        @if($minimum)<p class="sub" style="margin-top:8px">Primeira faixa líquida: R$ {{ number_format((float)$minimum['net'],2,',','.') }}. O valor final é calculado na proposta, com o perfil tributário da data-base.</p>@endif
                        @else <p class="sub">Sem tabela aprovada vigente na data-base.</p>@endif
                        @if($manager && $cfg)
                        <details style="margin-top:10px"><summary class="link">+ Criar NOVA versão desta tabela-base (aprovação)</summary>
                            <form method="POST" action="{{ route('proposals.price-version') }}" class="formgrid" style="margin-top:12px">@csrf
                                <input type="hidden" name="service" value="{{ $code }}"><p class="sub full">Informe valores-base líquidos, antes dos reajustes cadastrados; não grave preços reajustados ou tributados nesta tabela para evitar duplicidade.</p>
                                <label>Identificação da nova versão<input required name="version" maxlength="100" placeholder="Ex.: {{ date('Y') }}-rev02"></label>
                                <label>Vigência a partir de<input required type="date" name="effective_from" min="{{ date('Y-m-d') }}" value="{{ date('Y-m-d') }}"></label>
                                @foreach($cfg['faixas'] as $i=>$tier)
                                    <label>Faixa {{ $tier['min'] }}–{{ $tier['max']??'∞' }} — fixo líquido<input required type="number" min="0" step="0.01" name="tiers[{{ $i }}][fixed]" value="{{ $tier['fixed'] }}"></label>
                                    @if($code==='DFE')<input type="hidden" name="tiers[{{ $i }}][unit]" value="0">@else<label>Faixa {{ $tier['min'] }}–{{ $tier['max']??'∞' }} — unitário líquido<input required type="number" min="0" step="0.01" name="tiers[{{ $i }}][unit]" value="{{ $tier['unit'] }}"></label>@endif
                                @endforeach
                                @if($code==='RETROATIVAS')<label>Implantação líquida sem Licenciamento<input required step="0.01" min="0" type="number" name="special_implementation" value="{{ $cfg['implantacao_sem_licenciamento']['valor_liquido'] }}"></label>@endif
                                <label class="choice full"><input type="checkbox" name="approve" value="1" required>Confirmo a aprovação comercial desta nova versão, sem alterar as anteriores.</label>
                                <button class="btn primary" type="submit">Salvar nova versão aprovada</button>
                            </form>
                        </details>
                        @endif
                    </details>
                    <div class="divider"></div>
                @endforeach
            </section>
            <section class="card"><h2>Reajustes comerciais cumulativos</h2><p class="sub">Cada evento é aplicado sucessivamente sobre as faixas líquidas, com arredondamento a centavos em cada etapa. Não modifica PDFs já emitidos.</p>
                <div class="table-wrap" style="margin-top:12px"><table><thead><tr><th>Vigência</th><th>Percentual</th><th>Evento</th><th>Justificativa</th></tr></thead><tbody>@foreach($adjustments as $event)<tr><td>{{ $event->effective_from }}</td><td>+{{ number_format((float)$event->percentage,2,',','.') }}%</td><td>{{ $event->event_key }}</td><td>{{ $event->reason }}</td></tr>@endforeach</tbody></table></div>
                @if($manager)<div class="divider"></div><h3>Adicionar reajuste aprovado</h3><form method="POST" action="{{ route('proposals.adjustment') }}" class="formgrid" style="margin-top:12px">@csrf
                    <label>ID único de evento<input required name="event_key" maxlength="100" placeholder="EC-REAJUSTE-2027-..."></label>
                    <label>Início da vigência<input required type="date" name="effective_from" min="{{ date('Y-m-d') }}"></label>
                    <label>Percentual (%)<input required type="number" step="0.01" min="0.01" max="100" name="percentage"></label>
                    <label class="full">Justificativa<textarea required name="reason" minlength="10"></textarea></label>
                    <label class="choice full"><input type="checkbox" name="approve" required value="1">Confirmo decisão comercial para novas propostas.</label><button type="submit" class="btn primary">Registrar reajuste</button>
                </form>@endif
            </section>
        </div>
        <aside class="grid" style="align-content:start">
            <section class="card"><h2>Numeração global</h2><p class="sub">Sequência única para todos os serviços, sem reiniciar por ano. Cada rascunho não possui número.</p>
                <div class="summary" style="margin-top:12px"><span class="eyebrow">PRÓXIMO NÚMERO</span><strong>{{ $numbering?->reconciled_at ? $numbering->next_number : 'BLOQUEADO' }}</strong><small style="display:block">{{ $numbering?->reconciled_at ? 'Histórico conciliado pelo gestor' : 'Histórico ainda não conciliado' }}</small></div>
                @if($manager && ! $numbering?->reconciled_at)<form action="{{ route('proposals.numbering') }}" method="POST" class="formgrid" style="margin-top:12px">@csrf
                    <label class="full">Último número confirmado no histórico<input required name="last_number" type="number" min="999" placeholder="Conferir PDFs históricos antes de autorizar"></label>
                    <label class="choice full"><input required type="checkbox" value="1" name="confirmed">Conferi o histórico emitido, os números e os conflitos. Autorizo esta sequência.</label>
                    <button class="btn warn" type="submit">Conciliar e liberar numeração</button>
                </form>@endif
            </section>
            <section class="card"><h2>Tributos — gross-up</h2><p class="sub">ISS + PIS + COFINS + IBS + CBS. Calculados depois dos preços líquidos e reajustes.</p><div class="summary" style="margin-top:10px"><small>Alíquota total vigente em {{ $priceDate }}</small><strong>{{ number_format(array_sum($priceTables['tax_rates']),2,',','.') }}%</strong><p class="sub">Preço final = líquido ÷ (1 − alíquota total). A tabela acima não inclui tributos.</p></div>
                <div class="table-wrap"><table><thead><tr><th>Versão</th><th>Vigência</th><th>Alíquotas</th></tr></thead><tbody>@foreach($taxes as $tax)<tr><td>{{ $tax->version }}</td><td>{{ $tax->effective_from }}</td><td>@foreach(json_decode($tax->rates,true) as $key=>$value){{ $key }} {{ $value }}%; @endforeach</td></tr>@endforeach</tbody></table></div>
                @if($manager)<div class="divider"></div><h3>Nova versão tributária aprovada</h3><form action="{{ route('proposals.tax-profile') }}" method="POST" class="formgrid" style="margin-top:12px">@csrf
                    <label class="full">Versão<input required name="version" placeholder="Ex.: 2027-01-rev01"></label>
                    <label class="full">Vigência<input required type="date" min="{{ date('Y-m-d') }}" name="effective_from"></label>
                    @foreach(['ISS','PIS','COFINS','IBS','CBS'] as $tax)<label>{{ $tax }} (%)<input name="{{ $tax }}" required type="number" step="0.01" min="0" max="99" value="0"></label>@endforeach
                    <label class="choice full"><input type="checkbox" name="approve" value="1" required>Alíquotas revisadas e aprovadas para novas propostas.</label>
                    <button class="btn primary" type="submit">Salvar versão</button>
                </form>@endif
            </section>

        </aside>
    </div>
</x-proposals.shell>
