{{-- V88: um campo, um botão e o resultado da busca pública. Sem snapshot de negócio. --}}
@php
    $wa88Research = app(\App\Services\Contacts\WhatsappCompanyQuickResearchV88::class);
    $wa88Ready = \Illuminate\Support\Facades\Schema::hasTable('whatsapp_company_web_runs_v87')
        && \Illuminate\Support\Facades\Schema::hasTable('whatsapp_company_web_findings_v87');
    $wa88Key = $publicQuickSearchKeyV88;
    $wa88Run = $wa88Ready && $wa88Key !== null ? $wa88Research->latest($wa88Key) : null;
    $wa88Results = $wa88Ready ? $wa88Research->findings($wa88Run) : collect();
    $wa88PhoneGroups = $wa88Results->groupBy('phone_e164');
    $wa88PhoneCount = $wa88PhoneGroups->count();
    $wa88ExplicitCount = $wa88Results->where('kind', 'explicit_whatsapp_link')->pluck('phone_e164')->unique()->count();
    $wa88Sources = $wa88Run && $wa88Run->source_status
        ? (is_string($wa88Run->source_status) ? json_decode($wa88Run->source_status, true) : $wa88Run->source_status)
        : [];
    $wa88Busy = $wa88Run && in_array($wa88Run->status, ['queued', 'running'], true);
    $wa88Stale = $wa88Busy && \Carbon\Carbon::parse((string) $wa88Run->updated_at, 'UTC')->lt(now('UTC')->subMinutes(6));
    $wa89Ready = \Illuminate\Support\Facades\Schema::hasTable('whatsapp_company_web_approved_v89');
    $wa89Approval = app(\App\Services\Contacts\WhatsappCompanyPhoneApprovalV89::class);
    $wa89Targets = $wa89Ready && $wa88Run && $wa88Run->status === 'completed'
        ? $wa89Approval->targets((string) $wa88Run->company_label) : [];
    $wa89Inventory = $wa89Ready && $wa88PhoneCount > 0
        ? $wa89Approval->inventory($wa88PhoneGroups->keys()->all(), (string) $publicSaveTargetV89) : [];

