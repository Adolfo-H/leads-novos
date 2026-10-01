<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Services\CompanyService;
use App\Support\Cnpj;
use Livewire\Component;

new class extends Component
{
    public string $cnpj = '';

    public string $corporateName = '';

    public string $fantasyName = '';

    public string $type = 'matrix';

    public string $registrationStatus = 'ATIVA';

    public string $state = '';

    public string $municipalityName = '';

    public string $email = '';

    public string $phone1 = '';

    public string $shareCapital = '';

    public string $sizeCode = '';

    public string $legalNatureCode = '';

    public function save(
        CompanyService $service
    ) {
        $validated =
            $this->validate(
                [
                    'cnpj' => [
                        'required',
                        'string',
                        'max:30',
                    ],

                    'corporateName' => [
                        'required',
                        'string',
                        'max:255',
                    ],

                    'fantasyName' => [
                        'nullable',
                        'string',
                        'max:255',
                    ],

                    'type' => [
                        'required',
                        'in:matrix,branch',
                    ],

                    'registrationStatus' => [
                        'nullable',
                        'string',
                        'max:100',
                    ],

                    'state' => [
                        'nullable',
                        'string',
                        'size:2',
                    ],

                    'municipalityName' => [
                        'nullable',
                        'string',
                        'max:150',
                    ],

                    'email' => [
                        'nullable',
                        'email',
                        'max:255',
                    ],

                    'phone1' => [
                        'nullable',
                        'string',
                        'max:50',
                    ],

                    'shareCapital' => [
                        'nullable',
                        'string',
                        'max:40',
                    ],

                    'sizeCode' => [
                        'nullable',
                        'string',
                        'max:10',
                    ],

                    'legalNatureCode' => [
                        'nullable',
                        'string',
                        'max:10',
                    ],
                ],
                [
                    'cnpj.required' => 'Informe o CNPJ.',

                    'cnpj.max' => 'O CNPJ informado é muito longo.',

                    'corporateName.required' => 'Informe a razão social.',

                    'corporateName.max' => 'A razão social deve ter no máximo 255 caracteres.',

                    'fantasyName.max' => 'O nome fantasia deve ter no máximo 255 caracteres.',

                    'type.required' => 'Selecione o tipo do estabelecimento.',

                    'type.in' => 'O tipo do estabelecimento é inválido.',

                    'state.size' => 'A UF deve possuir exatamente 2 caracteres.',

                    'municipalityName.max' => 'O município deve ter no máximo 150 caracteres.',

                    'email.email' => 'Informe um endereço de e-mail válido.',

                    'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',

                    'phone1.max' => 'O telefone deve ter no máximo 50 caracteres.',

                    'shareCapital.max' => 'O capital social informado é muito longo.',

                    'sizeCode.max' => 'O código de porte deve ter no máximo 10 caracteres.',

                    'legalNatureCode.max' => 'O código de natureza jurídica deve ter no máximo 10 caracteres.',
                ]
            );

        $normalizedCnpj =
            Cnpj::normalize(
                $validated['cnpj']
            );

        if (
            ! Cnpj::isValid(
                $normalizedCnpj
            )
        ) {
            $this->addError(
                'cnpj',
                'Informe um CNPJ válido.'
            );

            return;
        }

        if (
            Establishment::query()
                ->where(
                    'cnpj',
                    $normalizedCnpj
                )
                ->exists()
        ) {
            $this->addError(
                'cnpj',
                'Este estabelecimento já está cadastrado.'
            );

            return;
        }

        /*
         * A tela Nova empresa cria um novo grupo.
         *
         * Se a raiz já existe, o usuário precisa
         * utilizar o fluxo específico de filial.
         *
         * Isso impede uma filial manual de
         * sobrescrever os dados do grupo.
         */
        $existingCompany =
            Company::query()
                ->where(
                    'cnpj_root',
                    Cnpj::root(
                        $normalizedCnpj
                    )
                )
                ->first();

        if ($existingCompany) {
            $this->addError(
                'cnpj',
                'Esta raiz de CNPJ já pertence a uma empresa cadastrada. Abra o dossiê da empresa e use a opção de adicionar filial.'
            );

            return;
        }

        $shareCapital =
            $this->parseMoney(
                $validated[
                    'shareCapital'
                ]
                ?? ''
            );

        if (
            (
                $validated[
                    'shareCapital'
                ]
                ?? ''
            ) !== ''
            && $shareCapital === null
        ) {
            $this->addError(
                'shareCapital',
                'Informe um capital social válido.'
            );

            return;
        }

        $service
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => trim(
                        $validated[
                            'corporateName'
                        ]
                    ),

                    'share_capital' => $shareCapital,

                    'size_code' => $validated[
                            'sizeCode'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'sizeCode'
                                ]
                            )
                            : null,

                    'legal_nature_code' => $validated[
                            'legalNatureCode'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'legalNatureCode'
                                ]
                            )
                            : null,

                    'source' => 'manual',
                ],
                [
                    'cnpj' => $normalizedCnpj,

                    'type' => $validated[
                            'type'
                        ],

                    'fantasy_name' => $validated[
                            'fantasyName'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'fantasyName'
                                ]
                            )
                            : null,

                    'registration_status' => $validated[
                            'registrationStatus'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'registrationStatus'
                                ]
                            )
                            : null,

                    'state' => $validated[
                            'state'
                        ] !== ''
                            ? mb_strtoupper(
                                trim(
                                    $validated[
                                        'state'
                                    ]
                                )
                            )
                            : null,

                    'municipality_name' => $validated[
                            'municipalityName'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'municipalityName'
                                ]
                            )
                            : null,

                    'email' => $validated[
                            'email'
                        ] !== ''
                            ? mb_strtolower(
                                trim(
                                    $validated[
                                        'email'
                                    ]
                                )
                            )
                            : null,

                    'phone_1' => $validated[
                            'phone1'
                        ] !== ''
                            ? trim(
                                $validated[
                                    'phone1'
                                ]
                            )
                            : null,

                    'source' => 'manual',
                ],
            );

        session()->flash(
            'success',
            'Empresa cadastrada com sucesso.'
        );

        return redirect()
            ->route(
                'companies.index'
            );
    }

    private function parseMoney(
        ?string $value
    ): ?float {
        if ($value === null) {
            return null;
        }

        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        $value =
            str_ireplace(
                'R$',
                '',
                $value
            );

        $value =
            str_replace(
                ' ',
                '',
                $value
            );

        /*
         * Formato brasileiro:
         *
         * 1.500.000,50
         */
        if (
            str_contains(
                $value,
                ','
            )
        ) {
            $value =
                str_replace(
                    '.',
                    '',
                    $value
                );

            $value =
                str_replace(
                    ',',
                    '.',
                    $value
                );
        }

        if (
            ! is_numeric(
                $value
            )
        ) {
            return null;
        }

        $number =
            (float) $value;

        if ($number < 0) {
            return null;
        }

        return $number;
    }
};
?>
<div class="ecnc-page" x-data="{ section: 'identificacao' }">

    <header class="ecnc-header">
        <div>
            <div class="ecnc-breadcrumb">
                <a href="{{ route('companies.index') }}" wire:navigate>
                    Empresas
                </a>
                <span aria-hidden="true">/</span>
                <span>Novo cadastro</span>
            </div>

            <h1>Nova empresa</h1>
            <p>Cadastre os dados da empresa e do estabelecimento.</p>
        </div>

        <a
            class="ecnc-button ecnc-secondary"
            href="{{ route('companies.index') }}"
            wire:navigate
        >
            ← Voltar
        </a>
    </header>


    <nav class="ecnc-sections" aria-label="Seções do cadastro">

        <a
            href="#ecnc-identificacao"
            x-on:click="section = 'identificacao'"
            x-bind:aria-current="section === 'identificacao' ? 'step' : null"
        >
            <span>01</span>
            Identificação
        </a>

        <a
            href="#ecnc-cadastro"
            x-on:click="section = 'cadastro'"
            x-bind:aria-current="section === 'cadastro' ? 'step' : null"
        >
            <span>02</span>
            Cadastro e porte
        </a>

        <a
            href="#ecnc-contato"
            x-on:click="section = 'contato'"
            x-bind:aria-current="section === 'contato' ? 'step' : null"
        >
            <span>03</span>
            Localização e contato
        </a>

    </nav>


    <form wire:submit="save" novalidate class="ecnc-form">

        @if ($errors->any())
            <div
                class="ecnc-alert"
                role="alert"
                tabindex="-1"
                x-init="$nextTick(() => $el.focus())"
            >
                <strong>Confira os dados antes de salvar.</strong>

                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif


        <div class="ecnc-layout">

            <fieldset
                class="ecnc-main"
                wire:loading.attr="disabled"
                wire:target="save"
            >
                <legend class="ecnc-sr">
                    Dados da nova empresa
                </legend>


                <section
                    class="ecnc-card"
                    id="ecnc-identificacao"
                    x-on:focusin="section = 'identificacao'"
                >
                    <header class="ecnc-card-head">
                        <span class="ecnc-number">01</span>

                        <div>
                            <h2>Identificação</h2>
                            <p>Os campos com * são obrigatórios.</p>
                        </div>
                    </header>

                    <div class="ecnc-grid">
                        <div class="ecnc-field"><label for="ecnc-cnpj">CNPJ <span class="ecnc-required" aria-hidden="true">*</span></label>
