<?php

namespace App\Services;

final class HubSpotWebhookObjectTypeService
{
    /**
     * IDs padrão dos objetos CRM do HubSpot.
     *
     * @var array<string, string>
     */
    private const IDS = [
        '0-1' => 'contact',

        '0-2' => 'company',

        '0-3' => 'deal',

        '0-27' => 'task',

        '0-46' => 'note',

        '0-47' => 'meeting',

        '0-48' => 'call',

        '0-49' => 'email',
    ];

    /**
     * @var array<string, string>
     */
    private const NAMES = [
        'CONTACT' => 'contact',

        'COMPANY' => 'company',

        'DEAL' => 'deal',

        'TASK' => 'task',

        'NOTE' => 'note',

        'CALL' => 'call',

        'EMAIL' => 'email',

        'MEETING_EVENT' => 'meeting',

        'MEETING' => 'meeting',
    ];

    public function normalize(
        mixed $objectTypeId,
        mixed $objectType,
        mixed $subscriptionType,
    ): string {
        if (
            is_scalar(
                $objectTypeId
            )
        ) {
            $id =
                trim(
                    (string)
                    $objectTypeId
                );

            if (
                isset(
                    self::IDS[
                        $id
                    ]
                )
            ) {
                return self::IDS[
                    $id
                ];
            }
        }

        if (
            is_scalar(
                $objectType
            )
        ) {
            $name =
                mb_strtoupper(
                    trim(
                        (string)
                        $objectType
                    )
                );

            if (
                isset(
                    self::NAMES[
                        $name
                    ]
                )
            ) {
                return self::NAMES[
                    $name
                ];
            }
        }

        /*
         * Compatibilidade com eventos legados:
         *
         * deal.propertyChange
         * company.creation
         * contact.deletion
         */
        if (
            is_scalar(
                $subscriptionType
            )
        ) {
            $subscription =
                mb_strtolower(
                    trim(
                        (string)
                        $subscriptionType
                    )
                );

            foreach (
                [
                    'company',
                    'contact',
                    'deal',
                    'task',
                    'note',
                    'call',
                    'email',
                    'meeting',
                ] as $type
            ) {
                if (
                    str_starts_with(
                        $subscription,
                        $type.'.'
                    )
                ) {
                    return $type;
                }
            }
        }

        return 'unknown';
    }

    public function apiPlural(
        string $type
    ): ?string {
        return match ($type) {
            'company' => 'companies',

            'contact' => 'contacts',

            'deal' => 'deals',

            'task' => 'tasks',

            'note' => 'notes',

            'call' => 'calls',

            'email' => 'emails',

            'meeting' => 'meetings',

            default => null,
        };
    }

    public function isActivity(
        string $type
    ): bool {
        return in_array(
            $type,
            [
                'task',
                'note',
                'call',
                'email',
                'meeting',
            ],
            true
        );
    }
}
