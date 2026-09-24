<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotWebhookObjectTypeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class HubSpotWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        HubSpotWebhookObjectTypeService $types,
    ): JsonResponse {
        $decoded =
            json_decode(
                $request->getContent(),
                true
            );

        if (! is_array($decoded)) {
            return response()->json(
                [
                    'message' => 'Invalid payload.',
                ],
                400
            );
        }

        $payloads =
            array_is_list(
                $decoded
            )
                ? $decoded
                : [
                    $decoded,
                ];

        $accepted = 0;
        $duplicates = 0;
        $invalid = 0;

        foreach ($payloads as $payload) {
            if (! is_array($payload)) {
                $invalid++;

                continue;
            }

            $objectId =
                $this->scalarString(
                    $payload[
                        'objectId'
                    ]
                    ?? null
                );

            $subscriptionType =
                $this->scalarString(
                    $payload[
                        'subscriptionType'
                    ]
                    ?? null
                );

            if (
                $objectId === null
                || $subscriptionType === null
            ) {
                $invalid++;

                continue;
            }

            $objectTypeId =
                $this->scalarString(
                    $payload[
                        'objectTypeId'
                    ]
                    ?? null
                );

            $objectType =
                $types->normalize(
                    objectTypeId: $objectTypeId,

                    objectType: $payload[
                            'objectType'
                        ]
                        ?? null,

                    subscriptionType: $subscriptionType,
                );

            $eventKey =
                $this->eventKey(
                    $payload
                );

            $event =
                HubSpotWebhookEvent::query()
                    ->firstOrCreate(
                        [
                            'event_key' => $eventKey,
                        ],
                        [
                            'portal_id' => $this->scalarString(
                                $payload[
                                    'portalId'
                                ]
                                ?? null
                            ),

                            'app_id' => $this->scalarString(
                                $payload[
                                    'appId'
                                ]
                                ?? null
                            ),

                            'subscription_id' => $this->scalarString(
                                $payload[
                                    'subscriptionId'
                                ]
                                ?? null
                            ),

                            'subscription_type' => $subscriptionType,

                            'object_type' => $objectType,

                            'object_type_id' => $objectTypeId,

                            'object_id' => $objectId,

                            'property_name' => $this->scalarString(
                                $payload[
                                    'propertyName'
                                ]
                                ?? null
                            ),

                            'occurred_at' => $this->occurredAt(
                                $payload[
                                    'occurredAt'
                                ]
                                ?? null
                            ),

                            'status' => 'received',

                            'payload' => $payload,
                        ]
                    );

            if (
                ! $event
                    ->wasRecentlyCreated
            ) {
                $duplicates++;

                continue;
            }

            ProcessHubSpotWebhookEvent::dispatch(
                $event->id
            );

            $accepted++;
        }

        return response()->json(
            [
                'accepted' => $accepted,

                'duplicates' => $duplicates,

                'invalid' => $invalid,
            ],
            202
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventKey(
        array $payload
    ): string {
        $parts = [
            $payload['appId']
                ?? null,

            $payload['eventId']
                ?? null,

            $payload['subscriptionId']
                ?? null,

            $payload['portalId']
                ?? null,

            $payload['subscriptionType']
                ?? null,

            $payload['objectTypeId']
                ?? null,

            $payload['objectId']
                ?? null,

            $payload['propertyName']
                ?? null,

            $payload['propertyValue']
                ?? null,

            $payload['occurredAt']
                ?? null,
        ];

        return hash(
            'sha256',
            json_encode(
                $parts,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            )
                ?: ''
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

    private function occurredAt(
        mixed $value
    ): ?CarbonImmutable {
        if (
            ! is_scalar($value)
        ) {
            return null;
        }

        try {
            if (
                is_numeric(
                    $value
                )
            ) {
                $number =
                    (int) $value;

                return
                    $number
                    > 100000000000
                        ? CarbonImmutable::createFromTimestampMs(
                            $number
                        )
                        : CarbonImmutable::createFromTimestamp(
                            $number
                        );
            }

            return CarbonImmutable::parse(
                (string) $value
            );
        } catch (Throwable) {
            return null;
        }
    }
}
