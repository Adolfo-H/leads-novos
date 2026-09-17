@php
    $commercialLead =
        $company->hubSpotLead;

    $commercialActivities =
        $company
            ->leadActivities
            ->take(40);

    $commercialStatus =
        $commercialLead?->work_status
        ?? 'new';

    $commercialStatusLabel =
        match ($commercialStatus) {
            'contacting' =>
                'Em contato',

            'waiting' =>
                'Aguardando retorno',

            'discarded' =>
                'Descartado',

            default =>
                'Novo',
        };

    $commercialStatusClass =
        match ($commercialStatus) {
            'contacting' =>
                'border-cyan-300/20 bg-cyan-300/10 text-cyan-300',

            'waiting' =>
                'border-amber-300/20 bg-amber-300/10 text-amber-300',

            'discarded' =>
                'border-red-300/20 bg-red-300/10 text-red-300',

            default =>
                'border-emerald-300/20 bg-emerald-300/10 text-emerald-300',
        };

    $stageLabel =
        match (
            $commercialLead?->deal_stage_id
        ) {
            'appointmentscheduled' =>
                'Prospects',

            'qualifiedtobuy' =>
                'Leds frio',

            'presentationscheduled' =>
                'Leds qualificado',

            '122191633' =>
                'Reunião Agendada',

            '122191634' =>
                'Reunião Cancelada',

            '122191635' =>
                'Reunião Realizada',

            'decisionmakerboughtin' =>
                'Proposta apresentada',

            'contractsent' =>
                'Aceite da proposta',

            '14249606' =>
                'Contrato Assinado',

            '14249607' =>
                'Início do teste',

            'closedwon' =>
                'Negócio fechado',

            'closedlost' =>
                'Recusou',

            '13185626' =>
                'Oportunidade Futura',

            '13185627' =>
                'Descartes',

            '13185628' =>
                'Leads fora foco',

            default =>
                $commercialLead
                    ?->deal_stage_id
                ?? '—',
        };

    $portalId =
        trim(
            (string) config(
                'services.hubspot.portal_id'
            )
        );

    $dealId =
        trim(
            (string) (
                $commercialLead
                    ?->hubspot_deal_id
                ?? ''
            )
        );

    $dealUrl =
        $portalId !== ''
        && $dealId !== ''
            ? sprintf(
                'https://app.hubspot.com/contacts/%s/record/0-3/%s',
                rawurlencode(
                    $portalId
                ),
                rawurlencode(
                    $dealId
                )
            )
            : null;

    $openTasks =
        data_get(
            $commercialLead?->metadata
                ?? [],
            'hubspot_status.open_tasks',
            []
        );

    if (! is_array($openTasks)) {
        $openTasks = [];
    }
@endphp


<section
    class="
        mt-5 overflow-hidden
        rounded-2xl
        border border-white/[0.06]
        bg-white/[0.025]
    "
