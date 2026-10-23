@php
    $linkingHubSpotCompany =
        $this->linkingHubSpotCompany;

    $companyLinkCandidates =
        $this->companyLinkCandidates;
@endphp


@if ($linkingHubSpotCompany)


    <div class="lf34-linker-overlay" x-data="{ pendingAction: null, pendingCompanyId: 0, pendingCompanyName: '' }"
         x-on:keydown.escape.window="pendingAction !== null ? pendingAction = null : $wire.closeCompanyLink()"
         x-on:click.self="if (pendingAction === null) $wire.closeCompanyLink()">
        <div class="rf-company-linker lf34-linker-dialog" role="dialog" aria-modal="true" aria-label="Identificar empresa no CRM">

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
                    trim((string) $linkingHubSpotCompany->name) !== ''
                    && trim((string) $linkingHubSpotCompany->name) !== '-'
                    ? $linkingHubSpotCompany->name
                    : 'Registro HubSpot #'.$linkingHubSpotCompany->hubspot_id
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
        <p class="lf42-linker-hint">
            Se a empresa fiscal não aparecer nesta busca, informe o <strong>CNPJ completo</strong>.
            Você poderá consultar a Receita Federal e importar a empresa antes de confirmar o vínculo.
        </p>



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
                        x-on:click.stop="pendingAction = 'import'; pendingCompanyId = 0;
                          pendingCompanyName = @js($companyLinkReceitaPreview['corporate_name'] ?? 'Empresa consultada na Receita');
                          $nextTick(() => $refs.confirmationCancel.focus())"
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
                                x-on:click.stop="pendingAction = 'existing'; pendingCompanyId = {{ (int) $candidate->id }};
                                    pendingCompanyName = @js($candidate->corporate_name);
                                    $nextTick(() => $refs.confirmationCancel.focus())"
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

        {{-- EC_PROSPECTOR_V43: confirmação no próprio sistema; nenhuma janela confirm() do navegador. --}}
        <div class="lf43-confirm-backdrop" x-cloak x-show="pendingAction !== null"
             x-on:click.self="pendingAction = null">
            <div class="lf43-confirm-card" role="alertdialog" aria-modal="true"
                 aria-labelledby="lf43-confirm-title" aria-describedby="lf43-confirm-description">
                <div class="lf43-confirm-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 13a5 5 0 0 0 7.07 0l2.12-2.12a5 5 0 0 0-7.07-7.07L10.5 5.4"/>
                        <path d="M14 11a5 5 0 0 0-7.07 0L4.8 13.12a5 5 0 0 0 7.07 7.07l1.6-1.6"/>
                    </svg>
                </div>
                <h3 id="lf43-confirm-title" x-text="pendingAction === 'import' ? 'Importar e vincular empresa?' : 'Confirmar vínculo fiscal?'">Confirmar vínculo fiscal?</h3>
                <p id="lf43-confirm-description">
                    <span x-show="pendingAction === 'existing'">Confira se o registro do HubSpot e a empresa fiscal selecionada representam a <strong>mesma empresa</strong>.</span>
                    <span x-show="pendingAction === 'import'">A empresa consultada na Receita será importada para o Prospector e vinculada ao registro do HubSpot.</span>
                </p>
                <div class="lf43-confirm-identity" x-text="pendingCompanyName"></div>
                <p class="lf43-confirm-note">Esta confirmação não modifica os dados originais do HubSpot.</p>
                <div class="lf43-confirm-actions">
                    <button x-ref="confirmationCancel" type="button" class="lf43-confirm-cancel"
                            x-on:click.stop="pendingAction = null">Cancelar</button>
                    <button x-ref="confirmCommit" type="button" class="lf43-confirm-commit"
                            x-on:click.stop="if (pendingAction === 'existing') { $wire.linkHubSpotCompany(pendingCompanyId) } else if (pendingAction === 'import') { $wire.importAndLinkHubSpotCompany() }; pendingAction = null">
                        Confirmar vínculo
                    </button>
                </div>
            </div>
        </div>
    </div>

@endif