<input type="text" id="ecnc-cnpj" wire:model="cnpj" aria-invalid="{{ $errors->has('cnpj') ? 'true' : 'false' }}" @if ($errors->has('cnpj')) aria-describedby="ecnc-cnpj-error" @endif required maxlength="30" placeholder="00.000.000/0000-00">
@error('cnpj')
<span class="ecnc-error" id="ecnc-cnpj-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-type">Tipo do estabelecimento <span class="ecnc-required" aria-hidden="true">*</span></label>
<select id="ecnc-type" wire:model="type" aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @if ($errors->has('type')) aria-describedby="ecnc-type-error" @endif required><option value="matrix">Matriz</option><option value="branch">Filial</option></select>
@error('type')
<span class="ecnc-error" id="ecnc-type-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-corporateName">Razão social <span class="ecnc-required" aria-hidden="true">*</span></label>
<input type="text" id="ecnc-corporateName" wire:model="corporateName" aria-invalid="{{ $errors->has('corporateName') ? 'true' : 'false' }}" @if ($errors->has('corporateName')) aria-describedby="ecnc-corporateName-error" @endif required maxlength="255" placeholder="Razão social completa">
@error('corporateName')
<span class="ecnc-error" id="ecnc-corporateName-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-fantasyName">Nome fantasia</label>
<input type="text" id="ecnc-fantasyName" wire:model="fantasyName" aria-invalid="{{ $errors->has('fantasyName') ? 'true' : 'false' }}" @if ($errors->has('fantasyName')) aria-describedby="ecnc-fantasyName-error" @endif maxlength="255" placeholder="Opcional">
@error('fantasyName')
<span class="ecnc-error" id="ecnc-fantasyName-error">{{ $message }}</span>
@enderror
</div>
                    </div>

                    <p class="ecnc-help">
                        CNPJ com ou sem pontuação.
                        A validação acontece ao salvar.
                    </p>
                </section>


                <section
                    class="ecnc-card"
                    id="ecnc-cadastro"
                    x-on:focusin="section = 'cadastro'"
                >
                    <header class="ecnc-card-head">
                        <span class="ecnc-number">02</span>

                        <div>
                            <h2>Cadastro e porte</h2>
                            <p>Informações cadastrais e societárias.</p>
                        </div>
                    </header>

                    <div class="ecnc-grid">
                        <div class="ecnc-field"><label for="ecnc-registrationStatus">Situação cadastral</label>
