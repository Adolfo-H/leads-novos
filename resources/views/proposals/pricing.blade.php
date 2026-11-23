<x-proposals.shell title="Preços, impostos e numeração" subtitle="Tabelas imutáveis por versão, reajustes cumulativos e controle central de emissão.">
    @php $manager = auth()->user()?->isCommercialManager(); @endphp
    <div class="grid two">
        <div class="grid" style="align-content:start">
            <section class="card"><div class="cardhead"><div><h2>Tabelas de preços — valores líquidos</h2><small>Modelos oficiais: faixas progressivas marginais</small></div></div>
                @foreach(\App\Services\Proposals\ProposalPricing::OFFICIAL as $code)
                    @php $rows=$versions->where('service',$code)->sortByDesc('effective_from'); $current=$rows->first();$cfg=$current ? json_decode($current->configuration,true) : null; @endphp
                    <details style="margin:12px 0" @if($loop->first) open @endif>
                        <summary class="link">{{ $code }} · {{ $current?->version }} · {{ $rows->count() }} versão(ões)</summary>
                        @if($cfg)<div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Faixa</th><th>Fixo líquido</th><th>Unitário líquido</th></tr></thead><tbody>
                        @foreach($cfg['faixas'] as $tier)<tr><td>{{ $tier['min'] }}–{{ $tier['max']??'∞' }}</td><td>R$ {{ number_format((float)$tier['fixed'],2,',','.') }}</td><td>R$ {{ number_format((float)$tier['unit'],2,',','.') }}</td></tr>@endforeach
                        </tbody></table></div>@endif
                        @if($manager && $cfg)
                        <details style="margin-top:10px"><summary class="link">+ Criar NOVA versão desta tabela (aprovação)</summary>
                            <form method="POST" action="{{ route('proposals.price-version') }}" class="formgrid" style="margin-top:12px">@csrf
                                <input type="hidden" name="service" value="{{ $code }}">
                                <label>Identificação da nova versão<input required name="version" maxlength="100" placeholder="Ex.: {{ date('Y') }}-rev02"></label>
                                <label>Vigência a partir de<input required type="date" name="effective_from" min="{{ date('Y-m-d') }}" value="{{ date('Y-m-d') }}"></label>
                                @foreach($cfg['faixas'] as $i=>$tier)
                                    <label>Faixa {{ $tier['min'] }}–{{ $tier['max']??'∞' }} — fixo líquido<input required type="number" min="0" step="0.01" name="tiers[{{ $i }}][fixed]" value="{{ $tier['fixed'] }}"></label>
                                    <label>Faixa {{ $tier['min'] }}–{{ $tier['max']??'∞' }} — unitário líquido<input required type="number" min="0" step="0.01" name="tiers[{{ $i }}][unit]" value="{{ $tier['unit'] }}"></label>
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
                @if($manager)<form action="{{ route('proposals.numbering') }}" method="POST" class="formgrid" style="margin-top:12px">@csrf
                    <label class="full">Último número confirmado no histórico<input required name="last_number" type="number" min="999" placeholder="Conferir PDFs históricos antes de autorizar"></label>
                    <label class="choice full"><input required type="checkbox" value="1" name="confirmed">Conferi o histórico emitido, os números e os conflitos. Autorizo esta sequência.</label>
                    <button class="btn warn" type="submit">Conciliar e liberar numeração</button>
                </form>@endif
            </section>
            <section class="card"><h2>Tributos — gross-up</h2><p class="sub">ISS + PIS + COFINS + IBS + CBS. Calculados depois dos preços líquidos e reajustes.</p>
                <div class="table-wrap"><table><thead><tr><th>Versão</th><th>Vigência</th><th>Alíquotas</th></tr></thead><tbody>@foreach($taxes as $tax)<tr><td>{{ $tax->version }}</td><td>{{ $tax->effective_from }}</td><td>@foreach(json_decode($tax->rates,true) as $key=>$value){{ $key }} {{ $value }}%; @endforeach</td></tr>@endforeach</tbody></table></div>
                @if($manager)<div class="divider"></div><h3>Nova versão tributária aprovada</h3><form action="{{ route('proposals.tax-profile') }}" method="POST" class="formgrid" style="margin-top:12px">@csrf
                    <label class="full">Versão<input required name="version" placeholder="Ex.: 2027-01-rev01"></label>
                    <label class="full">Vigência<input required type="date" min="{{ date('Y-m-d') }}" name="effective_from"></label>
                    @foreach(['ISS','PIS','COFINS','IBS','CBS'] as $tax)<label>{{ $tax }} (%)<input name="{{ $tax }}" required type="number" step="0.01" min="0" max="99" value="0"></label>@endforeach
                    <label class="choice full"><input type="checkbox" name="approve" value="1" required>Alíquotas revisadas e aprovadas para novas propostas.</label>
                    <button class="btn primary" type="submit">Salvar versão</button>
                </form>@endif
            </section>
            <div class="card help"><h3>Outros serviços</h3><p style="margin-top:9px">DF-e e NFS-e poderão ter os valores cadastrados após aprovação dos modelos e critérios de precificação. O sistema não infere preços a partir de propostas anteriores.</p></div>
        </aside>
    </div>
</x-proposals.shell>
