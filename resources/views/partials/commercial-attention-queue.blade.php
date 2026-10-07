<section
    class="ecgm-panel ecgm-attention-queue"
    aria-labelledby="ecgm-attention-title"
>

    <header class="ecgm-panel-head">

        <div>

            <span class="ecgm-eyebrow">
                FILA GERENCIAL
            </span>

            <h2 id="ecgm-attention-title">
                Empresas que precisam de atenção
            </h2>

            <p>
                Prioridade operacional consolidada da equipe.
            </p>

        </div>


        <span class="ecgm-attention-total">
            {{
                number_format(
                    count(
                        $attentionQueue
                    ),
                    0,
                    ',',
                    '.'
                )
            }}
            exibida(s)
        </span>

    </header>


    @forelse (
        $attentionQueue
        as $attention
    )

        <article
            class="
                ecgm-attention-row
                is-{{
                    $attention[
                        'reason_key'
                    ]
                }}
            "
            wire:key="
                attention-{{
                    $attention[
                        'company_id'
                    ]
                }}
            "
        >

            <div class="ecgm-attention-company">

                <span
                    class="
                        ecgm-attention-reason
                        is-{{
                            $attention[
                                'reason_key'
                            ]
                        }}
                    "
                >
                    {{
                        $attention[
                            'reason'
                        ]
                    }}
                </span>


                <strong>
                    {{
                        $attention[
                            'company_name'
                        ]
                    }}
                </strong>


                <small>
                    Score
                    {{
                        $attention[
                            'score'
                        ]
                    }}
                    /100
                </small>

            </div>


            <div class="ecgm-attention-owner">

                <span>
                    Responsável
                </span>

                <strong>
                    {{
                        $attention[
                            'owner_name'
                        ]
                    }}
                </strong>

            </div>


            <div class="ecgm-attention-detail">

                <span>
                    Situação
                </span>

                <strong>
                    {{
                        $attention[
                            'detail'
                        ]
                    }}
                </strong>

            </div>


            <div class="ecgm-attention-actions">

                <a
                    href="{{
                        route(
                            'leads.index',
                            $attention[
                                'filters'
                            ]
                        )
                    }}"
                    wire:navigate
                    class="ecgm-attention-secondary"
                >
                    Ver fila
                </a>


                <a
                    href="{{
                        route(
                            'companies.show',
                            $attention[
                                'company_id'
                            ]
                        )
                    }}"
                    wire:navigate
                    class="ecgm-attention-primary"
                >
                    Abrir dossiê →
                </a>

            </div>

        </article>

    @empty

        <div class="ecgm-attention-empty">

            <strong>
                Nenhuma pendência crítica agora
            </strong>

            <span>
                A equipe não possui retornos atrasados,
                aguardando sem prazo ou contatos parados.
            </span>

        </div>

    @endforelse

</section>