@endphp
<style>
.wa88-search{font-family:inherit;color:#15384c;max-width:1150px;margin:15px auto 30px;padding:24px 26px;border:1px solid #dae7eb;border-radius:13px;background:#fff;box-shadow:0 5px 25px rgba(30,69,87,.035)}
.wa88-kicker{font-size:10px;font-weight:800;letter-spacing:.1em;color:#008b7c;text-transform:uppercase}
.wa88-search h2{margin:7px 0 8px;color:#12394d;font-size:23px;line-height:1.25;font-weight:770}
.wa88-search p{font-size:13px;line-height:1.6;color:#688596;margin:6px 0}
.wa88-form{display:flex;align-items:end;gap:12px;margin:22px 0 8px;flex-wrap:wrap}
.wa88-field{flex:1 1 360px;min-width:240px;display:grid;gap:7px;color:#1d5060;font-weight:700;font-size:12px}
.wa88-field input{height:45px;border:1px solid #cddfe5;border-radius:8px;padding:11px 13px;background:#fff!important;color:#193b50!important;-webkit-text-fill-color:#193b50!important;font-size:14px;min-width:0;width:100%;box-shadow:none;outline:0}
.wa88-field input::placeholder{color:#829baa!important;-webkit-text-fill-color:#829baa!important}
.wa88-field input:focus{border-color:#009e90;box-shadow:0 0 0 3px #d6f4ed}
.wa88-submit{min-height:45px;border-radius:8px;padding:11px 19px;background:#008f83!important;border:1px solid #008f83!important;color:#fff!important;-webkit-text-fill-color:#fff!important;font-size:13px;font-weight:760;cursor:pointer;white-space:nowrap}
.wa88-submit:hover{background:#00756d!important}.wa88-submit:disabled{opacity:.58;cursor:wait}
.wa88-hint{font-size:11px!important;color:#728e99!important}
.wa88-feedback{border:1px solid #d9e9e8;border-radius:10px;padding:16px 18px;margin-top:20px;background:#f8fbfc}
.wa88-feedback strong{font-weight:760;font-size:13px;color:#244e61}
.wa88-feedback.is-error{border-color:#f1cfbd;background:#fff8f2;color:#a85a33}
.wa88-feedback.is-error strong{color:#a45630}
.wa88-feedback.is-ok{border-color:#c3e8db;background:#f1fbf6}
.wa88-feedback.is-ok strong{color:#067e68}
.wa88-counts{display:flex;gap:10px;flex-wrap:wrap;margin:17px 0 13px}
.wa88-counts>div{flex:1 1 160px;min-width:150px;background:#fff;border:1px solid #dfe9ec;border-radius:10px;padding:13px 16px}
.wa88-counts strong{font-size:26px;color:#083b54;display:block;line-height:1.2}
.wa88-counts small{font-size:11px;color:#668596}
.wa88-results-title{font-weight:760;color:#173c52;font-size:15px;margin:21px 0 9px}
.wa88-list{display:grid;gap:10px}
.wa88-item{padding:15px 18px;border:1px solid #dde8ec;border-radius:10px;background:#fff;display:grid;grid-template-columns:minmax(160px,1fr) minmax(180px,1.4fr);gap:12px;align-items:center}
.wa88-item strong{display:block;color:#133f52;font-size:16px;font-weight:770;margin-bottom:6px}
.wa88-tag{display:inline-flex;padding:4px 8px;font-size:10px;font-weight:690;color:#8a622e;background:#fff5e7;border-radius:6px}
.wa88-tag.is-public-wa{background:#e7f6f0;color:#077e66}
.wa88-sources{display:grid;justify-content:start;gap:4px;font-size:11px;line-height:1.4;color:#607d8f}
.wa88-sources a{display:inline-block;overflow-wrap:anywhere;color:#07897f;font-weight:740;text-decoration:underline;font-size:11px}
.wa88-note{border-top:1px solid #e4ecef;margin-top:17px;padding-top:13px;font-size:11px;color:#6e8593;line-height:1.55}
@media(max-width:700px){.wa88-search{padding:18px 15px}.wa88-item{grid-template-columns:1fr}.wa88-submit{width:100%}}

/* V89: seleção clara de registros; badges apenas para status semânticos. */
.wa89-select{display:inline-flex;align-items:center;gap:7px;margin:8px 12px 0 0;font-size:12px;font-weight:700;color:#176b64;cursor:pointer}
.wa89-select input{width:18px;height:18px;accent-color:#008e80;cursor:pointer}
.wa89-indicator{display:inline-block;font-size:11px;font-weight:650;border:1px solid #cedee7;border-radius:6px;padding:3px 8px;margin:7px 7px 0 0;color:#49677a;background:#f3f8fa}
.wa89-indicator.is-new{color:#956020;background:#fff7e9;border-color:#efdec1}
.wa89-indicator.is-saved{color:#126e60;background:#e8f8f1;border-color:#b9e6d6}
.wa89-directory{display:inline-block;background:transparent;border:0;color:#008c82;font-size:11px;font-weight:750;text-decoration:underline;cursor:pointer;margin:7px 0 0}
.wa89-approval{border:1px solid #bde2d7;border-radius:11px;background:#f5fcf9;padding:17px 18px;margin:18px 0 0}
.wa89-approval h3{color:#124a48;font-weight:750;font-size:16px;margin:0 0 6px}
.wa89-approval p{font-size:12px;color:#577986;line-height:1.5;margin:6px 0}
.wa89-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:9px 0 12px}
.wa89-actions button{padding:7px 11px;border:1px solid #c0dcd5;border-radius:7px;background:#fff;color:#176d63;font-size:12px;font-weight:700;cursor:pointer}
.wa89-form{display:grid;gap:10px}
.wa89-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#194f58}
.wa89-form select,.wa89-form textarea{border:1px solid #cbdfe3;border-radius:8px;background:#fff!important;color:#1e4654!important;-webkit-text-fill-color:#1e4654!important;padding:10px 12px;width:100%;font-size:13px}
.wa89-form textarea{min-height:58px;resize:vertical}
.wa89-form .wa89-consent{display:flex;align-items:flex-start;gap:9px;font-weight:500;cursor:pointer}
.wa89-consent input{accent-color:#008e80;width:18px;height:18px;flex:0 0 18px}
.wa89-confirm{border:0;background:#008c80;color:#fff!important;border-radius:8px;padding:11px 16px;font-size:13px;font-weight:750;cursor:pointer;justify-self:start}
.wa89-confirm:disabled{opacity:.5;cursor:not-allowed}
.wa89-warning{border:1px solid #f0d8bd;border-radius:8px;background:#fff9ef;color:#89592e;padding:10px 12px;font-size:12px}
.wa89-success{border:1px solid #bce1d1;border-radius:8px;background:#e8f8f0;color:#136e5d;padding:10px 12px;font-size:12px}

</style>
<section class="wa88-search" data-wa89-approval="enabled" aria-label="Pesquisa simples de telefones públicos por empresa" data-test="wa88-pesquisa-simples" data-legacy-test="whatsapp-public-company-v11">
    <span class="wa88-kicker">Contatos do Prospector · pesquisa pública</span>
    <h2>Pesquisar WhatsApp de uma empresa específica</h2>
    <p>Verificar números que já estão no meu banco e encontrar novos telefones publicados na internet. Basta informar a empresa e pesquisar — não precisa escolher negócios nem cadastrar um site antes.</p>

    <form class="wa88-form" wire:submit="searchPublicCompanyV88">
        <label class="wa88-field" for="wa88-company-name">
            <span>Buscar empresa por nome ou domínio</span>
            <input id="wa88-company-name" type="search" wire:model="publicQuickNameV88" maxlength="150"
                autocomplete="off" placeholder="Ex.: Agroterenas, Atvos, SAAGROS..." required>
        </label>
        <button class="wa88-submit" type="submit" wire:loading.attr="disabled" wire:target="searchPublicCompanyV88">
            <span wire:loading.remove wire:target="searchPublicCompanyV88">Pesquisar números</span>
            <span wire:loading wire:target="searchPublicCompanyV88">Iniciando pesquisa...</span>
        </button>
    </form>
    <p class="wa88-hint">Busca no site oficial identificado no HubSpot e nos índices públicos Tavily/Brave configurados. Nenhuma mensagem será enviada.</p>

    @error('wa88_name')
        <div class="wa88-feedback is-error" role="alert"><strong>Não foi possível iniciar a pesquisa.</strong><p>{{ $message }}</p></div>
    @enderror
    @if(! $wa88Ready)
        <div class="wa88-feedback is-error"><strong>Atualização do banco pendente</strong><p>Execute a migration da V87 antes de pesquisar.</p></div>
    @endif

    @if($wa88Key !== null)
        <div wire:poll.visible.12s>
            @if(! $wa88Run)
                <div class="wa88-feedback"><strong>Pesquisa ainda não registrada.</strong><p>Tente clicar novamente em Pesquisar números.</p></div>
            @else
                <div class="wa88-feedback {{ in_array($wa88Run->status, ['failed','unavailable','timed_out'], true) || $wa88Stale ? 'is-error' : ($wa88Run->status === 'completed' ? 'is-ok' : '') }}" role="status">
                    <strong>{{ $wa88Run->company_label }}</strong>
                    @if($wa88Stale)
                        <p>O processamento não concluiu no prazo. Você pode pesquisar novamente. Confira o worker default se continuar ocorrendo.</p>
                    @elseif($wa88Run->status === 'queued')
                        <p>Pesquisa solicitada. Aguardando a fila iniciar a consulta.</p>
                    @elseif($wa88Run->status === 'running')
                        <p>Pesquisando telefones públicos. A tela atualiza automaticamente.</p>
                    @elseif($wa88Run->status === 'completed' && $wa88PhoneCount > 0)
                        <p>Pesquisa concluída: {{ $wa88PhoneCount }} número(s) diferente(s) localizado(s). Confira as fontes antes de usar.</p>
                    @elseif($wa88Run->status === 'completed')
                        <p>Nenhum telefone encontrado nas fontes consultadas. Isso não significa que a empresa não tenha telefone ou WhatsApp.</p>
                    @elseif($wa88Run->status === 'unavailable')
                        <p>As fontes não responderam com sucesso. Verifique a configuração ou os limites das APIs; a pesquisa não comprova ausência de telefones.</p>
                    @else
                        <p>A pesquisa falhou ou expirou. Você pode tentar novamente. Se persistir, confira os logs do worker.</p>
                    @endif
                    @if(is_array($wa88Sources) && $wa88Sources !== [])
                        <p class="wa88-hint">Fontes: @foreach($wa88Sources as $wa88Provider => $wa88State) {{ $wa88Provider }}: {{ $wa88State }}{{ ! $loop->last ? ' · ' : '' }} @endforeach</p>
                    @endif
                </div>
                @if($wa88Run->status === 'completed' && $wa88PhoneCount > 0)
                    <div class="wa88-counts">
                        <div><strong>{{ $wa88PhoneCount }}</strong><small>Telefones públicos diferentes</small></div>
                        <div><strong>{{ $wa88ExplicitCount }}</strong><small>Com link explícito de WhatsApp</small></div>
                        <div><strong>{{ $wa88PhoneCount - $wa88ExplicitCount }}</strong><small>Somente telefone público comum</small></div>
                    </div>
                    <h3 class="wa88-results-title">Números encontrados</h3>
                    <div class="wa88-list">
                        @foreach($wa88PhoneGroups as $wa88Phone => $wa88Evidence)
                            @php $wa88HasWhatsappLink = $wa88Evidence->contains(static fn($record): bool => $record->kind === 'explicit_whatsapp_link'); @endphp
                            <article class="wa88-item" wire:key="wa88-phone-{{ $wa88Phone }}">
                                <div>
                                    <strong>{{ \App\Services\Contacts\BrazilPhoneNumber::display((string) $wa88Phone, (string) $wa88Phone) }}</strong>
                                    <span class="wa88-tag {{ $wa88HasWhatsappLink ? 'is-public-wa' : '' }}">
                                        {{ $wa88HasWhatsappLink ? 'Link público de WhatsApp (não confirmado)' : 'Telefone público — WhatsApp não confirmado' }}
                                    </span>
                                    @if($wa89Ready)
                                        @php $wa89Phone = $wa89Inventory[(string) $wa88Phone] ?? null; @endphp
                                        <div>
                                            <label class="wa89-select">
                                                <input type="checkbox" wire:model.live="publicSelectedPhonesV89" value="{{ $wa88Phone }}" aria-label="Selecionar {{ $wa88Phone }}">
                                                Selecionar
                                            </label>
                                            <span class="wa89-indicator {{ ($wa89Phone['exists'] ?? false) ? '' : 'is-new' }}">
                                                {{ ($wa89Phone['exists'] ?? false) ? 'Já cadastrado no Prospector' : 'Novo no diretório' }}
                                            </span>
                                            @if($wa89Phone['approved_here'] ?? false)
                                                <span class="wa89-indicator is-saved">Já vinculado ao cadastro escolhido</span>
                                            @endif
                                            @if(($wa89Phone['phone_id'] ?? null) && ($wa89Phone['contacts_count'] ?? 0) > 0)
                                                <button type="button" class="wa89-directory"
                                                    wire:click="openPublicNumber({{ $wa89Phone['phone_id'] }}, true)"
                                                    x-on:click="$dispatch('wa12-show-numbers')">Abrir no diretório</button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="wa88-sources">
                                    @foreach($wa88Evidence->take(3) as $wa88Source)
                                        <span>{{ $wa88Source->title ?: 'Publicação pública' }} · {{ $wa88Source->provider }}</span>
                                        <a href="{{ $wa88Source->source_url }}" target="_blank" rel="noopener noreferrer">Conferir fonte {{ $loop->iteration }}</a>
                                    @endforeach
                                    @if($wa88Evidence->count() > 3)<span>Mais {{ $wa88Evidence->count() - 3 }} fonte(s) registradas.</span>@endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @if($wa89Ready)
                        <section class="wa89-approval" aria-label="Salvar e vincular telefones à empresa">
                            <h3>Salvar telefones selecionados na empresa</h3>
                            <p>Selecione os telefones encontrados e escolha o cadastro certo. Os números públicos só serão vinculados depois da sua aprovação.</p>
                            <div class="wa89-actions">
                                <button type="button" wire:click="selectAllPublicPhonesV89">Selecionar todos os encontrados</button>
                                <button type="button" wire:click="$set('publicSelectedPhonesV89', [])">Limpar seleção</button>
                                <span>{{ count($publicSelectedPhonesV89) }} selecionado(s)</span>
                            </div>
                            @if(session('wa89_saved'))<div role="status" class="wa89-success">{{ session('wa89_saved') }}</div>@endif
                            @error('wa89_selection')<div role="alert" class="wa89-warning">{{ $message }}</div>@enderror
                            @error('wa89_target')<div role="alert" class="wa89-warning">{{ $message }}</div>@enderror
                            @if($wa89Targets !== [])
                                <div class="wa89-form">
                                    <label>Cadastro da empresa que receberá os números
                                        <select wire:model.live="publicSaveTargetV89" aria-label="Empresa de destino dos telefones">
                                            <option value="">Escolha o cadastro correto</option>
                                            @foreach($wa89Targets as $wa89Target)
                                                <option value="{{ $wa89Target['key'] }}">{{ $wa89Target['name'] }} — {{ $wa89Target['source'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Observação (opcional)
                                        <textarea wire:model="publicApprovalNoteV89" maxlength="300" placeholder="Ex.: telefone conferido na página de contatos oficial"></textarea>
                                    </label>
                                    <label class="wa89-consent">
                                        <input type="checkbox" wire:model.live="publicApprovalConfirmedV89">
                                        Confirmei as fontes e autorizo associar os números selecionados a este cadastro, sem confirmar WhatsApp automaticamente.
                                    </label>
                                    <button type="button" class="wa89-confirm" wire:click="savePublicNumbersV89"
                                        wire:loading.attr="disabled" wire:target="savePublicNumbersV89"
                                        @disabled($publicSelectedPhonesV89 === [] || $publicSaveTargetV89 === '' || ! $publicApprovalConfirmedV89)>
                                        Salvar e vincular números selecionados
                                    </button>
                                </div>
                            @else
                                <div class="wa89-warning">Nenhum cadastro de empresa com esse nome foi localizado no HubSpot ou no Prospector/Receita. A pesquisa fica salva, mas não criaremos uma empresa ou associação fictícia. Cadastre ou vincule a empresa antes de aprovar os números.</div>
                            @endif
                        </section>
                    @else
                        <div class="wa89-warning">A atualização de aprovações V89 ainda não foi migrada. Execute php artisan migrate antes de salvar números.</div>
                    @endif
                @endif
            @endif
        </div>
    @endif
    <div class="wa88-note">Números públicos são indícios, não confirmação de WhatsApp ativo nem de titularidade. Apenas os números aprovados são vinculados ao cadastro escolhido no índice local; HubSpot/Receita e as confirmações WhatsApp não são alterados.</div>
</section>
