<?php

namespace App\Services;

use App\Models\HubSpotWebhookEvent;

final class HubSpotWebhookEventContextService
{
    public function __construct(
        private readonly HubSpotWebhookObjectTypeService $types,
    ) {}

    /**
     * Retorna todos os objetos envolvidos
     * no evento.
     *
     * Um evento comum terá normalmente
     * apenas um objeto.
     *
     * Um associationChange pode trazer:
     *
     * - objeto principal;
     * - fromObject;
     * - toObject.
     *
     * @return list<array{
     *     type: string,
     *     id: string
     * }>
     */
    public function references(
        HubSpotWebhookEvent $event
    ): array {
        $references = [];

        $this->pushReference(
            references: $references,

            type: trim(
                (string)
                $event->object_type
            ),

            id: $this->scalarString(
                $event->object_id
            ),
        );

        $payload =
            $event->getAttribute(
                'payload'
            );

        if (! is_array($payload)) {
            return $this->unique(
                $references
            );
        }

        $subscriptionType =
            trim(
                (string)
                $event->subscription_type
            );

        /*
         * FROM
         */
        $fromObjectTypeId =
            $this->scalarString(
                $payload[
                    'fromObjectTypeId'
                ]
                ?? null
            );

        $fromObjectId =
            $this->scalarString(
                $payload[
                    'fromObjectId'
                ]
                ?? null
            );

        if (
            $fromObjectId !== null
        ) {
            $fromType =
                $this
                    ->types
                    ->normalize(
                        objectTypeId: $fromObjectTypeId,

                        objectType: $payload[
                                'fromObjectType'
                            ]
                            ?? null,

                        subscriptionType: $subscriptionType,
                    );

            $this->pushReference(
                references: $references,

                type: $fromType,

                id: $fromObjectId,
            );
        }

        /*
         * TO
         */
        $toObjectTypeId =
            $this->scalarString(
                $payload[
                    'toObjectTypeId'
                ]
                ?? null
            );

        $toObjectId =
            $this->scalarString(
                $payload[
                    'toObjectId'
                ]
                ?? null
            );

        if (
            $toObjectId !== null
        ) {
            $toType =
                $this
                    ->types
                    ->normalize(
                        objectTypeId: $toObjectTypeId,

                        objectType: $payload[
                                'toObjectType'
                            ]
                            ?? null,

                        subscriptionType: $subscriptionType,
                    );

            $this->pushReference(
                references: $references,

                type: $toType,

                id: $toObjectId,
            );
        }

        return $this->unique(
            $references
        );
    }

    /**
     * Retorna somente referências que
     * representam atividades comerciais.
     *
     * @return list<array{
     *     type: string,
     *     id: string
     * }>
     */
    public function activityReferences(
        HubSpotWebhookEvent $event
    ): array {
        return array_values(
            array_filter(
                $this->references(
                    $event
                ),
                fn (
                    array $reference
                ): bool => $this
                    ->types
                    ->isActivity(
                        $reference[
                            'type'
                        ]
                    )
            )
        );
    }

    /**
     * @param  list<array{
     *     type: string,
     *     id: string
     * }>  $references
     */
    private function pushReference(
        array &$references,
        string $type,
        ?string $id,
    ): void {
        if (
            $type === ''
            || $type === 'unknown'
            || $id === null
        ) {
            return;
        }

        $references[] = [
            'type' => $type,

            'id' => $id,
        ];
    }

    /**
     * @param  list<array{
     *     type: string,
     *     id: string
     * }>  $references
     * @return list<array{
     *     type: string,
     *     id: string
     * }>
     */
    private function unique(
        array $references
    ): array {
        $unique = [];

        foreach (
            $references as $reference
        ) {
            $key =
                $reference[
                    'type'
                ]
                .'|'
                .$reference[
                    'id'
                ];

            $unique[
                $key
            ] =
                $reference;
        }

        return array_values(
            $unique
        );
    }

    private function scalarString(
        mixed $value
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        return $value !== ''
            ? $value
            : null;
    }
}
