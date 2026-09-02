<?php

namespace App\Services;

use App\Models\Company;
use App\Support\Cnpj;

final class CnpjEnrichmentService
{
    public function __construct(
        private readonly CompanyService $companies,
    ) {}

    public function enrich(
        string $cnpj,
        array $data,
        string $source = 'brasilapi',
    ): Company {
        $corporateName = $this->first(
            $data,
            [
                'razao_social',
                'nome_empresarial',
                'razaoSocial',
                'nome',
            ]
        );

        if (! $corporateName) {
            throw new \RuntimeException(
                'Consulta não retornou razão social.'
            );
        }

        return $this->companies
            ->createOrUpdateFromEstablishment(
                [
                    'corporate_name' => (string) $corporateName,

                    'legal_nature_code' => $this->first(
                        $data,
                        [
                            'codigo_natureza_juridica',
                            'natureza_juridica.codigo',
                            'natureza_juridica_code',
                        ]
                    ),

                    'legal_nature_description' => $this->first(
                        $data,
                        [
                            'natureza_juridica.descricao',
                            'descricao_natureza_juridica',
                            'natureza_juridica',
                        ]
                    ),

                    'responsible_qualification_code' => $this->first(
                        $data,
                        [
                            'qualificacao_do_responsavel',
                            'qualificacao_responsavel',
                        ]
                    ),

                    'share_capital' => $this->numeric(
                        $this->first(
                            $data,
                            [
                                'capital_social',
                                'capitalSocial',
                            ]
                        )
                    ),

                    'size_code' => $this->first(
                        $data,
                        [
                            'codigo_porte',
                            'porte.codigo',
                        ]
                    ),

                    'size_description' => $this->first(
                        $data,
                        [
                            'descricao_porte',
                            'porte.descricao',
                            'porte',
                        ]
                    ),

                    'federative_entity' => $this->first(
                        $data,
                        [
                            'ente_federativo_responsavel',
                        ]
                    ),

                    'source' => $source,

                    'source_updated_at' => now(),

                    'metadata' => [
                    'provider' => $source,

                    'qsa' => $data['qsa']
                        ?? null,

                    'regime_tributario' => $data[
                            'regime_tributario'
                        ] ?? null,

                    'opcao_pelo_simples' => $data[
                            'opcao_pelo_simples'
                        ] ?? null,

                    'opcao_pelo_mei' => $data[
                            'opcao_pelo_mei'
                        ] ?? null,

                    'data_opcao_pelo_simples' => $data[
                            'data_opcao_pelo_simples'
                        ] ?? null,

                    'data_exclusao_do_simples' => $data[
                            'data_exclusao_do_simples'
                        ] ?? null,
                    ],
                ],
                [
                    'cnpj' => $cnpj,

                    'type' => $this->establishmentType(
                        $cnpj,
                        $data
                    ),

                    'fantasy_name' => $this->first(
                        $data,
                        [
                            'nome_fantasia',
                            'fantasia',
                            'nomeFantasia',
                        ]
                    ),

                    'registration_status_code' => $this->first(
                        $data,
                        [
                            'situacao_cadastral',
                            'codigo_situacao_cadastral',
                        ]
                    ),

                    'registration_status' => $this->registrationStatus(
                        $data
                    ),

                    'registration_status_date' => $this->first(
                        $data,
                        [
                            'data_situacao_cadastral',
                            'data_situacao',
                        ]
                    ),

                    'registration_status_reason_code' => $this->first(
                        $data,
                        [
                            'motivo_situacao_cadastral',
                            'codigo_motivo_situacao',
                        ]
                    ),

                    'start_date' => $this->first(
                        $data,
                        [
                            'data_inicio_atividade',
                            'data_inicio_atividades',
                            'data_abertura',
                        ]
                    ),

                    'foreign_city_name' => $this->first(
                        $data,
                        [
                            'nome_cidade_no_exterior',
                        ]
                    ),

                    'country_code' => $this->first(
                        $data,
                        [
                            'codigo_pais',
                        ]
                    ),

                    'address_type' => $this->first(
                        $data,
                        [
                            'descricao_tipo_de_logradouro',
                            'tipo_logradouro',
                            'endereco.tipo_logradouro',
                        ]
                    ),

                    'street' => $this->first(
                        $data,
                        [
                            'logradouro',
                            'endereco.logradouro',
                        ]
                    ),

                    'number' => $this->first(
                        $data,
                        [
                            'numero',
                            'endereco.numero',
                        ]
                    ),

                    'complement' => $this->first(
                        $data,
                        [
                            'complemento',
                            'endereco.complemento',
                        ]
                    ),

                    'neighborhood' => $this->first(
                        $data,
                        [
                            'bairro',
                            'endereco.bairro',
                        ]
                    ),

                    'zip_code' => $this->first(
                        $data,
                        [
                            'cep',
                            'endereco.cep',
                        ]
                    ),

                    'state' => $this->first(
                        $data,
                        [
                            'uf',
                            'estado',
                            'endereco.uf',
                        ]
                    ),

                    'municipality_code' => $this->first(
                        $data,
                        [
                            'codigo_municipio_ibge',
                            'codigo_municipio',
                            'municipio.codigo',
                        ]
                    ),

                    'municipality_name' => $this->first(
                        $data,
                        [
                            'municipio',
                            'cidade',
                            'municipio.nome',
                        ]
                    ),

                    'phone_1' => $this->phone(
                        $data,
                        1
                    ),

                    'phone_2' => $this->phone(
                        $data,
                        2
                    ),

                    'fax' => $this->first(
                        $data,
                        [
                            'ddd_fax',
                            'fax',
                        ]
                    ),

                    'email' => $this->first(
                        $data,
                        [
                            'email',
                            'correio_eletronico',
                        ]
                    ),

                    'special_situation' => $this->first(
                        $data,
                        [
                            'situacao_especial',
                        ]
                    ),

                    'special_situation_date' => $this->first(
                        $data,
                        [
                            'data_situacao_especial',
                        ]
                    ),

                    'source' => $source,

                    'source_updated_at' => now(),

                    'metadata' => [
                    'provider' => $source,

                    'identificador_matriz_filial' => $data[
                            'identificador_matriz_filial'
                        ] ?? null,

                    'descricao_identificador_matriz_filial' => $data[
                            'descricao_identificador_matriz_filial'
                        ] ?? null,

                    'codigo_municipio_ibge' => $data[
                            'codigo_municipio_ibge'
                        ] ?? null,

                    'descricao_motivo_situacao_cadastral' => $data[
                            'descricao_motivo_situacao_cadastral'
                        ] ?? null,
                    ],
                ],
                $this->cnaes(
                    $data
                ),
            );
    }

