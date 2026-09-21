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
    ): Company {
        return DB::transaction(function () use (
            $companyData,
            $establishmentData,
            $cnaes,
            $loadRelations,
        ): Company {
            if (
                empty($establishmentData['cnpj'])
            ) {
                throw new InvalidArgumentException(
                    'O CNPJ do estabelecimento é obrigatório.'
                );
            }

            if (
                empty($companyData['corporate_name'])
            ) {
                throw new InvalidArgumentException(
                    'A razão social é obrigatória.'
                );
            }

            $cnpj = Cnpj::normalize(
                $establishmentData['cnpj']
            );

            Cnpj::assertValid($cnpj);

            $root = Cnpj::root($cnpj);

            $company = Company::updateOrCreate(
                [
                    'cnpj_root' => $root,
                ],
                [
                    'corporate_name' => $companyData['corporate_name'],

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

                    'source' => $companyData[
                            'source'
                        ] ?? 'manual',

                    'source_updated_at' => $companyData[
                            'source_updated_at'
                        ] ?? now(),

                    'metadata' => $companyData[
                            'metadata'
                        ] ?? null,
                ]
            );

            $type = $establishmentData[
                'type'
            ] ?? 'matrix';

            if (
                ! in_array(
                    $type,
                    ['matrix', 'branch'],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'O tipo do estabelecimento deve ser matrix ou branch.'
                );
            }

            /** @var Establishment $establishment */
            $establishment = $company
                ->establishments()
                ->updateOrCreate(
                    [
                        'cnpj' => $cnpj,
                    ],
                    [
                        'order_number' => Cnpj::order($cnpj),

                        'check_digits' => Cnpj::checkDigits($cnpj),

                        'type' => $type,

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

                        'foreign_city_name' => $establishmentData[
                                'foreign_city_name'
                            ] ?? null,

                        'country_code' => $establishmentData[
                                'country_code'
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

                        'state' => isset(
                            $establishmentData[
                                'state'
                            ]
                        )
                                ? mb_strtoupper(
                                    $establishmentData[
                                        'state'
                                    ]
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

                        'email' => isset(
                            $establishmentData[
                                'email'
                            ]
                        )
                                ? mb_strtolower(
                                    trim(
                                        $establishmentData[
                                            'email'
                                        ]
                                    )
                                )
                                : null,

                        'special_situation' => $establishmentData[
                                'special_situation'
                            ] ?? null,

                        'special_situation_date' => $establishmentData[
                                'special_situation_date'
                            ] ?? null,

                        'source' => $establishmentData[
                                'source'
                            ] ?? 'manual',

                        'source_updated_at' => $establishmentData[
                                'source_updated_at'
                            ] ?? now(),

                        'metadata' => $establishmentData[
                                'metadata'
                            ] ?? null,
                    ]
                );

            foreach ($cnaes as $cnaeData) {
                if (empty($cnaeData['code'])) {
                    continue;
                }

                $code = preg_replace(
                    '/\D/',
                    '',
                    (string) $cnaeData['code']
                );

                if (strlen((string) $code) !== 7) {
                    continue;
                }

                $cnae = Cnae::updateOrCreate(
                    [
                        'code' => $code,
                    ],
                    [
                        'description' => $cnaeData[
                                'description'
                            ] ?? null,
                    ]
                );

                $establishment
                    ->cnaes()
                    ->syncWithoutDetaching([
                        $cnae->id => [
                            'is_primary' => (bool) (
                                $cnaeData[
                                    'is_primary'
                                ] ?? false
                            ),
                        ],
                    ]);
            }

            /*
             * Importações de grupos empresariais grandes
             * podem passar por centenas de estabelecimentos.
             *
             * Durante esse processamento não devemos
             * recarregar matriz + todas as filiais + CNAEs
             * a cada unidade processada.
             *
             * A carga completa continua sendo o padrão
             * para os demais fluxos do sistema.
             */
            if (! $loadRelations) {
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
