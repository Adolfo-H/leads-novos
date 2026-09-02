<?php

namespace App\Services;

use App\Models\Cnae;
use App\Models\Company;
use App\Models\Establishment;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EstablishmentService
{
    public function createBranch(
        Company $company,
        array $data,
    ): Establishment {
        return DB::transaction(function () use (
            $company,
            $data,
        ): Establishment {
            if (empty($data['cnpj'])) {
                throw new InvalidArgumentException(
                    'O CNPJ da filial é obrigatório.'
                );
            }

            $cnpj = Cnpj::normalize(
                $data['cnpj']
            );

            Cnpj::assertValid($cnpj);

            if (
                Cnpj::root($cnpj)
                !== $company->cnpj_root
            ) {
                throw new InvalidArgumentException(
                    'O CNPJ da filial deve possuir a mesma raiz da empresa.'
                );
            }

            $existing = Establishment::query()
                ->where('cnpj', $cnpj)
                ->first();

            if ($existing) {
                throw new InvalidArgumentException(
                    'Este CNPJ já está cadastrado.'
                );
            }

            return $company
                ->establishments()
                ->create(
                    $this->establishmentData(
                        $cnpj,
                        $data,
                        'branch',
                    )
                );
        });
    }

    public function update(
        Establishment $establishment,
        array $data,
    ): Establishment {
        return DB::transaction(function () use (
            $establishment,
            $data,
        ): Establishment {
            /*
             * O CNPJ não é alterado neste método.
             * Ele representa a identidade permanente
             * do estabelecimento.
             */
            $establishment->fill(
                $this->editableData($data)
            );

            $establishment->save();

            return $establishment
                ->fresh()
                ->load('cnaes');
        });
    }

    public function addCnae(
        Establishment $establishment,
        array $data,
    ): Cnae {
        return DB::transaction(function () use (
            $establishment,
            $data,
        ): Cnae {
            $code = $this->normalizeCnaeCode(
                $data['code'] ?? ''
            );

            $isPrimary = (bool) (
                $data['is_primary'] ?? false
            );

            $cnae = Cnae::query()
                ->updateOrCreate(
                    [
                        'code' => $code,
                    ],
                    [
                        'description' => $this->nullable(
                            $data[
                                'description'
                            ] ?? null
                        ),
                    ]
                );

            if ($isPrimary) {
                $establishment
                    ->cnaes()
                    ->newPivotQuery()
                    ->update([
                        'is_primary' => false,
                    ]);
            }

            $establishment
                ->cnaes()
                ->syncWithoutDetaching([
                    $cnae->id => [
                        'is_primary' => $isPrimary,
                    ],
                ]);

            /*
             * syncWithoutDetaching não atualiza
             * necessariamente os dados do pivot
             * de um relacionamento já existente
             * da maneira que queremos deixar
             * explícita aqui.
             */
            $establishment
                ->cnaes()
                ->updateExistingPivot(
                    $cnae->id,
                    [
                        'is_primary' => $isPrimary,
                    ]
                );

            return $cnae->fresh();
        });
    }

    public function setPrimaryCnae(
        Establishment $establishment,
        Cnae $cnae,
    ): void {
        DB::transaction(function () use (
            $establishment,
            $cnae,
        ): void {
            if (
                ! $establishment
                    ->cnaes()
                    ->whereKey($cnae->id)
                    ->exists()
            ) {
                throw new InvalidArgumentException(
                    'O CNAE não pertence a este estabelecimento.'
                );
            }

            $establishment
                ->cnaes()
                ->newPivotQuery()
                ->update([
                    'is_primary' => false,
                ]);

            $establishment
                ->cnaes()
                ->updateExistingPivot(
                    $cnae->id,
                    [
                        'is_primary' => true,
                    ]
                );
        });
    }

    public function removeCnae(
        Establishment $establishment,
        Cnae $cnae,
    ): void {
        DB::transaction(function () use (
            $establishment,
            $cnae,
        ): void {
            $establishment
                ->cnaes()
                ->detach($cnae->id);
        });
    }

    private function establishmentData(
        string $cnpj,
        array $data,
        string $type,
    ): array {
        return array_merge(
            [
                'cnpj' => $cnpj,

                'order_number' => Cnpj::order($cnpj),

                'check_digits' => Cnpj::checkDigits($cnpj),

                'type' => $type,

                'source' => $data['source']
                    ?? 'manual',

                'source_updated_at' => $data[
                        'source_updated_at'
                    ] ?? now(),
            ],
            $this->editableData($data)
        );
    }

    private function editableData(
        array $data,
    ): array {
        $state = $this->nullable(
            $data['state'] ?? null
        );

        $email = $this->nullable(
            $data['email'] ?? null
        );

        return [
            'fantasy_name' => $this->nullable(
                $data[
                    'fantasy_name'
                ] ?? null
            ),

            'registration_status_code' => $this->nullable(
                $data[
                    'registration_status_code'
                ] ?? null
            ),

            'registration_status' => $this->nullable(
                $data[
                    'registration_status'
                ] ?? null
            ),

            'registration_status_date' => $data[
                    'registration_status_date'
                ] ?? null,

            'registration_status_reason_code' => $this->nullable(
                $data[
                    'registration_status_reason_code'
                ] ?? null
            ),

            'start_date' => $data[
                    'start_date'
                ] ?? null,

            'foreign_city_name' => $this->nullable(
                $data[
                    'foreign_city_name'
                ] ?? null
            ),

            'country_code' => $this->nullable(
                $data[
                    'country_code'
                ] ?? null
            ),

            'address_type' => $this->nullable(
                $data[
                    'address_type'
                ] ?? null
            ),

            'street' => $this->nullable(
                $data[
                    'street'
                ] ?? null
            ),

            'number' => $this->nullable(
                $data[
                    'number'
                ] ?? null
            ),

            'complement' => $this->nullable(
                $data[
                    'complement'
                ] ?? null
            ),

            'neighborhood' => $this->nullable(
                $data[
                    'neighborhood'
                ] ?? null
            ),

            'zip_code' => $this->nullable(
                $data[
                    'zip_code'
                ] ?? null
            ),

            'state' => $state
                    ? mb_strtoupper($state)
                    : null,

            'municipality_code' => $this->nullable(
                $data[
                    'municipality_code'
                ] ?? null
            ),

            'municipality_name' => $this->nullable(
                $data[
                    'municipality_name'
                ] ?? null
            ),

            'phone_1' => $this->nullable(
                $data[
                    'phone_1'
                ] ?? null
            ),

            'phone_2' => $this->nullable(
                $data[
                    'phone_2'
                ] ?? null
            ),

            'fax' => $this->nullable(
                $data[
                    'fax'
                ] ?? null
            ),

            'email' => $email
                    ? mb_strtolower($email)
                    : null,

            'special_situation' => $this->nullable(
                $data[
                    'special_situation'
                ] ?? null
            ),

            'special_situation_date' => $data[
                    'special_situation_date'
                ] ?? null,

            'metadata' => $data[
                    'metadata'
                ] ?? null,
        ];
    }

    private function normalizeCnaeCode(
        mixed $value,
    ): string {
        $code = preg_replace(
            '/\D/',
            '',
            (string) $value
        );

        if (
            strlen((string) $code)
            !== 7
        ) {
            throw new InvalidArgumentException(
                'O CNAE deve possuir 7 dígitos.'
            );
        }

        return $code;
    }

    private function nullable(
        mixed $value,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value === ''
            ? null
            : $value;
    }
}