    private function establishmentType(
        string $cnpj,
        array $data,
    ): string {
        $value = mb_strtoupper(
            (string) (
                $this->first(
                    $data,
                    [
                        'descricao_identificador_matriz_filial',
                        'matriz_filial',
                        'tipo_estabelecimento',
                        'identificador_matriz_filial',
                    ]
                )
                ?? ''
            )
        );

        if (
            str_contains(
                $value,
                'FILIAL'
            )
            || $value === '2'
        ) {
            return 'branch';
        }

        if (
            str_contains(
                $value,
                'MATRIZ'
            )
            || $value === '1'
        ) {
            return 'matrix';
        }

        return Cnpj::order($cnpj)
            === '0001'
                ? 'matrix'
                : 'branch';
    }

    private function registrationStatus(
        array $data
    ): ?string {
        $description = $this->first(
            $data,
            [
                'descricao_situacao_cadastral',
                'situacao_descricao',
                'situacao',
                'status',
            ]
        );

        if (
            $description !== null
            && ! is_numeric(
                (string) $description
            )
        ) {
            return mb_strtoupper(
                (string) $description
            );
        }

        $code = $this->first(
            $data,
            [
                'situacao_cadastral',
                'codigo_situacao_cadastral',
            ]
        );

        return match (
            (string) $code
        ) {
            '1' => 'NULA',
            '2' => 'ATIVA',
            '3' => 'SUSPENSA',
            '4' => 'INAPTA',
            '8' => 'BAIXADA',

            default => null,
        };
    }

