@if ($distributionPreview !== null)

    <section
        class="ecgm-distribution-preview"
        aria-labelledby="ecgm-preview-title"
    >

        <header class="ecgm-preview-head">

            <div>
                <span class="ecgm-eyebrow">
                    PRÉVIA DA RODADA
                </span>

                <h3 id="ecgm-preview-title">
                    Como a carteira ficará
                </h3>

                <p>
                    Simulação antes de alterar qualquer responsável.
                </p>
            </div>


            <div class="ecgm-preview-volume">

                <span>
                    Serão distribuídos
                </span>

                <strong>
                    {{
                        $number(
                            $distributionPreview[
                                'planned'
                            ]
                        )
                    }}
                </strong>

                <small>
                    de
                    {{
                        $number(
                            $distributionPreview[
                                'available_before'
                            ]
                        )
                    }}
                    disponíveis
                </small>

            </div>

        </header>


        <div class="ecgm-preview-balance">

            <div>
                <span>
                    Diferença atual
                </span>

                <strong>
                    {{
                        $number(
                            $distributionPreview[
                                'balance_before'
                            ][
                                'spread'
                            ]
                        )
                    }}
                </strong>
            </div>


            <span class="ecgm-preview-arrow">
                →
            </span>


            <div>
                <span>
                    Diferença prevista
                </span>

                <strong>
                    {{
                        $number(
                            $distributionPreview[
                                'balance_after'
                            ][
                                'spread'
                            ]
                        )
                    }}
                </strong>
            </div>


            <div class="ecgm-preview-remaining">

                <span>
                    Restariam sem responsável
                </span>

                <strong>
                    {{
                        $number(
                            $distributionPreview[
                                'remaining_after'
                            ]
                        )
                    }}
                </strong>

            </div>

        </div>


        <div class="ecgm-preview-users">

            @foreach (
                $distributionPreview[
                    'users'
                ]
                as $previewUser
            )

                <article
                    class="ecgm-preview-user"
                    wire:key="
                        preview-user-{{
                            $previewUser[
                                'user_id'
                            ]
                        }}
                    "
                >

                    <strong>
                        {{
                            $previewUser[
                                'name'
                            ]
                        }}
                    </strong>


                    <div class="ecgm-preview-flow">

                        <span>
                            <small>
                                Atual
                            </small>

                            <b>
                                {{
                                    $number(
                                        $previewUser[
                                            'starting_load'
                                        ]
                                    )
                                }}
                            </b>
                        </span>


                        <i>
                            +
                            {{
                                $number(
                                    $previewUser[
                                        'assigned_now'
                                    ]
                                )
                            }}
                        </i>


                        <span>
                            <small>
                                Depois
                            </small>

                            <b>
                                {{
                                    $number(
                                        $previewUser[
                                            'ending_load'
                                        ]
                                    )
                                }}
                            </b>
                        </span>

                    </div>

                </article>

            @endforeach

        </div>


        <p class="ecgm-preview-note">
            A execução real revalida cada lead antes da atribuição.
            Alterações simultâneas podem mudar levemente o resultado final.
        </p>

    </section>

@endif
