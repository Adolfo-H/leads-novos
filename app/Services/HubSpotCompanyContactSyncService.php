<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HubSpotCompanyContactSyncService
{
    /**
     * Sincroniza TODOS os canais cadastrais.
     *
     * Estratégia:
     *
     * - 1 Contact por e-mail único;
     * - phone + mobilephone quando disponíveis;
     * - contatos somente com telefone quando
     *   não existe e-mail correspondente;
     * - nenhum e-mail/telefone único é perdido.
     *
     * @param  callable(int, int): void|null  $onProgress
     * @return list<array{
     *     id: string,
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    public function sync(
        Company $company,
        string $hubSpotCompanyId,
        ?string $hubSpotDealId = null,
        ?string $hubSpotTaskId = null,
        ?string $ownerId = null,
        ?callable $onProgress = null,
    ): array {
        $company->loadMissing(
            'establishments'
        );

        $candidates =
            $this->candidates(
                $company
            );

        $total =
            count(
                $candidates
            );

        $contacts = [];

        foreach (
            $candidates as $index => $candidate
        ) {
            $contactId =
                $this->resolve(
                    company: $company,

                    email: $candidate['email'],

                    phone: $candidate['phone'],

                    mobilePhone: $candidate['mobilephone'],

                    ownerId: $ownerId,
                );

            $this->associate(
                fromType: 'companies',

                fromId: $hubSpotCompanyId,

                toType: 'contacts',

                toId: $contactId,
            );

            if (
                $hubSpotDealId !== null
                && trim(
                    $hubSpotDealId
                ) !== ''
            ) {
                $this->associate(
                    fromType: 'contacts',

                    fromId: $contactId,

                    toType: 'deals',

                    toId: $hubSpotDealId,
                );
            }

            if (
                $hubSpotTaskId !== null
                && trim(
                    $hubSpotTaskId
                ) !== ''
            ) {
                $this->associate(
                    fromType: 'tasks',

                    fromId: $hubSpotTaskId,

                    toType: 'contacts',

                    toId: $contactId,
                );
            }

            $contacts[] = [
                'id' => $contactId,

                'email' => $candidate[
                        'email'
                    ],

                'phone' => $candidate[
                        'phone'
                    ],

                'mobilephone' => $candidate[
                        'mobilephone'
                    ],
            ];

            if ($onProgress !== null) {
                $onProgress(
                    $index + 1,
                    $total,
                );
            }
        }

        return $contacts;
    }

    /**
     * @return list<array{
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    public function candidates(
        Company $company
    ): array {
        $company->loadMissing(
            'establishments'
        );

        $establishments =
            $company
                ->establishments
                ->sortByDesc(
                    function (
                        $establishment
                    ): int {
                        $score = 0;

                        if (
                            $establishment->type
                            === 'matrix'
                        ) {
                            $score += 10;
                        }

                        if (
                            $establishment
                                ->registration_status_code
                            === '02'
                        ) {
                            $score += 5;
                        }

                        return $score;
                    }
                );

        /**
         * @var array<string, array{
         *     email: string,
         *     phones: array<string, string>
         * }>
         */
        $emailGroups = [];

        /**
         * @var list<array<string, string>>
         */
        $phoneOnlyGroups = [];

        /**
         * @var array<string, string>
         */
        $allPhones = [];

        foreach (
            $establishments as $establishment
        ) {
            $email =
                $this->email(
                    $establishment->email
                );

            $phones =
                $this->phones([
                    $establishment->phone_1,
                    $establishment->phone_2,
                ]);

            foreach (
                $phones as $digits => $phone
            ) {
                $allPhones[
                    $digits
                ] =
                    $phone;
            }

            if ($email !== null) {
                if (
                    ! isset(
                        $emailGroups[
                            $email
                        ]
                    )
                ) {
                    $emailGroups[
                        $email
                    ] = [
                        'email' => $email,

                        'phones' => [],
                    ];
                }

                foreach (
                    $phones as $digits => $phone
                ) {
                    $emailGroups[
                        $email
                    ][
                        'phones'
                    ][
                        $digits
                    ] =
                        $phone;
                }

                continue;
            }

            if ($phones !== []) {
                $phoneOnlyGroups[] =
                    $phones;
            }
        }

        $contacts = [];

        /**
         * Telefones já armazenados em algum
         * Contact com e-mail ou contact-only.
         *
         * @var array<string, true>
         */
        $usedPhones = [];

        /*
         * Primeiro preservamos todos os e-mails.
         */
        foreach (
            $emailGroups as $group
        ) {
            $phones =
                array_values(
                    $group[
                        'phones'
                    ]
                );

            $phone =
                $phones[0]
                ?? null;

            $mobilePhone =
                $phones[1]
                ?? null;

            foreach (
                array_slice(
                    array_keys(
                        $group[
                            'phones'
                        ]
                    ),
                    0,
                    2
                ) as $digits
            ) {
                $usedPhones[
                    $digits
                ] =
                    true;
            }

            $contacts[] = [
                'email' => $group[
                        'email'
                    ],

                'phone' => $phone,

                'mobilephone' => $mobilePhone,
            ];
        }

        /*
         * Estabelecimentos sem e-mail:
         * até dois telefones no mesmo Contact.
         */
        foreach (
            $phoneOnlyGroups as $phones
        ) {
            $remaining = [];

            foreach (
                $phones as $digits => $phone
            ) {
                if (
                    isset(
                        $usedPhones[
                            $digits
                        ]
                    )
                ) {
                    continue;
                }

                $remaining[
                    $digits
                ] =
                    $phone;
            }

            if ($remaining === []) {
                continue;
            }

            $digits =
                array_keys(
                    $remaining
                );

            $values =
                array_values(
                    $remaining
                );

            $phone =
                $values[0];

            $mobilePhone =
                $values[1]
                ?? null;

            foreach (
                array_slice(
                    $digits,
                    0,
                    2
                ) as $key
            ) {
                $usedPhones[
                    $key
                ] =
                    true;
            }

            $contacts[] = [
                'email' => null,

                'phone' => $phone,

                'mobilephone' => $mobilePhone,
            ];
        }

        /*
         * Telefones extras:
         *
         * garante que mesmo o terceiro/quarto
         * telefone de um mesmo e-mail não seja
         * perdido por falta de campo padrão.
         */
        foreach (
            $allPhones as $digits => $phone
        ) {
            if (
                isset(
                    $usedPhones[
                        $digits
                    ]
                )
            ) {
                continue;
            }

            $usedPhones[
                $digits
            ] =
                true;

            $contacts[] = [
                'email' => null,

                'phone' => $phone,

                'mobilephone' => null,
            ];
        }

        return $contacts;
    }

    private function resolve(
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
            /*
             * Contato existente mantém owner.
             *
             * Apenas preenchemos campos que
             * ainda estão vazios no HubSpot.
             */
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
            $properties[
                'email'
            ] =
                $email;
        } else {
            /*
             * Sem e-mail, adicionamos um nome
             * identificável no HubSpot.
             */
            $properties[
                'firstname'
            ] =
                'Contato cadastral';

            $properties[
                'lastname'
            ] =
                mb_substr(
                    $company
                        ->corporate_name,
                    0,
                    100
                );
        }

        if (
            $phone !== null
            && trim(
                $phone
            ) !== ''
        ) {
            $properties[
                'phone'
            ] =
                $phone;
        }

        if (
            $mobilePhone !== null
            && trim(
                $mobilePhone
            ) !== ''
        ) {
            $properties[
                'mobilephone'
            ] =
                $mobilePhone;
        }

        if ($ownerId !== null) {
            $properties[
                'hubspot_owner_id'
            ] =
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
            $this->client()
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
                            && trim(
                                $value
                            ) !== ''
                    )
                )
            );

        if ($values === []) {
            return null;
        }

        $filterGroups = [];

        foreach (
            $values as $value
        ) {
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
            $this->client()
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
            $this->client()
                ->get(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/'
                    .rawurlencode(
                        $contactId
                    ),
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
            is_array(
                $data
            )
                ? (
                    $data[
                        'properties'
                    ]
                    ?? []
                )
                : [];

        if (! is_array($properties)) {
            $properties = [];
        }

        $updates = [];

        if (
            trim(
                (string) (
                    $properties[
                        'company'
                    ]
                    ?? ''
                )
            ) === ''
        ) {
            $updates[
                'company'
            ] =
                $company
                    ->corporate_name;
        }

        if (
            $phone !== null
            && trim(
                $phone
            ) !== ''
            && trim(
                (string) (
                    $properties[
                        'phone'
                    ]
                    ?? ''
                )
            ) === ''
        ) {
            $updates[
                'phone'
            ] =
                $phone;
        }

        if (
            $mobilePhone !== null
            && trim(
                $mobilePhone
            ) !== ''
            && trim(
                (string) (
                    $properties[
                        'mobilephone'
                    ]
                    ?? ''
                )
            ) === ''
        ) {
            $updates[
                'mobilephone'
            ] =
                $mobilePhone;
        }

        if ($updates === []) {
            return;
        }

        $response =
            $this->client()
                ->patch(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/'
                    .rawurlencode(
                        $contactId
                    ),
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
            $this->client()
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
            is_array(
                $data
            )
                ? (
                    $data[
                        'id'
                    ]
                    ?? null
                )
                : null;

        if (! is_scalar($id)) {
            throw new RuntimeException(
                'O HubSpot não retornou '
                .'o ID do contato.'
            );
        }

        return (string) $id;
    }

    private function associate(
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
    ): void {
        $response =
            $this->client()
                ->put(
                    $this->baseUrl()
                    .'/crm/v4/objects/'
                    .$fromType
                    .'/'
                    .rawurlencode(
                        $fromId
                    )
                    .'/associations/default/'
                    .$toType
                    .'/'
                    .rawurlencode(
                        $toId
                    )
                );

        $this->ensureSuccess(
            $response,
            'associar contato'
        );
    }

    private function singleId(
        Response $response
    ): ?string {
        $data =
            $response->json();

        $results =
            is_array(
                $data
            )
                ? (
                    $data[
                        'results'
                    ]
                    ?? []
                )
                : [];

        if (! is_array($results)) {
            return null;
        }

        $ids = [];

        foreach (
            $results as $result
        ) {
            if (! is_array($result)) {
                continue;
            }

            $id =
                $result[
                    'id'
                ]
                ?? null;

            if (
                is_scalar(
                    $id
                )
                && trim(
                    (string) $id
                ) !== ''
            ) {
                $ids[] =
                    trim(
                        (string) $id
                    );
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
                'Mais de um contato compatível '
                .'foi encontrado no HubSpot. '
                .'A sincronização foi interrompida '
                .'para evitar associação ambígua.'
            );
        }

        return $ids[0]
            ?? null;
    }

    private function email(
        mixed $value
    ): ?string {
        $email =
            mb_strtolower(
                trim(
                    (string) $value
                )
            );

        return
            $email !== ''
            && filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) !== false
                ? $email
                : null;
    }

    /**
     * @param  list<mixed>  $values
     * @return array<string, string>
     */
    private function phones(
        array $values
    ): array {
        $phones = [];

        foreach (
            $values as $value
        ) {
            $phone =
                trim(
                    (string) $value
                );

            if ($phone === '') {
                continue;
            }

            $digits =
                preg_replace(
                    '/\D/',
                    '',
                    $phone
                );

            if (
                $digits === null
                || $digits === ''
            ) {
                continue;
            }

            $phones[
                $digits
            ] =
                $phone;
        }

        return $phones;
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

    private function client(): PendingRequest
    {
        $token =
            trim(
                (string) config(
                    'services.hubspot.access_token'
                )
            );

        if ($token === '') {
            throw new RuntimeException(
                'Token do HubSpot não configurado.'
            );
        }

        return Http::withToken(
            $token
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30);
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