>

    <div
        class="
            flex flex-col gap-4
            border-b border-white/[0.06]
            px-5 py-5
            lg:flex-row
            lg:items-center
            lg:justify-between
        "
    >

        <div>
            <div
                class="
                    text-[11px]
                    font-semibold uppercase
                    tracking-[0.16em]
                    text-cyan-300
                "
            >
                Operação SDR
            </div>

            <div
                class="
                    mt-1 text-base
                    font-semibold
                    text-[#eef1ff]
                "
            >
                Histórico comercial
            </div>

            <p
                class="
                    mt-1 text-xs
                    text-[#7f89aa]
                "
            >
                Linha do tempo da prospecção,
                HubSpot e follow-ups.
            </p>
        </div>


        <div
            class="
                flex flex-wrap
                items-center gap-3
            "
        >

            <span
                class="
                    rounded-full border
                    px-3 py-1.5
                    text-xs font-semibold
                    {{ $commercialStatusClass }}
                "
            >
                {{ $commercialStatusLabel }}
            </span>

            @if ($dealUrl)

                <a
                    href="{{ $dealUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="ec-button-secondary"
                >
                    Abrir negócio no HubSpot ↗
                </a>

            @endif

        </div>

    </div>


    <div
        class="
            grid gap-3
            border-b border-white/[0.06]
            px-5 py-4
            sm:grid-cols-2
            xl:grid-cols-4
        "
    >

        <div>
            <div class="ec-field-label">
                Status atual
            </div>

            <div
                class="
                    mt-1 text-sm
                    font-semibold
                    text-[#eef1ff]
                "
            >
                {{ $commercialStatusLabel }}
            </div>
        </div>


        <div>
            <div class="ec-field-label">
                Etapa HubSpot
            </div>

            <div
                class="
                    mt-1 text-sm
                    font-semibold
                    text-[#d9ddef]
                "
            >
                {{ $stageLabel }}
            </div>
        </div>


        <div>
            <div class="ec-field-label">
                Tarefas abertas
            </div>

            <div
                class="
                    mt-1 text-sm
                    font-semibold
                    {{
                        (int) (
                            $commercialLead
                                ?->open_task_count
                            ?? 0
                        ) > 0
                            ? 'text-amber-300'
                            : 'text-[#d9ddef]'
                    }}
                "
            >
                {{
                    (int) (
                        $commercialLead
                            ?->open_task_count
                        ?? 0
                    )
                }}
            </div>
        </div>


        <div>
            <div class="ec-field-label">
                Próxima ação
            </div>

            <div
                class="
                    mt-1 text-sm
                    {{
                        $commercialLead
                            ?->last_task_due_at
                            ?->isPast()
                            ? 'text-red-300'
                            : 'text-[#d9ddef]'
                    }}
                "
            >
                {{
                    $commercialLead
                        ?->last_task_due_at
                        ?->format(
                            'd/m/Y H:i'
                        )
                    ?? '—'
                }}
            </div>
        </div>

    </div>


    @if (
        $commercialLead
        && $commercialLead->last_activity_at
    )

        <div
            class="
                border-b border-white/[0.06]
                px-5 py-3
                text-xs text-[#8993b2]
            "
        >
            Última atividade identificada no HubSpot:
            <strong class="text-[#cbd1e7]">
                {{
                    $commercialLead
                        ->last_activity_at
                        ->format(
                            'd/m/Y H:i'
                        )
                }}
            </strong>
        </div>

    @endif


    @if (
        $commercialLead?->sync_error
    )

        <div
            class="
                border-b border-red-300/10
                bg-red-300/[0.04]
                px-5 py-3
                text-xs text-red-300
            "
        >
            Falha de sincronização:
            {{ $commercialLead->sync_error }}
        </div>

    @endif


    @if ($openTasks !== [])

        <div
            class="
                border-b border-white/[0.06]
                px-5 py-4
            "
        >

            <div
                class="
                    text-[10px]
                    font-semibold uppercase
                    tracking-[0.12em]
                    text-[#737e9f]
                "
            >
                Follow-ups pendentes
            </div>


            <div
                class="
                    mt-3 grid gap-2
                    lg:grid-cols-2
                "
            >

                @foreach (
                    $openTasks
                    as $task
                )

                    @php
                        $taskDueLabel = null;

                        try {
                            if (
                                filled(
                                    $task['due_at']
                                    ?? null
                                )
                            ) {
                                $taskDueLabel =
                                    \Carbon\CarbonImmutable::parse(
                                        $task['due_at']
                                    )->format(
                                        'd/m/Y H:i'
                                    );
                            }
                        } catch (\Throwable) {
                            $taskDueLabel = null;
                        }
                    @endphp

                    <div
                        class="
                            rounded-xl
                            border border-white/[0.06]
                            bg-white/[0.025]
                            px-4 py-3
                        "
                    >

                        <div
                            class="
                                text-sm font-semibold
                                text-[#e8ebf7]
                            "
                        >
                            {{
                                $task['subject']
                                ?? 'Tarefa sem título'
                            }}
                        </div>


                        <div
                            class="
                                mt-1 text-[11px]
                                text-[#7f89aa]
                            "
                        >
                            {{
                                $task['status']
                                ?? 'Sem status'
                            }}

                            @if ($taskDueLabel)
                                · {{ $taskDueLabel }}
                            @endif
                        </div>

                    </div>

                @endforeach

            </div>

        </div>

    @endif


    <div class="px-5 py-5">

        <div
            class="
                text-[10px]
                font-semibold uppercase
                tracking-[0.12em]
                text-[#737e9f]
            "
        >
            Linha do tempo
        </div>


        @if (
            $commercialActivities
                ->isEmpty()
        )

            <div
                class="
                    mt-4 rounded-xl
                    border border-dashed
                    border-white/[0.08]
                    px-4 py-7
                    text-center
                "
            >
                <div
                    class="
                        text-sm font-semibold
                        text-[#aab2cc]
                    "
                >
                    Nenhum evento comercial registrado
                </div>

                <div
                    class="
                        mt-1 text-xs
                        text-[#697394]
                    "
                >
                    As próximas movimentações serão
                    registradas automaticamente.
                </div>
            </div>

        @else

            <div class="mt-4">

                @foreach (
                    $commercialActivities
                    as $activity
                )

                    @php
                        $activityLabel =
                            match (
                                $activity->type
                            ) {
                                'hubspot_synced' =>
                                    'HubSpot',

                                'hubspot_snapshot' =>
                                    'Snapshot',

                                'hubspot_status_changed' =>
                                    'Status HubSpot',

                                'hubspot_stage_changed' =>
                                    'Etapa HubSpot',

                                'hubspot_task_changed' =>
                                    'Follow-up HubSpot',

                                'status_changed' =>
                                    'Status',

                                'note_updated' =>
                                    'Observação',

                                'follow_up_updated' =>
                                    'Próxima ação',

                                default =>
                                    'Atividade',
                            };

                        $dotClass =
                            match (
                                $activity->type
                            ) {
                                'hubspot_synced' =>
                                    'bg-emerald-300',

                                'hubspot_status_changed' =>
                                    'bg-cyan-300',

                                'hubspot_stage_changed' =>
                                    'bg-violet-300',

                                'hubspot_task_changed',
                                'follow_up_updated' =>
                                    'bg-amber-300',

                                'note_updated' =>
                                    'bg-blue-300',

                                default =>
                                    'bg-[#697394]',
                            };
                    @endphp


                    <div
                        class="
                            grid
                            grid-cols-[20px_1fr]
                            gap-3
                        "
                    >

                        <div
                            class="
                                relative flex
                                justify-center
                            "
                        >

                            <div
                                class="
                                    absolute bottom-0
                                    top-4 w-px
                                    bg-white/[0.06]
                                "
                            ></div>

                            <span
                                class="
                                    relative mt-1.5
                                    h-2.5 w-2.5
                                    rounded-full
                                    {{ $dotClass }}
                                "
                            ></span>

                        </div>


                        <div
                            class="
                                border-b
                                border-white/[0.05]
                                pb-4
                            "
                        >

                            <div
                                class="
                                    flex flex-wrap
                                    items-center gap-2
                                "
                            >

                                <span
                                    class="
                                        text-sm
                                        font-semibold
                                        text-[#e8ebf7]
                                    "
                                >
                                    {{ $activity->title }}
                                </span>

                                <span
                                    class="
                                        rounded-full
                                        bg-white/[0.04]
                                        px-2 py-0.5
                                        text-[9px]
                                        font-semibold
                                        uppercase
                                        tracking-wide
                                        text-[#8791b2]
                                    "
                                >
                                    {{ $activityLabel }}
                                </span>

                            </div>


                            @if (
                                $activity->description
                            )

                                <div
                                    class="
                                        mt-1
                                        text-xs
                                        leading-5
                                        text-[#929bb8]
                                    "
                                >
                                    {{ $activity->description }}
                                </div>

                            @endif


                            <div
                                class="
                                    mt-1.5 text-[10px]
                                    text-[#646e91]
                                "
                            >
                                {{
                                    $activity
                                        ->occurred_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}

                                ·

                                {{
                                    $activity
                                        ->user
                                        ?->name
                                    ?? 'Automático'
                                }}
                            </div>

                        </div>

                    </div>

                @endforeach

            </div>

        @endif

    </div>

</section>
