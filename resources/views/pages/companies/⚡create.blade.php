<?php

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


<div class="ec-company-create-page">

    {{-- =====================================================
         HERO
    ====================================================== --}}
    <section class="ec-company-create-hero">

        <div class="ec-company-create-hero-copy">

            <div class="ec-page-kicker">
                Inteligência de Leads
            </div>

            <h1 class="ec-company-create-title">
                Nova empresa
            </h1>

            <p class="ec-company-create-subtitle">
                Cadastre uma empresa manualmente
                para iniciar a análise comercial.
            </p>

        </div>


        {{-- GLOBO DIGITAL --}}
        <div
            class="ec-company-create-hero-visual"
            aria-hidden="true"
        >

            <svg
                viewBox="0 0 760 320"
                role="presentation"
            >

                <defs>

                    <radialGradient
                        id="createGlobeHalo"
                        cx="50%"
                        cy="50%"
                        r="50%"
                    >
                        <stop
                            offset="0%"
                            stop-color="#1497ff"
                            stop-opacity=".27"
                        />

                        <stop
                            offset="55%"
                            stop-color="#1497ff"
                            stop-opacity=".10"
                        />

                        <stop
                            offset="100%"
                            stop-color="#1497ff"
                            stop-opacity="0"
                        />

                    </radialGradient>


                    <linearGradient
                        id="createOrbit"
                        x1="0"
                        y1="0"
                        x2="1"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stop-color="#2de0d1"
                            stop-opacity="0"
                        />

                        <stop
                            offset="45%"
                            stop-color="#2f9dff"
                            stop-opacity=".70"
                        />

                        <stop
                            offset="75%"
                            stop-color="#39e0d3"
                            stop-opacity=".55"
                        />

                        <stop
                            offset="100%"
                            stop-color="#39e0d3"
                            stop-opacity="0"
                        />

                    </linearGradient>


                    <pattern
                        id="createBackgroundDots"
                        width="16"
                        height="16"
                        patternUnits="userSpaceOnUse"
                    >
                        <circle
                            cx="2"
                            cy="2"
                            r="1.05"
                            fill="#1685e0"
                            opacity=".25"
                        />
                    </pattern>


                    <pattern
                        id="createWorldDots"
                        width="7"
                        height="7"
                        patternUnits="userSpaceOnUse"
                    >
                        <circle
                            cx="2"
                            cy="2"
                            r="1.2"
                            fill="#42b7ff"
                        />
                    </pattern>


                    <clipPath id="createGlobeClip">

                        <circle
                            cx="535"
                            cy="150"
                            r="118"
                        />

                    </clipPath>

                </defs>


                <rect
                    x="250"
                    y="12"
                    width="460"
                    height="276"
                    fill="url(#createBackgroundDots)"
                    opacity=".72"
                />


                <circle
                    cx="535"
                    cy="150"
                    r="172"
                    fill="url(#createGlobeHalo)"
                />


                <circle
                    cx="535"
                    cy="150"
                    r="118"
                    fill="none"
                    stroke="#269cff"
                    stroke-opacity=".35"
                />


                <g
                    fill="none"
                    stroke="#2d8de5"
                    stroke-opacity=".22"
                    clip-path="url(#createGlobeClip)"
                >

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="118"
                        ry="40"
                    />

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="118"
                        ry="74"
                    />

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="45"
                        ry="118"
                    />

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="83"
                        ry="118"
                    />

                    <path
                        d="M417 150H653"
                    />

                </g>


                <g
                    fill="url(#createWorldDots)"
                    clip-path="url(#createGlobeClip)"
                >

                    <path
                        d="
                            M460 86
                            C480 72
                            512 70
                            534 82
                            L546 94
                            L536 105
                            L514 111
                            L500 126
                            L477 129
                            L462 118
                            L453 103
                            Z
                        "
                    />

                    <path
                        d="
                            M493 128
                            C515 132
                            528 146
                            529 165
                            L522 187
                            L510 210
                            L494 218
                            L484 200
                            L481 177
                            L486 149
                            Z
                        "
                    />

                    <path
                        d="
                            M548 91
                            C566 81
                            589 83
                            607 96
                            L616 110
                            L608 121
                            L590 123
                            L580 134
                            L563 129
                            L552 116
                            Z
                        "
                    />

                    <path
                        d="
                            M563 133
                            C582 137
                            593 149
                            596 165
                            L589 184
                            L577 204
                            L564 199
                            L556 181
                            L554 157
                            Z
                        "
                    />

                </g>


                <g
                    fill="none"
                    stroke="url(#createOrbit)"
                    stroke-width="1.6"
                >

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="192"
                        ry="58"
                        transform="
                            rotate(
                                14
                                535
                                150
                            )
                        "
                    />

                    <ellipse
                        cx="535"
                        cy="150"
                        rx="176"
                        ry="48"
                        transform="
                            rotate(
                                -17
                                535
                                150
                            )
                        "
                    />

                </g>


                <g fill="#39e1d4">

                    <circle
                        cx="456"
                        cy="112"
                        r="4.2"
                    />

                    <circle
                        cx="505"
                        cy="91"
                        r="3.4"
                    />

                    <circle
                        cx="588"
                        cy="101"
                        r="4"
                    />

                    <circle
                        cx="607"
                        cy="149"
                        r="4.3"
                    />

                    <circle
                        cx="534"
                        cy="201"
                        r="4.2"
                    />

                </g>

            </svg>

        </div>


        <a
            href="{{ route('companies.index') }}"
            wire:navigate
            class="ec-company-create-back"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
            >
                <path d="M19 12H5" />
                <path d="m10 17-5-5 5-5" />
            </svg>

            Voltar
        </a>

    </section>


    {{-- =====================================================
         ERROS
    ====================================================== --}}
    @if ($errors->any())

        <div class="ec-company-create-alert">

            <div class="ec-company-create-alert-icon">
                !
            </div>

            <div>

                <strong>
                    Verifique os campos destacados.
                </strong>

                <span>
                    Existem informações obrigatórias ou inválidas.
                </span>

            </div>

        </div>

    @endif


    {{-- =====================================================
         FORMULARIO
    ====================================================== --}}
    <form
        wire:submit="save"
        class="ec-company-create-form"
    >

        {{-- IDENTIFICAÇÃO --}}
        <section class="ec-company-create-card">

            <div class="ec-company-create-card-header">

                <div class="ec-company-create-card-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path
                            d="
                                M5 21
                                V7
                                l7-3
                                v17
                            "
                        />

                        <path
                            d="
                                M12 9
                                h7
                                v12
                            "
                        />
                    </svg>

                </div>

                <div>

                    <h2>
                        Identificação
                    </h2>

                    <p>
                        Dados principais do grupo empresarial
                        e do estabelecimento.
                    </p>

                </div>

            </div>


            <div class="ec-company-create-grid ec-company-create-grid-2">

                {{-- CNPJ --}}
                <div class="ec-company-create-field">

                    <label for="cnpj">
                        CNPJ
                    </label>

                    <input
                        id="cnpj"
                        type="text"
                        wire:model.blur="cnpj"
                        placeholder="00.000.000/0000-00"
                        autocomplete="off"
                    >

                    @error('cnpj')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- TIPO --}}
                <div class="ec-company-create-field">

                    <label for="type">
                        Tipo do estabelecimento
                    </label>

                    <select
                        id="type"
                        wire:model="type"
                    >

                        <option value="matrix">
                            Matriz
                        </option>

                        <option value="branch">
                            Filial
                        </option>

                    </select>

                    @error('type')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- RAZAO SOCIAL --}}
                <div
                    class="
                        ec-company-create-field
                        ec-company-create-field-full
                    "
                >

                    <label for="corporateName">
                        Razão social
                    </label>

                    <input
                        id="corporateName"
                        type="text"
                        wire:model.blur="corporateName"
                        placeholder="Razão social completa"
                    >

                    @error('corporateName')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- FANTASIA --}}
                <div
                    class="
                        ec-company-create-field
                        ec-company-create-field-full
                    "
                >

                    <label for="fantasyName">
                        Nome fantasia
                    </label>

                    <input
                        id="fantasyName"
                        type="text"
                        wire:model.blur="fantasyName"
                        placeholder="Opcional"
                    >

                    @error('fantasyName')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>

            </div>

        </section>


        {{-- CADASTRO E PORTE --}}
        <section class="ec-company-create-card">

            <div class="ec-company-create-card-header">

                <div class="ec-company-create-card-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path d="M4 7h16" />
                        <path d="M4 12h16" />
                        <path d="M4 17h10" />
                    </svg>

                </div>

                <div>

                    <h2>
                        Cadastro e porte
                    </h2>

                    <p>
                        Situação cadastral, porte
                        e informações societárias.
                    </p>

                </div>

            </div>


            <div class="ec-company-create-grid ec-company-create-grid-3">

                {{-- SITUAÇÃO --}}
                <div class="ec-company-create-field">

                    <label for="registrationStatus">
                        Situação cadastral
                    </label>

                    <select
                        id="registrationStatus"
                        wire:model="registrationStatus"
                    >

                        <option value="ATIVA">
                            Ativa
                        </option>

                        <option value="SUSPENSA">
                            Suspensa
                        </option>

                        <option value="INAPTA">
                            Inapta
                        </option>

                        <option value="BAIXADA">
                            Baixada
                        </option>

                        <option value="NULA">
                            Nula
                        </option>

                    </select>

                    @error('registrationStatus')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- CAPITAL --}}
                <div class="ec-company-create-field">

                    <label for="shareCapital">
                        Capital social
                    </label>

                    <input
                        id="shareCapital"
                        type="text"
                        wire:model.blur="shareCapital"
                        placeholder="Ex.: 2.500.000,00"
                    >

                    @error('shareCapital')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- PORTE --}}
                <div class="ec-company-create-field">

                    <label for="sizeCode">
                        Código de porte
                    </label>

                    <input
                        id="sizeCode"
                        type="text"
                        wire:model.blur="sizeCode"
                        placeholder="Ex.: 05"
                    >

                    @error('sizeCode')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- NATUREZA --}}
                <div class="ec-company-create-field">

                    <label for="legalNatureCode">
                        Natureza jurídica
                    </label>

                    <input
                        id="legalNatureCode"
                        type="text"
                        wire:model.blur="legalNatureCode"
                        placeholder="Código"
                    >

                    @error('legalNatureCode')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>

            </div>

        </section>


        {{-- LOCALIZACAO --}}
        <section class="ec-company-create-card">

            <div class="ec-company-create-card-header">

                <div class="ec-company-create-card-icon">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                    >
                        <path
                            d="
                                M12 21
                                s6-5.5
                                6-11
                                a6 6 0 1 0-12 0
                                c0 5.5 6 11 6 11Z
                            "
                        />

                        <circle
                            cx="12"
                            cy="10"
                            r="2"
                        />
                    </svg>

                </div>

                <div>

                    <h2>
                        Localização e contato cadastral
                    </h2>

                    <p>
                        Dados geográficos e principais
                        canais de contato da empresa.
                    </p>

                </div>

            </div>


            <div class="ec-company-create-grid ec-company-create-grid-2">

                {{-- UF --}}
                <div class="ec-company-create-field">

                    <label for="state">
                        UF
                    </label>

                    <input
                        id="state"
                        type="text"
                        maxlength="2"
                        wire:model.blur="state"
                        placeholder="PR"
                        class="uppercase"
                    >

                    @error('state')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- MUNICIPIO --}}
                <div class="ec-company-create-field">

                    <label for="municipalityName">
                        Município
                    </label>

                    <input
                        id="municipalityName"
                        type="text"
                        wire:model.blur="municipalityName"
                        placeholder="Toledo"
                    >

                    @error('municipalityName')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- EMAIL --}}
                <div class="ec-company-create-field">

                    <label for="email">
                        E-mail cadastral
                    </label>

                    <input
                        id="email"
                        type="email"
                        wire:model.blur="email"
                        placeholder="contato@empresa.com.br"
                    >

                    @error('email')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>


                {{-- TELEFONE --}}
                <div class="ec-company-create-field">

                    <label for="phone1">
                        Telefone cadastral
                    </label>

                    <input
                        id="phone1"
                        type="text"
                        wire:model.blur="phone1"
                        placeholder="(00) 0000-0000"
                    >

                    @error('phone1')

                        <span class="ec-company-create-error">
                            {{ $message }}
                        </span>

                    @enderror

                </div>

            </div>

        </section>


        {{-- AÇÕES --}}
        <div class="ec-company-create-actions">

            <a
                href="{{ route('companies.index') }}"
                wire:navigate
                class="ec-company-create-cancel"
            >
                Cancelar
            </a>


            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="ec-company-create-submit"
            >

                <span
                    wire:loading.remove
                    wire:target="save"
                >
                    Salvar empresa
                </span>


                <span
                    wire:loading
                    wire:target="save"
                >
                    Salvando...
                </span>

            </button>

        </div>

    </form>

</div>
