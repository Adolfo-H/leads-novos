<section class="rf-action-center">

    <div class="rf-action-center-head">

        <div>

            <div class="rf-action-eyebrow">
                Foco do dia
            </div>

            <h2>
                O que precisa da sua atenção agora
            </h2>

            <p>
                Atalhos operacionais para priorizar contatos,
                retornos e oportunidades sem procurar manualmente
                na fila.
            </p>

        </div>


        <button
            type="button"
            wire:click="applyDailyView"
            class="
                rf-action-primary
                {{
                    $dailyView === 'today'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <span>
                Minha fila hoje
            </span>

            <strong>
                {{
                    number_format(
                        $this->dailyQueueCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

        </button>

    </div>


    <div class="rf-action-grid">

        <button
            type="button"
            wire:click="applyFollowUpView('overdue')"
            class="
                rf-action-card
                is-danger
                {{
                    $followUp === 'overdue'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <div class="rf-action-card-top">

                <span class="rf-action-icon">
                    !
                </span>

                <span class="rf-action-label">
                    Follow-ups atrasados
                </span>

            </div>

            <strong class="rf-action-number">
                {{
                    number_format(
                        $this->overdueCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

            <span class="rf-action-helper">
                Já passaram do horário previsto
            </span>

        </button>


        <button
            type="button"
            wire:click="applyFollowUpView('today')"
            class="
                rf-action-card
                is-warning
                {{
                    $followUp === 'today'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <div class="rf-action-card-top">

                <span class="rf-action-icon">
                    ↗
                </span>

                <span class="rf-action-label">
                    Vencem hoje
                </span>

            </div>

            <strong class="rf-action-number">
                {{
                    number_format(
                        $this->dueTodayCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

            <span class="rf-action-helper">
                Retornos programados para hoje
            </span>

        </button>


        <button
            type="button"
            wire:click="applyReprospectingReadyView"
            class="
                rf-action-card
                is-accent
                {{
                    $reprospectingReadyOnly
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <div class="rf-action-card-top">

                <span class="rf-action-icon">
                    ↻
                </span>

                <span class="rf-action-label">
                    Reprospecção pronta
                </span>

            </div>

            <strong class="rf-action-number">
                {{
                    number_format(
                        $this->reprospectingReadyCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

            <span class="rf-action-helper">
                Empresas liberadas para nova abordagem
            </span>

        </button>


        <button
            type="button"
            wire:click="applyQuickView('new')"
            class="
                rf-action-card
                is-primary
                {{
                    $workStatus === 'new'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <div class="rf-action-card-top">

                <span class="rf-action-icon">
                    +
                </span>

                <span class="rf-action-label">
                    Novos para abordar
                </span>

            </div>

            <strong class="rf-action-number">
                {{
                    number_format(
                        $this->newCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

            <span class="rf-action-helper">
                Ainda sem interação comercial
            </span>

        </button>

    </div>


    <div class="rf-action-secondary">

        <button
            type="button"
            wire:click="clearFilters"
            class="rf-action-metric"
        >

            <span>
                Na operação
            </span>

            <strong>
                {{
                    number_format(
                        $this->operationalCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

        </button>


        <button
            type="button"
            wire:click="applyQuickView('contacting')"
            class="
                rf-action-metric
                {{
                    $workStatus === 'contacting'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <span>
                Em contato
            </span>

            <strong>
                {{
                    number_format(
                        $this->contactingCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

        </button>


        <button
            type="button"
            wire:click="applyFollowUpView('unscheduled')"
            class="
                rf-action-metric
                {{
                    $followUp === 'unscheduled'
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <span>
                Aguardando sem prazo
            </span>

            <strong>
                {{
                    number_format(
                        $this->unscheduledCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

        </button>


        <button
            type="button"
            wire:click="applyStaleView"
            class="
                rf-action-metric
                {{
                    $staleOnly
                        ? 'is-active'
                        : ''
                }}
            "
        >

            <span>
                Contatos parados
            </span>

            <strong>
                {{
                    number_format(
                        $this->staleCount,
                        0,
                        ',',
                        '.'
                    )
                }}
            </strong>

        </button>


        @if (
            $this->isCommercialManager()
        )

            <button
                type="button"
                wire:click="applyOwnerView('mine')"
                class="
                    rf-action-metric
                    {{
                        $owner === 'mine'
                            ? 'is-active'
                            : ''
                    }}
                "
            >

                <span>
                    Minha carteira
                </span>

                <strong>
                    {{
                        number_format(
                            $this->myLeadsCount,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

            </button>


            <button
                type="button"
                wire:click="applyOwnerView('unassigned')"
                class="
                    rf-action-metric
                    is-manager
                    {{
                        $owner === 'unassigned'
                            ? 'is-active'
                            : ''
                    }}
                "
            >

                <span>
                    Sem responsável
                </span>

                <strong>
                    {{
                        number_format(
                            $this->unassignedCount,
                            0,
                            ',',
                            '.'
                        )
                    }}
                </strong>

            </button>

        @endif

    </div>

</section>
