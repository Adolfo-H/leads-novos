<?php

namespace App\Jobs;

use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotActivitySyncService;
use App\Services\HubSpotWebhookAssociationResolver;
use App\Services\HubSpotWebhookMirrorSyncService;
use App\Services\HubSpotWebhookObjectTypeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessHubSpotWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public int $eventId,
    ) {
        $this->onConnection(
            'redis'
        );

        /*
         * Webhooks nunca devem esperar
         * atrás de refreshes em massa.
         */
        $this->onQueue(
            'hubspot-webhooks'
        );
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (
                new WithoutOverlapping(
                    'hubspot-webhook-event-'
                    .$this->eventId
                )
            )
                ->dontRelease()
                ->expireAfter(
                    $this->timeout + 60
                ),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            15,
            60,
            180,
            600,
        ];
    }

    public function handle(
        HubSpotWebhookAssociationResolver $resolver,
        HubSpotWebhookObjectTypeService $types,
        HubSpotActivitySyncService $activities,
        HubSpotWebhookMirrorSyncService $mirror,
    ): void {
        /*
         * CLAIM ATÔMICO
         * -------------
         *
         * Mesmo se dois jobs do mesmo evento
         * chegarem ao worker, somente um pode
         * mudar queued -> processing.
         *
         * WithoutOverlapping
         * continuam como camadas adicionais.
         */
        $event =
            DB::transaction(
                function (): ?HubSpotWebhookEvent {
                    $event =
                        HubSpotWebhookEvent::query()
                            ->whereKey(
                                $this->eventId
                            )
                            ->lockForUpdate()
                            ->first();

                    if ($event === null) {
                        return null;
                    }

                    if (
                        in_array(
                            $event->status,
                            [
                                'processed',
                                'ignored',
                                'blocked_scope',
                            ],
                            true
                        )
                    ) {
                        return null;
                    }

                    /* WEBHOOK_RETRY_PROCESSING_V1
                     * O retry do mesmo job pode assumir processing.
                     * Primeiras entregas duplicadas continuam bloqueadas.
                     * WithoutOverlapping impede execução simultânea.
                     */
                    if (
                        $event->status === 'processing'
                        && $this->attempts() <= 1
                    ) {
                        return null;
                    }

                    if (
                        ! in_array(
                            $event->status,
                            [
                                'queued',
                                'received',
                                'failed',
                                'processing',
                            ],
                            true
                        )
                    ) {
                        return null;
                    }

                    $event->forceFill([
                        'status' => 'processing',

                        'attempts' => $event->attempts
                            + 1,

                        'error' => null,

                        'processed_at' => null,
                    ])->save();

                    return $event;
                }
            );

        if ($event === null) {
            return;
        }

        if (
            $event->object_type
            === 'unknown'
        ) {
            $event->forceFill([
                'status' => 'ignored',

                'processed_at' => now(),
            ])->save();

            return;
        }

        /*
         * Primeiro preservamos qualquer Company
         * fiscal já conhecida antes de alterar
         * ou remover objetos do mirror.
         */
        $companyIds =
            $resolver->companyIds(
                objectType: $event->object_type,

                objectId: $event->object_id,
            );

        /*
         * Atualiza Company / Deal / Contact /
         * Task no espelho local mesmo quando
         * ainda não conhecemos o CNPJ.
         */
        $mirrorHandled =
            $mirror->syncEvent(
                $event
            );

        /*
         * O mirror pode ter acabado de criar ou
         * corrigir associações.
         *
         * Resolvemos novamente e agregamos.
         */
        $companyIds =
            array_values(
                array_unique(
                    array_merge(
                        $companyIds,

                        $resolver->companyIds(
                            objectType: $event->object_type,

                            objectId: $event->object_id,
                        )
                    )
                )
            );

        /*
         * Atividades:
         *
         * - busca conteúdo completo no HubSpot;
         * - cria/atualiza histórico local;
         * - em exclusão, usa também associação
         *   previamente salva.
         */
        $activityHandled =
            false;

        if (
            $types->isActivity(
                $event->object_type
            )
        ) {
            $companyIds =
                $activities->syncEvent(
                    event: $event,

                    companyIds: $companyIds,
                );

            $activityHandled =
                true;
        }

        if (
            $companyIds === []
        ) {
            /*
             * Se o mirror foi atualizado,
             * o evento foi útil mesmo sem CNPJ.
             */
            $event->forceFill([
                'status' => (
                    $mirrorHandled
                    || $activityHandled
                )
                        ? 'processed'
                        : 'ignored',

                'processed_at' => now(),
            ])->save();

            return;
        }

        /*
         * Atualiza o estado comercial completo
         * das empresas relacionadas.
         */
        foreach (
            $companyIds as $companyId
        ) {
            /*
             * Alteração de tarefa é o caso mais
             * frequente da operação comercial.
             *
             * Não precisamos reconstruir todo o
             * CRM para mudar:
             *
             * - data;
             * - assunto;
             * - status;
             * - conclusão;
             * - criação/exclusão.
             */
            if (
                $event->object_type
                === 'task'
            ) {
                SyncHubSpotCompanyRealtimeStatus::dispatch(
                    $companyId
                );

                continue;
            }

            /*
             * Empresa, negócio, contato ou
             * atividade estrutural continuam
             * usando refresh completo, porém
             * na fila realtime.
             */
            RefreshCompanyFromHubSpot::dispatch(
                $companyId
            );
        }

        $event->forceFill([
            'status' => 'processed',

            'processed_at' => now(),
        ])->save();
    }

    public function failed(
        Throwable $exception
    ): void {
        HubSpotWebhookEvent::query()
            ->whereKey(
                $this->eventId
            )
            ->where(
                'status',
                'processing'
            )
            ->update([
                'status' => 'failed',

                'error' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    4000
                ),

                'processed_at' => null,
            ]);
    }
}
