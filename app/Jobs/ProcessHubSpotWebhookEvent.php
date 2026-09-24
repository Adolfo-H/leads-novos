<?php

namespace App\Jobs;

use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotActivitySyncService;
use App\Services\HubSpotWebhookAssociationResolver;
use App\Services\HubSpotWebhookObjectTypeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessHubSpotWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public int $eventId,
    ) {
        $this->onConnection(
            'redis'
        );
    }

    public function uniqueId(): string
    {
        return (string)
            $this->eventId;
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
    ): void {
        $event =
            HubSpotWebhookEvent::query()
                ->find(
                    $this->eventId
                );

        if ($event === null) {
            return;
        }

        if (
            in_array(
                $event->status,
                [
                    'processed',
                    'ignored',
                ],
                true
            )
        ) {
            return;
        }

        $event->forceFill([
            'status' => 'processing',

            'attempts' => $event->attempts
                + 1,

            'error' => null,
        ])->save();

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
         * Primeiro tentamos resolver a empresa
         * pelas associações atuais do objeto.
         */
        $companyIds =
            $resolver->companyIds(
                objectType: $event->object_type,

                objectId: $event->object_id,
            );

        /*
         * Para atividades:
         *
         * - busca conteúdo completo no HubSpot;
         * - cria ou atualiza o histórico local;
         * - em exclusão, encontra também a
         *   associação já salva localmente.
         */
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
        }

        if ($companyIds === []) {
            $event->forceFill([
                'status' => 'ignored',

                'processed_at' => now(),
            ])->save();

            return;
        }

        /*
         * Depois da atividade local, atualizamos
         * o estado comercial completo da empresa:
         *
         * - tarefas;
         * - acompanhamento;
         * - negócios;
         * - etapa;
         * - score;
         * - prioridade.
         */
        foreach (
            $companyIds as $companyId
        ) {
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
            ->update([
                'status' => 'failed',

                'error' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    4000
                ),
            ]);
    }
}
