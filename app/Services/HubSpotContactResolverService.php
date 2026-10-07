<?php

namespace App\Services;

use App\Models\Company;
use App\Support\HubSpotHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class HubSpotContactResolverService
{
    public function resolve(
        Company $company,
        ?string $email,
        ?string $phone,
        ?string $mobilePhone,
        ?string $ownerId,
    ): string {
        $existing =
            $email !== null
                ? $this->findByEmail(
                    $email
                )
                : $this->findByPhone(
                    $phone,
                    $mobilePhone,
                );

        if ($existing !== null) {
            $this->fillMissingChannels(
                contactId: $existing,
                company: $company,
                phone: $phone,
                mobilePhone: $mobilePhone,
            );

            return $existing;
        }

        $properties = [
            'company' => $company
                ->corporate_name,
        ];

        if ($email !== null) {
            $properties['email'] =
                $email;
        } else {
            $properties['firstname'] =
                'Contato cadastral';

            $properties['lastname'] =
                mb_substr(
                    $company
                        ->corporate_name,
                    0,
                    100
                );
        }

        if (
            $phone !== null
            && trim($phone) !== ''
        ) {
            $properties['phone'] =
                $phone;
        }

        if (
            $mobilePhone !== null
            && trim($mobilePhone) !== ''
        ) {
            $properties['mobilephone'] =
                $mobilePhone;
        }

        if (
            $ownerId !== null
            && trim($ownerId) !== ''
        ) {
            $properties['hubspot_owner_id'] =
                $ownerId;
        }

        return $this->createContact(
            $properties
        );
    }

    private function findByEmail(
        string $email
    ): ?string {
        $response =
            $this->searchClient()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/search',
                    [
                        'filterGroups' => [
                            [
                                'filters' => [
                                    [
                                        'propertyName' => 'email',
                                        'operator' => 'EQ',
                                        'value' => $email,
                                    ],
                                ],
                            ],
                        ],
                        'properties' => [
                            'email',
                            'phone',
                            'mobilephone',
                        ],
                        'limit' => 10,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar contato por e-mail'
        );

        return $this->singleId(
            $response
        );
    }

    private function findByPhone(
        ?string $phone,
        ?string $mobilePhone,
    ): ?string {
        $values =
            array_values(
                array_unique(
                    array_filter(
                        [
                            $phone,
                            $mobilePhone,
                        ],
                        static fn (
                            ?string $value
                        ): bool => $value !== null
                            && trim($value) !== ''
                    )
                )
            );

        if ($values === []) {
            return null;
        }

        $filterGroups = [];

        foreach ($values as $value) {
            foreach (
                [
                    'phone',
                    'mobilephone',
                ] as $property
            ) {
                $filterGroups[] = [
                    'filters' => [
                        [
                            'propertyName' => $property,
                            'operator' => 'EQ',
                            'value' => $value,
                        ],
                    ],
                ];
            }
        }

        $response =
            $this->searchClient()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/search',
                    [
                        'filterGroups' => $filterGroups,
                        'properties' => [
                            'email',
                            'phone',
                            'mobilephone',
                        ],
                        'limit' => 10,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar contato por telefone'
        );

        return $this->singleId(
            $response
        );
    }

    private function fillMissingChannels(
        string $contactId,
        Company $company,
        ?string $phone,
        ?string $mobilePhone,
    ): void {
        $response =
            $this->readClient()
                ->get(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/'
                    .rawurlencode($contactId),
                    [
                        'properties' => implode(
                            ',',
                            [
                                'company',
                                'phone',
                                'mobilephone',
                            ]
                        ),
                    ]
                );

        $this->ensureSuccess(
            $response,
            'ler contato existente'
        );

        $data =
            $response->json();

        $properties =
            is_array($data)
                ? ($data['properties'] ?? [])
                : [];

        if (! is_array($properties)) {
            $properties = [];
        }

        $updates = [];

        if (
            trim(
                (string) (
                    $properties['company']
                    ?? ''
                )
            ) === ''
        ) {
            $updates['company'] =
                $company->corporate_name;
        }

        if (
            $phone !== null
            && trim($phone) !== ''
            && trim(
                (string) (
                    $properties['phone']
                    ?? ''
                )
            ) === ''
        ) {
            $updates['phone'] =
                $phone;
        }

        if (
            $mobilePhone !== null
            && trim($mobilePhone) !== ''
            && trim(
                (string) (
                    $properties['mobilephone']
                    ?? ''
                )
            ) === ''
        ) {
            $updates['mobilephone'] =
                $mobilePhone;
        }

        if ($updates === []) {
            return;
        }

        $response =
            $this->writeClient()
                ->patch(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/'
                    .rawurlencode($contactId),
                    [
                        'properties' => $updates,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'completar contato existente'
        );
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function createContact(
        array $properties
    ): string {
        $response =
            $this->writeClient()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts',
                    [
                        'properties' => $properties,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'criar contato'
        );

        $data =
            $response->json();

        $id =
            is_array($data)
                ? ($data['id'] ?? null)
                : null;

        if (
            ! is_scalar($id)
            || trim((string) $id) === ''
        ) {
            throw new RuntimeException(
                'O HubSpot não retornou o ID do contato.'
            );
        }

        return trim(
            (string) $id
        );
    }

    private function singleId(
        Response $response
    ): ?string {
        $data =
            $response->json();

        $results =
            is_array($data)
                ? ($data['results'] ?? [])
                : [];

        if (! is_array($results)) {
            return null;
        }

        $ids = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $id =
                $result['id']
                ?? null;

            if (! is_scalar($id)) {
                continue;
            }

            $id =
                trim(
                    (string) $id
                );

            if ($id !== '') {
                $ids[] =
                    $id;
            }
        }

        $ids =
            array_values(
                array_unique(
                    $ids
                )
            );

        if (count($ids) > 1) {
            throw new RuntimeException(
                'Mais de um contato compatível foi encontrado no HubSpot. '
                .'A sincronização foi interrompida para evitar associação ambígua.'
            );
        }

        return $ids[0]
            ?? null;
    }

    private function searchClient(): PendingRequest
    {
        return HubSpotHttpClient::make(
            asJson: true,
            retryMethods: [
                'GET',
                'POST',
            ],
        );
    }

    private function readClient(): PendingRequest
    {
        return HubSpotHttpClient::make(
            asJson: true,
        );
    }

    private function writeClient(): PendingRequest
    {
        return HubSpotHttpClient::make(
            asJson: true,
        );
    }

    private function baseUrl(): string
    {
        $url =
            rtrim(
                (string) config(
                    'services.hubspot.base_url'
                ),
                '/'
            );

        if ($url === '') {
            throw new RuntimeException(
                'HUBSPOT_BASE_URL não configurada.'
            );
        }

        return $url;
    }

    private function ensureSuccess(
        Response $response,
        string $operation,
    ): void {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException(
            'Erro ao '
            .$operation
            .' no HubSpot. HTTP '
            .$response->status()
            .': '
            .mb_substr(
                $response->body(),
                0,
                1000
            )
        );
    }
}