<select id="ecnc-registrationStatus" wire:model="registrationStatus" aria-invalid="{{ $errors->has('registrationStatus') ? 'true' : 'false' }}" @if ($errors->has('registrationStatus')) aria-describedby="ecnc-registrationStatus-error" @endif><option value="ATIVA">Ativa</option><option value="SUSPENSA">Suspensa</option><option value="INAPTA">Inapta</option><option value="BAIXADA">Baixada</option><option value="NULA">Nula</option><option value="">Não informada</option></select>
@error('registrationStatus')
<span class="ecnc-error" id="ecnc-registrationStatus-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-shareCapital">Capital social (R$)</label>
<input type="text" id="ecnc-shareCapital" wire:model="shareCapital" aria-invalid="{{ $errors->has('shareCapital') ? 'true' : 'false' }}" @if ($errors->has('shareCapital')) aria-describedby="ecnc-shareCapital-error" @endif maxlength="40" placeholder="Ex.: 2.500.000,00">
@error('shareCapital')
<span class="ecnc-error" id="ecnc-shareCapital-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-sizeCode">Código de porte</label>
<input type="text" id="ecnc-sizeCode" wire:model="sizeCode" aria-invalid="{{ $errors->has('sizeCode') ? 'true' : 'false' }}" @if ($errors->has('sizeCode')) aria-describedby="ecnc-sizeCode-error" @endif maxlength="10" placeholder="Ex.: 05">
@error('sizeCode')
<span class="ecnc-error" id="ecnc-sizeCode-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-legalNatureCode">Código de natureza jurídica</label>
<input type="text" id="ecnc-legalNatureCode" wire:model="legalNatureCode" aria-invalid="{{ $errors->has('legalNatureCode') ? 'true' : 'false' }}" @if ($errors->has('legalNatureCode')) aria-describedby="ecnc-legalNatureCode-error" @endif maxlength="10" placeholder="Código cadastral">
@error('legalNatureCode')
<span class="ecnc-error" id="ecnc-legalNatureCode-error">{{ $message }}</span>
@enderror
</div>
                    </div>
                </section>


                <section
                    class="ecnc-card"
                    id="ecnc-contato"
                    x-on:focusin="section = 'contato'"
                >
                    <header class="ecnc-card-head">
                        <span class="ecnc-number">03</span>

                        <div>
                            <h2>Localização e contato</h2>
                            <p>Informe os canais disponíveis para contato.</p>
                        </div>
                    </header>

                    <div class="ecnc-grid">
                        <div class="ecnc-field"><label for="ecnc-state">UF</label>
