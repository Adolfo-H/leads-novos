@php
    $linkingHubSpotCompany =
        $this->linkingHubSpotCompany;

    $companyLinkCandidates =
        $this->companyLinkCandidates;
@endphp


@if ($linkingHubSpotCompany)

    <style>
        .rf-company-linker {
            padding: 15px 17px;
            border-bottom:
                1px solid rgba(46,221,210,.16);
            background:
                linear-gradient(
                    135deg,
                    rgba(46,221,210,.055),
                    rgba(74,168,255,.025)
                );
        }

        .rf-linker-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .rf-linker-head strong {
            color: #f0f8ff;
            font-size: 12px;
        }

        .rf-linker-head span {
            display: block;
            margin-top: 4px;
            color: #819cb7;
            font-size: 9px;
            line-height: 1.4;
        }

        .rf-linker-source {
            margin-top: 12px;
            padding: 10px 12px;
            border: 1px solid rgba(245,197,75,.16);
            border-radius: 9px;
            background: rgba(245,197,75,.04);
        }

        .rf-linker-source small {
            display: block;
            color: #819cb7;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .rf-linker-source b {
            display: block;
            margin-top: 4px;
            color: #ffd467;
            font-size: 12px;
        }

        .rf-linker-search {
            width: 100%;
            height: 40px;
            margin-top: 12px;
            padding: 0 12px;
            border:
                1px solid rgba(128,184,222,.18);
            border-radius: 9px;
            outline: none;
            background: #081f37;
            color: #e8f4ff;
            font-size: 11px;
        }

        .rf-linker-search:focus {
            border-color: rgba(46,221,210,.5);
        }

        .rf-linker-results {
            display: grid;
            gap: 7px;
            margin-top: 10px;
        }

        .rf-linker-result {
            display: grid;
            grid-template-columns:
                minmax(250px,1.4fr)
                minmax(200px,.8fr)
                auto;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border:
                1px solid rgba(111,181,226,.12);
            border-radius: 9px;
            background: rgba(255,255,255,.018);
        }

        .rf-linker-company {
            color: #edf6ff;
            font-size: 11px;
            font-weight: 800;
        }

        .rf-linker-meta {
            margin-top: 4px;
            color: #7896b5;
            font-size: 9px;
            line-height: 1.4;
        }

        .rf-linker-receita {
            margin-top: 10px;
            padding: 12px;
            border: 1px solid rgba(66,223,172,.20);
            border-radius: 10px;
            background: rgba(66,223,172,.045);
        }

        .rf-linker-receita-label {
            color: #5ce2b1;
            font-size: 8px;
            font-weight: 850;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .rf-linker-receita-name {
            margin-top: 5px;
            color: #eef8ff;
            font-size: 12px;
            font-weight: 800;
        }

        .rf-linker-receita-meta {
            margin-top: 5px;
            color: #8ba6bd;
            font-size: 9px;
            line-height: 1.5;
        }

        .rf-linker-error {
            margin-top: 10px;
            padding: 9px 11px;
            border: 1px solid rgba(255,110,123,.18);
            border-radius: 8px;
            background: rgba(255,110,123,.04);
            color: #ff8994;
            font-size: 9px;
        }

        .rf-linker-warning {
            margin-top: 10px;
            color: #8aa4bd;
            font-size: 9px;
            line-height: 1.45;
        }

        .rf-linker-empty {
            margin-top: 10px;
            padding: 12px;
            border:
                1px dashed rgba(128,184,222,.14);
            border-radius: 9px;
            color: #7896b5;
            font-size: 9px;
        }

        @media (max-width: 900px) {
            .rf-linker-result {
                grid-template-columns: 1fr;
            }
        }
    </style>


    <div class="rf-company-linker">

        <div class="rf-linker-head">

            <div>

                <strong>
                    Identificar empresa
                </strong>

                <span>
                    Localize a empresa fiscal correta
                    e confirme manualmente o vínculo.
                </span>

            </div>

            <button
                type="button"
                wire:click="closeCompanyLink"
                class="
                    rf-btn
                    rf-btn-secondary
                "
            >
                Fechar
            </button>

        </div>


        <div class="rf-linker-source">

            <small>
                Registro atual do HubSpot
            </small>

            <b>
                {{
                    $linkingHubSpotCompany->name
                    ?: 'Empresa sem nome'
                }}
            </b>

        </div>


        <input
            type="search"
            wire:model.live.debounce.300ms="companyLinkSearch"
            class="rf-linker-search"
            placeholder="
                Buscar por razão social,
                nome fantasia ou CNPJ...
            "
        >


        @if (
            mb_strlen(
                trim(
                    $companyLinkSearch
                )
            ) < 2
        )

            <div class="rf-linker-empty">
                Digite pelo menos 2 caracteres.
                Você pode pesquisar pelo nome ou
                pelo CNPJ da empresa correta.
            </div>

        @elseif (
            $companyLinkCandidates
                ->isEmpty()
        )

            <div class="rf-linker-empty">
                Nenhuma empresa local encontrada.

                @php
                    $normalizedLinkCnpj =
                        \App\Support\Cnpj::normalize(
                            $companyLinkSearch
                        );

                    $validLinkCnpj =
                        \App\Support\Cnpj::isValid(
                            $normalizedLinkCnpj
                        );
                @endphp

                @if ($validLinkCnpj)

                    <div
                        style="
                            margin-top:10px;
                        "
                    >
                        Esse CNPJ ainda não está
                        cadastrado no Prospector.
                    </div>

                    <button
                        type="button"
                        wire:click="
                            lookupCompanyLinkReceita
                        "
                        wire:loading.attr="disabled"
                        wire:target="
                            lookupCompanyLinkReceita
                        "
                        class="
                            rf-btn
                            rf-btn-primary
                        "
                        style="
                            margin-top:10px;
                        "
                    >
                        Consultar Receita Federal
                    </button>

                @else

                    <div
                        style="
                            margin-top:7px;
                        "
                    >
                        Se a empresa ainda não existe
                        no Prospector, informe o CNPJ
                        completo para consultar a
                        Receita.
                    </div>

                @endif

            </div>


            @if (
                $companyLinkReceitaError
                !== ''
            )

                <div class="rf-linker-error">
                    {{
                        $companyLinkReceitaError
                    }}
                </div>

            @endif


            @if (
                $companyLinkReceitaPreview
                !== []
            )

                <div class="rf-linker-receita">

                    <div class="rf-linker-receita-label">
                        Receita Federal
                    </div>

                    <div class="rf-linker-receita-name">
                        {{
                            $companyLinkReceitaPreview[
                                'corporate_name'
                            ]
                        }}
                    </div>

                    <div class="rf-linker-receita-meta">

                        CNPJ:
                        {{
                            $companyLinkReceitaPreview[
                                'cnpj_formatted'
                            ]
                        }}

                        @if (
                            $companyLinkReceitaPreview[
                                'fantasy_name'
                            ] !== ''
                        )

                            <br>
                            Fantasia:
                            {{
                                $companyLinkReceitaPreview[
                                    'fantasy_name'
                                ]
                            }}

                        @endif

                        <br>

                        {{
                            collect([
                                $companyLinkReceitaPreview[
                                    'municipality_name'
                                ],

                                $companyLinkReceitaPreview[
                                    'state'
                                ],
                            ])
                                ->filter()
                                ->implode(' / ')
                        }}

                        @if (
                            $companyLinkReceitaPreview[
                                'registration_status'
                            ] !== ''
                        )

                            <br>

                            Situação:
                            <strong>
                                {{
                                    $companyLinkReceitaPreview[
                                        'registration_status'
                                    ]
                                }}
                            </strong>

                        @endif

                        <br>

                        Estabelecimentos encontrados:
                        {{
                            $companyLinkReceitaPreview[
                                'establishment_count'
                            ]
                        }}

                    </div>


                    <button
                        type="button"
                        wire:click="
                            importAndLinkHubSpotCompany
                        "
                        wire:confirm="
                            Importar esta empresa da Receita
                            e vinculá-la ao registro do
                            HubSpot? Confirme somente se
                            o CNPJ estiver correto.
                        "
                        wire:loading.attr="disabled"
                        wire:target="
                            importAndLinkHubSpotCompany
                        "
                        class="
                            rf-btn
                            rf-btn-warning
                        "
                        style="
                            margin-top:10px;
                        "
                    >
                        Importar e vincular
                    </button>

                </div>

            @endif

        @else

            <div class="rf-linker-results">

                @foreach (
                    $companyLinkCandidates
                    as $candidate
                )

                    @php
                        $candidateMatrix =
                            $candidate->matrix;
                    @endphp

                    <div class="rf-linker-result">

                        <div>

                            <div class="rf-linker-company">
                                {{
                                    $candidate
                                        ->corporate_name
                                }}
                            </div>

                            <div class="rf-linker-meta">
                                Raiz CNPJ:
                                {{
                                    $candidate
                                        ->cnpj_root
                                }}

                                @if (
                                    $candidateMatrix?->cnpj
                                )
                                    · CNPJ:
                                    {{
                                        $candidateMatrix->cnpj
                                    }}
                                @endif
                            </div>

                        </div>


                        <div>

                            <div class="rf-linker-meta">

                                @if (
                                    $candidateMatrix
                                        ?->fantasy_name
                                )

                                    {{
                                        $candidateMatrix
                                            ->fantasy_name
                                    }}

                                    <br>

                                @endif

                                {{
                                    collect([
                                        $candidateMatrix
                                            ?->municipality_name,

                                        $candidateMatrix
                                            ?->state,
                                    ])
                                        ->filter()
                                        ->implode(' / ')
                                    ?: 'Localização não informada'
                                }}

                            </div>

                        </div>


                        <div
                            style="
                                display:flex;
                                gap:6px;
                                flex-wrap:wrap;
                            "
                        >

                            <a
                                href="{{
                                    route(
                                        'companies.show',
                                        $candidate
                                    )
                                }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="
                                    rf-btn
                                    rf-btn-secondary
                                "
                            >
                                Ver dossiê
                            </a>

                            <button
                                type="button"
                                wire:click="
                                    linkHubSpotCompany(
                                        {{ $candidate->id }}
                                    )
                                "
                                wire:confirm="
                                    Confirma este vínculo?
                                    Use somente quando tiver
                                    certeza de que é a mesma
                                    empresa.
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                class="
                                    rf-btn
                                    rf-btn-warning
                                "
                            >
                                Vincular
                            </button>

                        </div>

                    </div>

                @endforeach

            </div>

        @endif


        <div class="rf-linker-warning">
            Este processo não cria um novo CNPJ e
            não altera os dados originais do HubSpot.
            Ele apenas associa o registro do CRM a
            uma empresa fiscal já existente no
            Prospector.
        </div>

    </div>

@endif
