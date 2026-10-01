<?php

namespace App\Services;

use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CompanyService
{
    /**
     * @param  array<string, mixed>  $companyData
     * @param  array<string, mixed>  $establishmentData
     * @param  array<int, array<string, mixed>>  $cnaes
     */
    public function createOrUpdateFromEstablishment(
        array $companyData,
        array $establishmentData,
        array $cnaes = [],
        bool $loadRelations = true,
        bool $replaceCnaes = false,
    ): Company {
        return DB::transaction(function () use (
            $companyData,
            $establishmentData,
            $cnaes,
            $loadRelations,
            $replaceCnaes,
        ): Company {
            if (
                empty(
                    $establishmentData[
                        'cnpj'
                    ]
                )
            ) {
                throw new InvalidArgumentException(
                    'O CNPJ do estabelecimento é obrigatório.'
                );
            }

            if (
                empty(
                    $companyData[
                        'corporate_name'
                    ]
                )
            ) {
                throw new InvalidArgumentException(
                    'A razão social é obrigatória.'
                );
            }

            $cnpj =
                Cnpj::normalize(
                    (string)
                        $establishmentData[
                            'cnpj'
                        ]
                );

            Cnpj::assertValid(
                $cnpj
            );

            $root =
                Cnpj::root(
                    $cnpj
                );

            /*
             * Dados recebidos por Receita/API
             * podem ser parciais.
             *
             * Uma atualização parcial não deve
             * zerar dados bons já existentes.
             */
            $company =
                Company::query()
                    ->where(
                        'cnpj_root',
                        $root
                    )
                    ->lockForUpdate()
                    ->first();

            if ($company === null) {
                $company =
                    Company::query()
                        ->create(
                            array_merge(
                                [
                                    'cnpj_root' => $root,
                                ],
                                $this
                                    ->companyValues(
                                        $companyData,
                                        creating: true,
                                    )
                            )
                        );
            } else {
                $company
                    ->fill(
                        $this
                            ->companyValues(
                                $companyData,
                                creating: false,
                            )
                    );

                $company->save();
            }

            /*
             * Também preservamos os dados
             * cadastrais existentes quando
             * uma fonte parcial não devolve
             * determinado campo.
             */
            $establishment =
                $company
                    ->establishments()
                    ->where(
                        'cnpj',
                        $cnpj
                    )
                    ->lockForUpdate()
                    ->first();

            $type =
                $establishmentData[
                    'type'
                ]
                ?? (
                    $establishment instanceof Establishment
                        ? $establishment->type
                        : 'matrix'
                );

            if (
                ! in_array(
                    $type,
                    [
                        'matrix',
                        'branch',
                    ],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'O tipo do estabelecimento deve ser matrix ou branch.'
                );
            }

            $establishmentValues =
                $this
                    ->establishmentValues(
                        $cnpj,
                        $type,
                        $establishmentData,
                        creating: $establishment
                            === null,
                    );

            if (
                $establishment
                === null
            ) {
                /** @var Establishment $establishment */
                $establishment =
                    $company
                        ->establishments()
                        ->create(
                            $establishmentValues
                        );
            } else {
                $establishment
                    ->fill(
                        $establishmentValues
                    );

                $establishment
                    ->save();
            }

            /*
             * replaceCnaes = true:
             *
             * a fonte informou o retrato completo
             * dos CNAEs daquele estabelecimento.
             *
             * Portanto CNAEs antigos que não
             * aparecem mais precisam sair.
             */
            if (
                $cnaes !== []
                || $replaceCnaes
            ) {
                $this
                    ->syncCnaes(
                        $establishment,
                        $cnaes,
                        replace: $replaceCnaes,
                    );
            }

            if (
                ! $loadRelations
            ) {
                return $company;
            }

            return $company
                ->fresh()
                ->load([
                    'establishments.cnaes',
                ]);
        });
    }

    /**
     * Dados de enriquecimento são parciais.
     *
     * Em empresas existentes:
     * null não apaga informação.
     *
     * Limpeza manual continua sendo feita
     * pelo fluxo específico de edição.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function companyValues(
        array $data,
        bool $creating,
    ): array {
        $values = [
            'corporate_name' => trim(
                (string)
                    $data[
                        'corporate_name'
                    ]
            ),
        ];

        foreach (
            [
                'legal_nature_code',
                'legal_nature_description',
                'responsible_qualification_code',
                'share_capital',
                'size_code',
                'size_description',
                'federative_entity',
                'metadata',
            ] as $field
        ) {
            if (
                ! array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $value =
                $data[
                    $field
                ];

            if (
                $value === null
                && ! $creating
            ) {
                continue;
            }

            if (
                is_string(
                    $value
                )
            ) {
                $value =
                    $this
                        ->nullableString(
                            $value
                        );

                if (
                    $value === null
                    && ! $creating
                ) {
                    continue;
                }
            }

            $values[
                $field
            ] = $value;
        }

        if (
            array_key_exists(
                'source',
                $data
            )
            && $data[
                'source'
            ] !== null
        ) {
            $values[
                'source'
            ] =
                (string)
                    $data[
                        'source'
                    ];
        } elseif ($creating) {
            $values[
                'source'
            ] = 'manual';
        }

        if (
            array_key_exists(
                'source_updated_at',
                $data
            )
            && $data[
                'source_updated_at'
            ] !== null
        ) {
            $values[
                'source_updated_at'
            ] =
                $data[
                    'source_updated_at'
                ];
        } elseif ($creating) {
            $values[
                'source_updated_at'
            ] = now();
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function establishmentValues(
        string $cnpj,
        string $type,
        array $data,
        bool $creating,
    ): array {
        $values = [
            'cnpj' => $cnpj,

            'order_number' => Cnpj::order(
                $cnpj
            ),

            'check_digits' => Cnpj::checkDigits(
                $cnpj
            ),

            'type' => $type,
        ];

        foreach (
            [
                'fantasy_name',
                'registration_status_code',
                'registration_status',
                'registration_status_reason_code',
                'foreign_city_name',
                'country_code',
                'address_type',
                'street',
                'number',
                'complement',
                'neighborhood',
                'zip_code',
                'municipality_code',
                'municipality_name',
                'phone_1',
                'phone_2',
                'fax',
                'special_situation',
            ] as $field
        ) {
            if (
                ! array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $value =
                $this
                    ->nullableString(
                        $data[
                            $field
                        ]
                    );

            if (
                $value === null
                && ! $creating
            ) {
                continue;
            }

            $values[
                $field
            ] = $value;
        }

        foreach (
            [
                'registration_status_date',
                'start_date',
                'special_situation_date',
                'metadata',
            ] as $field
        ) {
            if (
                ! array_key_exists(
                    $field,
                    $data
                )
            ) {
                continue;
            }

            $value =
                $data[
                    $field
                ];

            if (
                $value === null
                && ! $creating
            ) {
                continue;
            }

            $values[
                $field
            ] = $value;
        }

        if (
            array_key_exists(
                'state',
                $data
            )
        ) {
            $state =
                $this
                    ->nullableString(
                        $data[
                            'state'
                        ]
                    );

            if (
                $state !== null
                || $creating
            ) {
                $values[
                    'state'
                ] =
                    $state !== null
                        ? mb_strtoupper(
                            $state
                        )
                        : null;
            }
        }

        if (
            array_key_exists(
                'email',
                $data
            )
        ) {
            $email =
                $this
                    ->nullableString(
                        $data[
                            'email'
                        ]
                    );

            if (
                $email !== null
                || $creating
            ) {
                $values[
                    'email'
                ] =
                    $email !== null
                        ? mb_strtolower(
                            $email
                        )
                        : null;
            }
        }

        if (
            array_key_exists(
                'source',
                $data
            )
            && $data[
                'source'
            ] !== null
        ) {
            $values[
                'source'
            ] =
                (string)
                    $data[
                        'source'
                    ];
        } elseif ($creating) {
            $values[
                'source'
            ] = 'manual';
        }

        if (
            array_key_exists(
                'source_updated_at',
                $data
            )
            && $data[
                'source_updated_at'
            ] !== null
        ) {
            $values[
                'source_updated_at'
            ] =
                $data[
                    'source_updated_at'
                ];
        } elseif ($creating) {
            $values[
                'source_updated_at'
            ] = now();
        }

        return $values;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cnaes
     */
    private function syncCnaes(
        Establishment $establishment,
        array $cnaes,
        bool $replace,
    ): void {
        /**
         * @var array<int, array{
         *     is_primary: bool
         * }> $sync
         */
        $sync = [];

        $primaryAssigned =
            false;

        foreach (
            $cnaes as $cnaeData
        ) {
            if (
                empty(
                    $cnaeData[
                        'code'
                    ]
                )
            ) {
                continue;
            }

            $code =
                preg_replace(
                    '/\D/',
                    '',
                    (string)
                        $cnaeData[
                            'code'
                        ]
                );

            if (
                strlen(
                    (string)
                        $code
                ) !== 7
            ) {
                continue;
            }

            $cnae =
                Cnae::query()
                    ->firstOrNew([
                        'code' => $code,
                    ]);

            if (
                array_key_exists(
                    'description',
                    $cnaeData
                )
            ) {
                $description =
                    $this
                        ->nullableString(
                            $cnaeData[
                                'description'
                            ]
                        );

                /*
                 * Uma fonte parcial sem descrição
                 * não apaga uma descrição boa
                 * já cadastrada.
                 */
                if (
                    $description !== null
                    || ! $cnae->exists
                ) {
                    $cnae
                        ->description =
                            $description;
                }
            }

            $cnae->save();

            $requestedPrimary =
                (bool) (
                    $cnaeData[
                        'is_primary'
                    ]
                    ?? false
                );

            /*
             * Mesmo se a fonte vier errada,
             * somente o primeiro CNAE marcado
             * como principal será aceito.
             */
            $isPrimary =
                $requestedPrimary
                && ! $primaryAssigned;

            if ($isPrimary) {
                $primaryAssigned =
                    true;
            }

            $sync[
                $cnae->id
            ] = [
                'is_primary' => $isPrimary,
            ];
        }

        /*
         * Retrato autoritativo da fonte:
         *
         * remove CNAEs antigos que não
         * estão mais no payload.
         */
        if ($replace) {
            $establishment
                ->cnaes()
                ->sync(
                    $sync
                );

            return;
        }

        /*
         * Atualização incremental:
         *
         * caso chegue um novo principal,
         * desmarca qualquer principal anterior.
         */
        if (
            $primaryAssigned
        ) {
            $establishment
                ->cnaes()
                ->newPivotQuery()
                ->update([
                    'is_primary' => false,
                ]);
        }

        $establishment
            ->cnaes()
            ->syncWithoutDetaching(
                $sync
            );

        /*
         * Atualiza explicitamente o pivot
         * de CNAEs já existentes.
         */
        foreach (
            $sync as $cnaeId => $pivot
        ) {
            $establishment
                ->cnaes()
                ->updateExistingPivot(
                    $cnaeId,
                    $pivot,
                );
        }
    }

    private function nullableString(
        mixed $value,
    ): ?string {
        if (
            $value === null
        ) {
            return null;
        }

        $value =
            trim(
                (string)
                    $value
            );

        return $value === ''
            ? null
            : $value;
    }

    /**
     * @param  array<string, mixed>  $companyData
     * @param  array<string, mixed>  $establishmentData
     */
    public function updateCompanyAndMatrix(
        Company $company,
        array $companyData,
        array $establishmentData,
    ): Company {
        return DB::transaction(function () use (
            $company,
            $companyData,
            $establishmentData,
        ): Company {
            if (
                empty(
                    $companyData['corporate_name']
                )
            ) {
                throw new InvalidArgumentException(
                    'A razão social é obrigatória.'
                );
            }

            $establishment = $company
                ->establishments()
                ->where('type', 'matrix')
                ->first();

            /*
             * Empresas importadas poderão, em casos
             * excepcionais, chegar inicialmente sem
             * estabelecimento marcado como matriz.
             *
             * Nesse caso utilizamos o primeiro
             * estabelecimento até a regularização.
             */
            if (! $establishment) {
                $establishment = $company
                    ->establishments()
                    ->orderBy('id')
                    ->first();
            }

            if (! $establishment) {
                throw new InvalidArgumentException(
                    'A empresa não possui estabelecimento cadastrado.'
                );
            }

            $company->fill([
                'corporate_name' => trim(
                    $companyData[
                        'corporate_name'
                    ]
                ),

                'legal_nature_code' => $companyData[
                        'legal_nature_code'
                    ] ?? null,

                'legal_nature_description' => $companyData[
                        'legal_nature_description'
                    ] ?? null,

                'responsible_qualification_code' => $companyData[
                        'responsible_qualification_code'
                    ] ?? null,

                'share_capital' => $companyData[
                        'share_capital'
                    ] ?? null,

                'size_code' => $companyData[
                        'size_code'
                    ] ?? null,

                'size_description' => $companyData[
                        'size_description'
                    ] ?? null,

                'federative_entity' => $companyData[
                        'federative_entity'
                    ] ?? null,
            ]);

            $company->save();

            $email =
                $establishmentData[
                    'email'
                ] ?? null;

            $state =
                $establishmentData[
                    'state'
                ] ?? null;

            $establishment->fill([
                'fantasy_name' => $establishmentData[
                        'fantasy_name'
                    ] ?? null,

                'registration_status_code' => $establishmentData[
                        'registration_status_code'
                    ] ?? null,

                'registration_status' => $establishmentData[
                        'registration_status'
                    ] ?? null,

                'registration_status_date' => $establishmentData[
                        'registration_status_date'
                    ] ?? null,

                'registration_status_reason_code' => $establishmentData[
                        'registration_status_reason_code'
                    ] ?? null,

                'start_date' => $establishmentData[
                        'start_date'
                    ] ?? null,

                'address_type' => $establishmentData[
                        'address_type'
                    ] ?? null,

                'street' => $establishmentData[
                        'street'
                    ] ?? null,

                'number' => $establishmentData[
                        'number'
                    ] ?? null,

                'complement' => $establishmentData[
                        'complement'
                    ] ?? null,

                'neighborhood' => $establishmentData[
                        'neighborhood'
                    ] ?? null,

                'zip_code' => $establishmentData[
                        'zip_code'
                    ] ?? null,

                'state' => $state
                        ? mb_strtoupper(
                            trim($state)
                        )
                        : null,

                'municipality_code' => $establishmentData[
                        'municipality_code'
                    ] ?? null,

                'municipality_name' => $establishmentData[
                        'municipality_name'
                    ] ?? null,

                'phone_1' => $establishmentData[
                        'phone_1'
                    ] ?? null,

                'phone_2' => $establishmentData[
                        'phone_2'
                    ] ?? null,

                'fax' => $establishmentData[
                        'fax'
                    ] ?? null,

                'email' => $email
                        ? mb_strtolower(
                            trim($email)
                        )
                        : null,

                'special_situation' => $establishmentData[
                        'special_situation'
                    ] ?? null,

                'special_situation_date' => $establishmentData[
                        'special_situation_date'
                    ] ?? null,
            ]);

            $establishment->save();

            return $company
                ->fresh()
                ->load([
                    'establishments.cnaes',
                ]);
        });
    }
}
