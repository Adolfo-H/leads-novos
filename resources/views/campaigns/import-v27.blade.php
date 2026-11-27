<x-layouts::app :title="'Importar destinatários'">
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-wizard.css') }}?v=93.2">
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-import-v27.css') }}?v=27.3">
    <main class="v93-page ec-import-v27" data-campaign-import-v27>
        <header class="v93-head">
            <div><small class="v93-kicker">CAMPANHAS DE E-MAIL / CRIAÇÃO</small><h1>Nova campanha</h1><p>Importe os destinatários, escreva a mensagem e revise antes de salvar.</p></div>
            <a class="v93-btn v93-btn-quiet" href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId]) }}">← Voltar à seleção</a>
        </header>
        <nav class="v93-stepper" aria-label="Etapas de criação">
            <span class="active"><b>1</b><span>Selecionar contatos</span></span>
            <span><b>2</b><span>Escrever mensagem</span></span>
            <span><b>3</b><span>Ritmo e revisão</span></span>
        </nav>
        @if ($errors->any())
            <div class="v93-alert v93-alert-error" role="alert"><strong>Confira a importação</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if (session('import_notice'))<div class="v93-alert" role="status">{{ session('import_notice') }}</div>@endif
        <div class="ec-import-layout">
            <div class="ec-import-main">
                <section class="v93-card">
                    <div class="v93-card-head"><div><h2>Importar destinatários</h2><p>Adicione contatos externos sem cadastrar no HubSpot.</p></div></div>
                    <nav class="ec-import-tabs" aria-label="Origem dos contatos">
                        @foreach (['all'=>'Todos','hubspot'=>'HubSpot','manual'=>'Cadastro manual','receita'=>'Receita Federal'] as $origin=>$title)
                            <a href="{{ route('campaigns.wizard-v93',['wizard_id'=>$wizardId,'origem'=>$origin]) }}">{{ $title }}</a>
                        @endforeach
                        <span aria-current="page">⇧ Importar lista</span>
                    </nav>
                    <form method="POST" action="{{ route('campaigns.import-v27.upload') }}" enctype="multipart/form-data" class="v93-editor" data-ec-import-upload>
                        @csrf
                        <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                        <div><strong class="ec-import-label">Formato da importação</strong>
                            <div class="ec-import-formats" role="group" aria-label="Escolher formato">
                                @foreach ([['sheet','▤','Excel / CSV','.xlsx, .xls, .csv'],['word','W','Word','.docx, .doc'],['pdf','PDF','PDF','PDF com texto'],['paste','≡','Colar e-mails','Ctrl + V / TXT']] as [$key,$symbol,$title,$ext])
                                    <button class="ec-import-format" type="button" data-import-format="{{ $key }}" aria-pressed="{{ $key === 'sheet' ? 'true' : 'false' }}"><span class="ec-import-format-icon">{{ $symbol }}</span><span><strong>{{ $title }}</strong><small>{{ $ext }}</small></span></button>
                                @endforeach
                            </div>
                        </div>
                        <label>Origem da lista <input name="source_label" required maxlength="240" placeholder="Ex.: formulário de inscrição, evento ou lista de clientes autorizados"></label>
                        <div class="ec-import-upload" data-import-file-area>
                            <label><span>Selecionar arquivo (até 8 MB)</span><input name="import_file" type="file" accept=".csv,.xlsx,.xls,.docx,.doc,.pdf,.txt"></label>
                            <small>Arraste para o seletor de arquivos ou clique para escolher. Nenhum arquivo é enviado ao HubSpot.</small>
                        </div>
                        <label data-import-paste-area>Ou cole os endereços<textarea name="paste" rows="5" maxlength="262144" placeholder="Cole a lista aqui, um e-mail por linha ou separados por vírgulas."></textarea></label>
                        <div class="ec-import-actions"><small>Até 5.000 linhas na prévia; o limite da campanha permanece o configurado.</small><button type="submit" class="v93-btn v93-btn-primary">Ler e revisar arquivo →</button></div>
                    </form>
                </section>
                @if ($preview !== null && $result !== null)
                    <section class="v93-card ec-import-review">
                        <div class="ec-import-file-info"><span class="ec-import-format-icon">▤</span><div><strong>{{ $preview['file'] }}</strong><small>{{ $preview['source'] }} · Prévia e revisões temporárias por 30 minutos</small></div><form method="POST" action="{{ route('campaigns.import-v27.discard') }}">@csrf<input type="hidden" name="wizard_id" value="{{ $wizardId }}"><button type="submit" class="v93-btn">Descartar prévia</button></form></div>
                        <h3>Mapeamento de colunas</h3><p class="ec-import-muted">Confirme de onde vêm os dados. Campos opcionais podem ficar vazios.</p>
                        <form method="POST" action="{{ route('campaigns.import-v27.mapping') }}" class="v93-editor">
                            @csrf<input type="hidden" name="wizard_id" value="{{ $wizardId }}"><input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                            <div class="ec-import-map">
                                @foreach (\App\Services\Campaigns\CampaignImportReview::FIELDS as $key=>$title)
                                    <label>{{ $title }}<select name="mapping[{{ $key }}]" @if($key==='email') required @endif><option value="-1">{{ $key==='email' ? 'Selecione' : 'Não importar' }}</option>@foreach ($preview['table']['columns'] as $i=>$column)<option value="{{ $i }}" @selected(($preview['mapping'][$key]??-1)===$i)>{{ $column }} ({{ $i+1 }})</option>@endforeach</select></label>
                                @endforeach
                            </div>
                            <div class="ec-import-actions"><small>Excel: primeira planilha. Word/PDF: somente e-mails do texto.</small><button class="v93-btn" type="submit">Aplicar mapeamento</button></div>
                        </form>
                        <div class="ec-import-metrics">
                            @foreach ([[count($preview['table']['rows']),'Linhas lidas'],[count($result['contacts']),'E-mails distintos'],[$result['personal'],'Pessoais'],[$result['business'],'Corporativos informados'],[$result['unknown'],'Não classificados'],[count($blocked),'Bloqueados'],[$result['duplicates'],'Duplicados'],[$result['invalid'],'Inválidos']] as [$number,$label])
                                <div><strong>{{ number_format($number,0,',','.') }}</strong><small>{{ $label }}</small></div>
                            @endforeach
                        </div>
                        <div class="ec-import-notice"><strong>Revisão de autorização de contato</strong><p>Endereço encontrado não significa permissão para enviar campanhas. Você pode marcar o arquivo inteiro para revisão. Registre a referência individual de autorização de comunicação comercial antes de adicionar cada destinatário à campanha. A classificação do arquivo ou domínio é indicativa, não comprova permissão. Descadastrados e duplicados não podem ser adicionados.</p></div>
                        <div class="ec-import-list-head"><h3>Prévia dos destinatários</h3><span>Página {{ $page }} de {{ $pageCount }}</span></div>
                        <div class="ec-import-bulk-summary" data-import-reviewed-summary>
                            <div><strong>{{ number_format($reviewedCount, 0, ',', '.') }} revisado(s) disponível(is)</strong><small>Em todas as páginas da prévia, entre os contatos marcados. A marcação e as referências em edição são preservadas ao navegar.</small></div>
                            <form method="POST" action="{{ route('campaigns.import-v27.add') }}" class="ec-import-bulk-form">
                                @csrf
                                <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                                <input type="hidden" name="action" value="add_reviewed">
                                <label class="ec-import-confirm"><input type="checkbox" name="permission_confirmed" value="1" required> Confirmo as revisões individuais guardadas.</label>
                                <button type="submit" class="v93-btn v93-btn-primary" @disabled($reviewedCount === 0 || $reviewedCount + $selectedCount > $cap)>Adicionar todos os revisados</button>
                            </form>
                            @if ($reviewedCount + $selectedCount > $cap)
                                <p class="ec-import-bulk-warning" role="status">A seleção ultrapassaria o limite de {{ number_format($cap, 0, ',', '.') }}. Desmarque os contatos excedentes e guarde a seleção. Nenhum contato será cortado automaticamente.</p>
                            @endif
                        </div>
                        @if ($contacts === [])
                            <p class="v93-note">Nenhum e-mail válido encontrado. Confira o mapeamento ou carregue um arquivo com texto selecionável.</p>
                        @else
                            <form method="POST" action="{{ route('campaigns.import-v27.add') }}" class="v93-editor" data-ec-import-review>
                                @csrf<input type="hidden" name="wizard_id" value="{{ $wizardId }}"><input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                                <input type="hidden" name="page" value="{{ $page }}">
                                <div class="ec-import-global-select" data-import-global-selection>
                                    <div><strong>{{ number_format($markedCount, 0, ',', '.') }} de {{ number_format($eligibleCount, 0, ',', '.') }} marcados no arquivo inteiro</strong><small>Inclui todas as páginas. A marcação não aprova nem envia e-mails.</small></div>
                                    <div class="ec-import-review-actions">
                                        <button type="submit" name="action" value="mark_all" formnovalidate class="v93-btn v93-btn-primary" @disabled($eligibleCount === 0)>Selecionar todos do arquivo ({{ number_format($eligibleCount, 0, ',', '.') }})</button>
                                        <button type="submit" name="action" value="clear_marks" formnovalidate class="v93-btn">Limpar seleção do arquivo</button>
                                    </div>
                                </div>
                                @if ($markedCount + $selectedCount > $cap)
                                    <p class="ec-import-bulk-warning" role="status">A marcação pode incluir a lista inteira para revisão, mas a inclusão na campanha continua limitada a {{ number_format($cap, 0, ',', '.') }} destinatários. Nenhum contato é cortado automaticamente.</p>
                                @endif
                                <div class="ec-import-selection-bar">
                                    <label class="ec-import-select-all"><input type="checkbox" data-import-select-all aria-label="Selecionar todos da página"> <strong>Selecionar todos da página</strong></label>
                                    <span data-import-selected-count aria-live="polite">0 marcados</span>
                                    <small>Use os botões de paginação abaixo para guardar a marcação e o texto digitado, sem aprovar os contatos.</small>
                                </div>
                                <div class="v93-table-scroll"><table class="v93-table ec-import-table"><thead><tr><th>Marcar</th><th>Contato / e-mail</th><th>Empresa / cargo</th><th>Situação</th><th>Referência de permissão</th></tr></thead><tbody>
                                    @foreach ($contacts as $contact)
                                        @php $isBlocked = isset($blocked[$contact['email']]); $isChosen = isset($chosen[$contact['email']]); $disabled = $isBlocked || $isChosen; @endphp
                                        <tr>
                                            <td><input type="checkbox" name="selected[]" value="{{ $contact['index'] }}" aria-label="Selecionar {{ $contact['email'] }}" @disabled($disabled) @checked(! $disabled && isset($marks[$contact['index']])) data-import-select></td>
                                            <td><strong>{{ $contact['name'] ?: 'Nome não informado' }}</strong><small>{{ $contact['email'] }}</small><small>{{ ['personal'=>'Pessoal','business'=>'Corporativo informado','unknown'=>'Tipo não classificado'][$contact['type']] }}</small></td>
                                            <td>{{ $contact['company'] ?: '—' }}<small>{{ $contact['job'] ?: '—' }}</small></td>
                                            <td><span class="ec-import-state {{ $isBlocked ? 'is-blocked' : ($isChosen ? 'is-added' : '') }}">{{ $isBlocked ? 'Bloqueado' : ($isChosen ? 'Já selecionado' : (isset($reviewed[$contact['index']]) ? 'Revisão guardada' : 'Revisar autorização')) }}</span></td>
                                            <td>@unless($disabled)<input class="ec-import-proof" name="evidence[{{ $contact['index'] }}]" maxlength="500" aria-label="Referência de permissão de {{ $contact['email'] }}" placeholder="Registro de aceite / referência" value="{{ $draftEvidence[$contact['index']] ?? $reviewed[$contact['index']] ?? '' }}" data-import-proof>@else — @endunless</td>
                                        </tr>
                                    @endforeach
                                </tbody></table></div>
                                <label class="ec-import-confirm"><input type="checkbox" name="permission_confirmed" value="1" required> Confirmo que cada contato marcado possui autorização de comunicação comercial documentada e que a referência indicada corresponde ao respectivo destinatário.</label>
                                <div class="ec-import-actions"><small>Adicionar não envia e-mails.</small><div class="ec-import-review-actions"><button type="submit" name="action" value="keep_marks" formnovalidate class="v93-btn">Guardar seleção</button><button type="submit" name="action" value="review_page" class="v93-btn">Guardar revisão desta página</button><button type="submit" name="action" value="add" class="v93-btn v93-btn-primary">Adicionar contatos revisados</button></div></div>
                                @if ($pageCount>1)<nav class="ec-import-pager" aria-label="Páginas da prévia"><span>Mostrando até 25 contatos por página · seleção preservada ao navegar</span><div>@if($page>1)<button type="submit" name="action" value="previous_page" formnovalidate class="v93-btn">← Anterior</button>@endif @if($page<$pageCount)<button type="submit" name="action" value="next_page" formnovalidate class="v93-btn">Próxima →</button>@endif</div></nav>@endif
                            </form>
                        @endif
                    </section>
                @endif
            </div>
            <aside class="v93-card ec-import-side">
                <h2>Resumo da importação</h2>
                <div class="ec-import-side-stat"><small>Encontrados na prévia</small><strong>{{ number_format(count($result['contacts']??[]),0,',','.') }}</strong></div>
                @if ($preview !== null)
                    <div class="ec-import-side-stat"><small>Marcados para revisão · arquivo inteiro</small><strong>{{ number_format($markedCount,0,',','.') }} <span>/ {{ number_format($eligibleCount,0,',','.') }}</span></strong></div>
                    <div class="ec-import-side-stat"><small>Revisados e marcados</small><strong>{{ number_format($reviewedCount,0,',','.') }}</strong></div>
                @endif
                <div class="ec-import-side-stat"><small>Selecionados na campanha (todas as origens)</small><strong>{{ number_format($selectedCount,0,',','.') }} <span>/ {{ number_format($cap,0,',','.') }}</span></strong></div>
                <div class="v93-note">A seleção do HubSpot, Receita e cadastros manuais permanece intacta. Nenhum envio é iniciado aqui.</div>
                @if($selectedCount>0)<a class="v93-btn v93-btn-primary" href="{{ route('campaigns.wizard-v93',['wizard_id'=>$wizardId,'passo'=>'mensagem']) }}">Continuar para mensagem →</a>@endif
                <a class="v93-btn" href="{{ route('campaigns.wizard-v93',['wizard_id'=>$wizardId]) }}">Revisar seleção completa</a>
            </aside>
        </div>
        <footer class="v93-bottom-note">ExportControl Prospector · Importação local · Nenhum envio automático</footer>
    </main>
    <script src="{{ asset('js/ec-campaign-import-v27.js') }}?v=27.3" defer></script>
</x-layouts::app>