<input type="text" id="ecnc-state" wire:model="state" aria-invalid="{{ $errors->has('state') ? 'true' : 'false' }}" @if ($errors->has('state')) aria-describedby="ecnc-state-error" @endif maxlength="2" placeholder="Ex.: PR">
@error('state')
<span class="ecnc-error" id="ecnc-state-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-municipalityName">Município</label>
<input type="text" id="ecnc-municipalityName" wire:model="municipalityName" aria-invalid="{{ $errors->has('municipalityName') ? 'true' : 'false' }}" @if ($errors->has('municipalityName')) aria-describedby="ecnc-municipalityName-error" @endif maxlength="150" placeholder="Nome do município">
@error('municipalityName')
<span class="ecnc-error" id="ecnc-municipalityName-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-email">E-mail cadastral</label>
<input type="email" id="ecnc-email" wire:model="email" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @if ($errors->has('email')) aria-describedby="ecnc-email-error" @endif maxlength="255" placeholder="contato@empresa.com.br">
@error('email')
<span class="ecnc-error" id="ecnc-email-error">{{ $message }}</span>
@enderror
</div>
<div class="ecnc-field"><label for="ecnc-phone1">Telefone cadastral</label>
<input type="tel" id="ecnc-phone1" wire:model="phone1" aria-invalid="{{ $errors->has('phone1') ? 'true' : 'false' }}" @if ($errors->has('phone1')) aria-describedby="ecnc-phone1-error" @endif maxlength="50" placeholder="(00) 00000-0000">
@error('phone1')
<span class="ecnc-error" id="ecnc-phone1-error">{{ $message }}</span>
@enderror
</div>
                    </div>
                </section>

            </fieldset>


            <aside class="ecnc-aside" aria-label="Resumo do cadastro">

                <section class="ecnc-summary">
                    <span class="ecnc-tag">CADASTRO MANUAL</span>

                    <h2>Resumo do cadastro</h2>
                    <p>Confira as informações enquanto preenche.</p>

                    <dl>
                        <div>
                            <dt>Razão social</dt>
                            <dd x-text="$wire.corporateName || 'Não informada'">
                                {{ $corporateName ?: 'Não informada' }}
                            </dd>
                        </div>

                        <div>
                            <dt>CNPJ</dt>
                            <dd x-text="$wire.cnpj || 'Não informado'">
                                {{ $cnpj ?: 'Não informado' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Estabelecimento</dt>
                            <dd x-text="$wire.type === 'matrix' ? 'Matriz' : 'Filial'">
                                {{ $type === 'matrix' ? 'Matriz' : 'Filial' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Situação cadastral</dt>
                            <dd x-text="$wire.registrationStatus || 'Não informada'">
                                {{ $registrationStatus ?: 'Não informada' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Localização</dt>
                            <dd x-text="[$wire.municipalityName, $wire.state].filter(Boolean).join(' / ') || 'Não informada'">
                                {{ trim($municipalityName.' '.$state) ?: 'Não informada' }}
                            </dd>
                        </div>

                        <div>
                            <dt>E-mail</dt>
                            <dd x-text="$wire.email || 'Não informado'">
                                {{ $email ?: 'Não informado' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Telefone</dt>
                            <dd x-text="$wire.phone1 || 'Não informado'">
                                {{ $phone1 ?: 'Não informado' }}
                            </dd>
                        </div>
                    </dl>
                </section>


                <section class="ecnc-note">
                    <h3>Antes de salvar</h3>

                    <p>
                        Confira a razão social e o tipo do estabelecimento.
                        O sistema verifica o CNPJ e evita cadastrar novamente
                        um estabelecimento existente.
                    </p>
                </section>

            </aside>

        </div>


        <footer class="ecnc-actions">

            <span class="ecnc-action-note">
                Revise os dados antes de concluir.
            </span>

            <div>
                <a
                    class="ecnc-button ecnc-secondary"
                    href="{{ route('companies.index') }}"
                    wire:navigate
                >
                    Cancelar
                </a>

                <button
                    type="submit"
                    class="ecnc-button ecnc-primary"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    <span wire:loading.remove wire:target="save">
                        Salvar empresa
                    </span>

                    <span wire:loading wire:target="save" role="status">
                        Salvando...
                    </span>
                </button>
            </div>

        </footer>

    </form>

</div>