    private function cnaes(
        array $data
    ): array {
        $result = [];

        $primary =
            data_get(
                $data,
                'cnae_principal'
            );

        if (is_array($primary)) {
            $this->addCnae(
                $result,
                $primary['codigo']
                    ?? $primary['code']
                    ?? null,
                $primary['descricao']
                    ?? $primary['description']
                    ?? null,
                true,
            );
        } elseif ($primary) {
            $this->addCnae(
                $result,
                $primary,
                $this->first(
                    $data,
                    [
                        'cnae_principal_descricao',
                    ]
                ),
                true,
            );
        }

        /*
         * Formato oficial retornado pela
         * BrasilAPI.
         */
        if ($result === []) {
            $this->addCnae(
                $result,
                $this->first(
                    $data,
                    [
                        'cnae_fiscal',
                    ]
                ),
                $this->first(
                    $data,
                    [
                        'cnae_fiscal_descricao',
                    ]
                ),
                true,
            );
        }

        $secondary =
            data_get(
                $data,
                'cnaes_secundarios'
            );

        if (! is_array($secondary)) {
            $secondary =
                data_get(
                    $data,
                    'cnaes_secundarias'
                );
        }

        if (is_array($secondary)) {
            foreach (
                $secondary as $item
            ) {
                if (! is_array($item)) {
                    $this->addCnae(
                        $result,
                        $item,
                        null,
                        false,
                    );

                    continue;
                }

                $this->addCnae(
                    $result,
                    $item['codigo']
                        ?? $item['code']
                        ?? null,
                    $item['descricao']
                        ?? $item['description']
                        ?? null,
                    false,
                );
            }
        }

        return array_values(
            $result
        );
    }

    private function addCnae(
        array &$result,
        mixed $code,
        mixed $description,
        bool $primary,
    ): void {
        $code = $this
            ->normalizeCnaeCode(
                $code
            );

        if (! $code) {
            return;
        }

        /*
         * Se o mesmo CNAE aparecer também
         * na lista secundária, preservamos
         * a informação de principal.
         */
        if (
            isset($result[$code])
            && $result[$code][
                'is_primary'
            ] === true
        ) {
            return;
        }

        $result[$code] = [
            'code' => $code,

            'description' => $description !== null
                    ? (string) $description
                    : null,

            'is_primary' => $primary,
        ];
    }

    private function normalizeCnaeCode(
        mixed $value
    ): ?string {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        $digits = preg_replace(
            '/\D/',
            '',
            (string) $value
        );

        if (
            ! is_string($digits)
            || $digits === ''
            || strlen($digits) > 7
        ) {
            return null;
        }

        return str_pad(
            $digits,
            7,
            '0',
            STR_PAD_LEFT
        );
    }

    private function phone(
        array $data,
        int $number,
    ): ?string {
        return $this->first(
            $data,
            [
                'ddd_telefone_'.$number,
                'telefone_'.$number,
                'telefone'.$number,
                'telefones.'.($number - 1).'.numero',
            ]
        );
    }

    private function numeric(
        mixed $value
    ): ?float {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        if (
            is_int($value)
            || is_float($value)
        ) {
            return (float) $value;
        }

        $value = trim(
            (string) $value
        );

        $value = str_ireplace(
            'R$',
            '',
            $value
        );

        $value = str_replace(
            ' ',
            '',
            $value
        );

        if (
            str_contains(
                $value,
                ','
            )
        ) {
            $value = str_replace(
                '.',
                '',
                $value
            );

            $value = str_replace(
                ',',
                '.',
                $value
            );
        }

        $value = preg_replace(
            '/[^0-9.\-]/',
            '',
            $value
        );

        return is_numeric($value)
            ? (float) $value
            : null;
    }

    private function first(
        array $data,
        array $paths,
    ): mixed {
        foreach ($paths as $path) {
            $value = data_get(
                $data,
                $path
            );

            /*
             * Estes campos são todos escalares.
             * Evita gravar arrays acidentalmente
             * em colunas string.
             */
            if (
                is_array($value)
                || is_object($value)
            ) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }
            }

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }
}
