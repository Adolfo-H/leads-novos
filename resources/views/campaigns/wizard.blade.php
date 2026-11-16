<x-layouts::app :title="'Nova campanha de e-mail'">
    <link rel="stylesheet" href="{{ asset('css/ec-campaign-wizard.css') }}?v=93.2">
    <main class="v93-page" data-campaign-wizard-v93="http-native">
        <header class="v93-head">
            <div>
                <small class="v93-kicker">CAMPANHAS DE E-MAIL / CRIAÇÃO</small>
                <h1>{{ ! empty($state['editing_campaign_id']) ? 'Editar rascunho' : 'Nova campanha' }}</h1>
                <p>Selecione contatos, escreva a mensagem e revise antes de salvar o rascunho.</p>
            </div>
            <a class="v93-btn v93-btn-quiet" href="{{ route('campaigns.index') }}">Cancelar e voltar</a>
        </header>

        <nav class="v93-stepper" aria-label="Etapas de criação">
            <a class="{{ $step === 'contatos' ? 'active' : '' }}" href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId]) }}"><b>1</b><span>Selecionar contatos</span></a>
            <a class="{{ $step === 'mensagem' ? 'active' : '' }}" href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']) }}"><b>2</b><span>Escrever mensagem</span></a>
            <span class="{{ in_array($step, ['ritmo', 'revisao'], true) ? 'active' : '' }}"><b>3</b><span>Ritmo e revisão</span></span>
        </nav>

        @if ($errors->any())
            <div class="v93-alert v93-alert-error" role="alert"><strong>Não foi possível avançar:</strong>
                <ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </div>
        @endif
        @if (session('campaigns_v93_notice'))
            <div class="v93-alert" role="status">{{ session('campaigns_v93_notice') }}</div>
        @endif

        @if ($step === 'contatos')
            <div class="v93-layout">
                <section class="v93-card">
                    <div class="v93-card-head"><div><h2>Escolha os contatos</h2><p>Filtre, marque os e-mails desejados e continue.</p></div><span>{{ number_format($people->total(), 0, ',', '.') }} encontrados</span></div>
                    <div id="v93-filter" class="v93-search">
                        <input name="next_search" form="v93-selection-form" type="search" value="{{ $search }}" placeholder="Buscar nome, empresa ou e-mail..." aria-label="Buscar contatos" autocomplete="off">
                        <button class="v93-btn v93-btn-secondary" type="submit" form="v93-selection-form" name="action" value="add">Buscar</button>
                    </div>
                    <nav class="v93-tabs" aria-label="Origem dos contatos">
                        @foreach (['all' => 'Todos', 'hubspot' => 'HubSpot', 'manual' => 'Cadastro manual', 'receita' => 'Receita Federal'] as $key => $title)
                            <button type="submit" form="v93-selection-form" name="next_origin" value="{{ $key }}" class="{{ $origin === $key ? 'active' : '' }}" aria-pressed="{{ $origin === $key ? 'true' : 'false' }}">{{ $title }}</button>
                        @endforeach
                    </nav>
                    <form id="v93-selection-form" method="POST" action="{{ route('campaigns.wizard-v93.selection') }}" class="v93-selection-form">
                        @csrf
                        <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                        <input type="hidden" name="origem" value="{{ $origin }}">
                        <input type="hidden" name="busca" value="{{ $search }}">
                        <input type="hidden" name="pagina" value="{{ $people->currentPage() }}">
                        <div class="v93-actions">
                            <button class="v93-btn v93-btn-secondary" type="button" id="v93-select-all">Selecionar página</button>
                            <button class="v93-btn v93-btn-secondary" type="submit" name="action" value="add">Adicionar seleção</button>
                            <span>Limite seguro: {{ number_format($cap, 0, ',', '.') }} contatos</span>
                        </div>
                        <div class="v93-table-scroll">
                            <table class="v93-table"><thead><tr><th aria-label="Marcar"></th><th>Contato / e-mail</th><th>Empresa</th><th>Origem</th></tr></thead>
                                <tbody>
                                    @forelse ($people as $person)
                                        @php
                                            $email = \App\Services\Campaigns\CampaignAudience::normalizedEmail((string) $person->email);
                                            $blocked = in_array($email, $suppressed, true) || filter_var($email, FILTER_VALIDATE_EMAIL) === false;
                                        @endphp
                                        <tr class="{{ isset($expected[$person->source_key]) ? 'selected' : '' }}">
                                            <td>
                                                <input type="hidden" name="visible[]" value="{{ $person->source_key }}">
                                                <input type="hidden" name="expected[{{ $person->source_key }}]" value="{{ $email }}">
                                                <input type="checkbox" name="keys[]" value="{{ $person->source_key }}" aria-label="Selecionar {{ $email }}" class="v93-contact-check" @checked(isset($expected[$person->source_key]) && ! $blocked) @disabled($blocked)>
                                            </td>
                                            <td>
                                                <strong>{{ $email }}</strong>
                                                <small>
                                                    {{ trim((string) $person->person_name) ?: 'Nome não informado' }}
                                                    @if ($blocked)
                                                        · E-mail bloqueado ou inválido
                                                    @endif
                                                </small>
                                            </td>
                                            <td>{{ trim((string) $person->company_name) ?: 'Não informada' }}</td>
                                            <td><span class="v93-origin">{{ match($person->origin) {'hubspot' => 'HubSpot', 'receita' => 'Receita Federal', default => 'Manual'} }}</span></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4"><p class="v93-empty">Nenhum contato encontrado com os filtros escolhidos.</p></td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="v93-pagination"><span>Página {{ $people->currentPage() }} de {{ $people->lastPage() }}</span><div>
                            @if ($people->currentPage() > 1)
                                <button type="submit" name="next_page" value="{{ $people->currentPage() - 1 }}" class="v93-btn v93-btn-quiet">Anterior</button>
                            @endif
                            @if ($people->hasMorePages())
                                <button type="submit" name="next_page" value="{{ $people->currentPage() + 1 }}" class="v93-btn v93-btn-quiet">Próxima</button>
                            @endif
                        </div></div>
                        <div class="v93-footer"><span>Ao continuar, nenhum e-mail será enviado.</span><button class="v93-btn v93-btn-primary" type="submit" name="action" value="continue">Continuar para mensagem →</button></div>
                    </form>
                    <form method="POST" action="{{ route('campaigns.wizard-v93.filtered') }}" class="v93-add-filtered">
                        @csrf
                        <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                        <input type="hidden" name="origem" value="{{ $origin }}">
                        <input type="hidden" name="busca" value="{{ $search }}">
                        <button class="v93-link" type="submit">Adicionar todos os resultados filtrados (respeitando bloqueios e limites)</button>
                    </form>
                </section>
                <aside class="v93-card v93-selected" aria-label="Destinatários selecionados">
                    <h2>Destinatários selecionados</h2>
                    <strong class="v93-big-number" data-v94-selection-count>{{ count($expected) }}</strong><p>contato(s) na seleção</p>
                    <div class="v93-note">Somente endereços válidos e não bloqueados entram no rascunho. O e-mail será conferido novamente ao salvar.</div>
                    @if($expected !== [])
                        <div class="v93-selected-list">
                            @foreach ($expected as $key => $email)
                                <form method="POST" action="{{ route('campaigns.wizard-v93.remove') }}" class="v93-selected-item">
                                    @csrf <input type="hidden" name="wizard_id" value="{{ $wizardId }}"><input type="hidden" name="key" value="{{ $key }}">
                                    <input type="hidden" name="origem" value="{{ $origin }}"><input type="hidden" name="busca" value="{{ $search }}"><input type="hidden" name="pagina" value="{{ $people->currentPage() }}">
                                    <span title="{{ $key }}">{{ $email }}</span><button type="submit" aria-label="Remover {{ $email }}">×</button>
                                </form>
                            @endforeach
                        </div>
                    @endif
                </aside>
            </div>
            <script>
              (() => {
                const form = document.getElementById('v93-selection-form');
                const search = document.querySelector('#v93-filter input[name="next_search"]');
                const count = document.querySelector('[data-v94-selection-count]');
                const present = new Set([...form.querySelectorAll('input[name="visible[]"]')].map((item) => item.value));
                const saved = @json(array_keys($expected));
                const outside = saved.filter((key) => !present.has(key)).length;
                const boxes = [...form.querySelectorAll('.v93-contact-check:not(:disabled)')];
                function refreshCount() {
                  count.textContent = String(outside + boxes.filter((item) => item.checked).length);
                  boxes.forEach((item) => item.closest('tr')?.classList.toggle('selected', item.checked));
                }
                boxes.forEach((item) => item.addEventListener('change', refreshCount));
                document.getElementById('v93-select-all')?.addEventListener('click', () => {
                  const checked = !boxes.every((item) => item.checked);
                  boxes.forEach((item) => item.checked = checked);
                  refreshCount();
                });
                let timeout;
                search?.addEventListener('input', () => {
                  clearTimeout(timeout);
                  timeout = setTimeout(() => {
                    const action = document.createElement('input');
                    action.type = 'hidden'; action.name = 'action'; action.value = 'add';
                    form.append(action);
                    form.requestSubmit();
                  }, 700);
                });
                refreshCount();
              })();
            </script>
        @elseif ($step === 'mensagem')
            <div class="v93-layout">
                <section class="v93-card">
                    <div class="v93-card-head"><div><h2>Escreva a mensagem</h2><p>Personalize o conteúdo antes de configurar o ritmo.</p></div></div>
                    <form method="POST" action="{{ route('campaigns.wizard-v93.message') }}" class="v93-editor">
                        @csrf
                        <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                        <label>Nome interno da campanha<input type="text" name="name" maxlength="180" required value="{{ old('name', $state['name'] ?? '') }}" placeholder="Ex.: Apresentação ExportControl"></label>
                        <label>Assunto do e-mail<input type="text" name="subject" maxlength="180" required value="{{ old('subject', $state['subject'] ?? '') }}"></label>
                        <label>Mensagem<textarea name="body" rows="16" maxlength="12000" required>{{ old('body', $state['body'] ?? '') }}</textarea></label>
                        <p class="v93-hint">Pode usar os marcadores &#123;&#123;primeiro_nome&#125;&#125;, &#123;&#123;nome&#125;&#125; e &#123;&#123;empresa&#125;&#125;.</p>
                        <div class="v93-footer"><a href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId]) }}" class="v93-btn v93-btn-quiet">← Voltar aos contatos</a><button type="submit" class="v93-btn v93-btn-primary">Continuar para ritmo →</button></div>
                    </form>
                </section>
                <aside class="v93-card v93-selected"><h2>Público definido</h2><strong class="v93-big-number">{{ count($expected) }}</strong><p>endereços conferidos</p><div class="v93-note">O envio permanece bloqueado até a aprovação manual na campanha.</div></aside>
            </div>
        @elseif ($step === 'ritmo')
            <div class="v93-layout">
                <section class="v93-card">
                    <div class="v93-card-head"><div><h2>Ritmo e revisão</h2><p>Defina a duração desejada; o servidor respeitará os limites autorizados do SMTP.</p></div></div>
                    <form method="POST" action="{{ route('campaigns.wizard-v93.pace') }}" class="v93-editor">
                        @csrf
                        <input type="hidden" name="wizard_id" value="{{ $wizardId }}">
                        <label>Janela planejada de envio<select name="hours" required>
                            @foreach ([1,2,4,8,12,24] as $hour)
                                <option value="{{ $hour }}" @selected((int)($state['hours'] ?? 8) === $hour)>{{ $hour }} {{ $hour === 1 ? 'hora' : 'horas' }}</option>
                            @endforeach
                        </select></label>
                        <div class="v93-note">Salvar um rascunho não envia e-mails. A aprovação de envio continua no fluxo protegido do Prospector.</div>
                        <div class="v93-footer"><a href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo'=>'mensagem']) }}" class="v93-btn v93-btn-quiet">← Voltar à mensagem</a><button type="submit" class="v93-btn v93-btn-primary">Revisar rascunho →</button></div>
                    </form>
                </section>
                <aside class="v93-card v93-selected"><h2>Resumo</h2><strong class="v93-big-number">{{ count($expected) }}</strong><p>destinatários selecionados</p></aside>
            </div>
        @else
            <div class="v93-layout">
                <section class="v93-card">
                    <div class="v93-card-head"><div><h2>Revise antes de salvar</h2><p>Confirme a lista e o texto. Nada será disparado automaticamente.</p></div></div>
                    <dl class="v93-review"><dt>Campanha</dt><dd>{{ $state['name'] ?? '' }}</dd><dt>Assunto</dt><dd>{{ $state['subject'] ?? '' }}</dd><dt>Destinatários</dt><dd>{{ count($expected) }} endereços</dd><dt>Janela planejada</dt><dd>{{ $state['hours'] ?? 8 }} horas</dd></dl>
                    <div class="v93-message-preview">{{ $state['body'] ?? '' }}</div>
                    <div class="v93-footer"><a href="{{ route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo'=>'mensagem']) }}" class="v93-btn v93-btn-quiet">← Editar mensagem</a>
                        <form method="POST" action="{{ route('campaigns.wizard-v93.save') }}">@csrf<input type="hidden" name="wizard_id" value="{{ $wizardId }}"><button type="submit" class="v93-btn v93-btn-primary">Salvar rascunho (sem enviar)</button></form>
                    </div>
                </section>
                <aside class="v93-card v93-selected"><h2>Destinatários</h2><strong class="v93-big-number">{{ count($expected) }}</strong><div class="v93-selected-list">@foreach ($expected as $email)<div class="v93-selected-item">{{ $email }}</div>@endforeach</div></aside>
            </div>
        @endif
        <footer class="v93-bottom-note">ExportControl Prospector · Fluxo HTTP com validação de destinatários no servidor · Nenhuma operação SMTP nesta tela</footer>
    </main>
</x-layouts::app>
