<?php

namespace App\Services;

use App\Models\Company;

final class HubSpotContactCandidateService
{
    /**
     * Monta a lista de Contacts que deverá ser
     * sincronizada com o HubSpot.
     *
     * Regras preservadas:
     *
     * - todos os e-mails válidos e únicos;
     * - phone_1 e phone_2;
     * - deduplicação de telefone pelos dígitos;
     * - matriz priorizada;
     * - unidade ativa priorizada;
     * - contatos sem e-mail continuam válidos;
     * - telefones excedentes nunca são perdidos.
     *
     * @return list<array{
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    public function collect(
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
         * Todos os telefones encontrados,
         * inclusive aqueles que excedem
         * phone + mobilephone.
         *
         * @var array<string, string>
         */
        $allPhones = [];

        foreach (
            $establishments as $establishment
        ) {
            $email =
                $this->normalizeEmail(
                    $establishment->email
                );

            $phones =
                $this->normalizePhones([
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
         * @var array<string, true>
         */
        $usedPhones = [];

        /*
         * Primeiro criamos os Contacts que
         * possuem e-mail.
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
         * Unidades que possuem telefone,
         * mas não possuem e-mail.
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
         * Caso um mesmo e-mail apareça em
         * diversas unidades e possua mais de
         * dois telefones, os excedentes viram
         * contatos apenas com telefone.
         *
         * Dessa forma nenhum canal cadastral
         * existente no Prospector é descartado.
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

    private function normalizeEmail(
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
    private function normalizePhones(
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

            /*
             * Dígitos são a identidade.
             *
             * "(45) 99999-0000"
             * e "45999990000"
             * representam o mesmo telefone.
             */
            $phones[
                $digits
            ] =
                $phone;
        }

        return $phones;
    }
}
