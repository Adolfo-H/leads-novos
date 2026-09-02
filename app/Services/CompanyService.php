<?php

namespace App\Services;

use App\Models\Cnae;
use App\Models\Company;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CompanyService
{
    public function createOrUpdateFromEstablishment(
        array $companyData,
        array $establishmentData,
        array $cnaes = [],
    ): Company {
        return DB::transaction(function () use (
            $companyData,
            $establishmentData,
            $cnaes,
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

            return $company
                ->fresh()
                ->load([
                    'establishments.cnaes',
                ]);
        });
    }
}
