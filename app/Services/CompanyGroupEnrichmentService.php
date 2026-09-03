<?php

namespace App\Services;

use App\Contracts\CnpjGroupDataProvider;
use App\Models\Company;
use App\Support\Cnpj;
use InvalidArgumentException;

final class CompanyGroupEnrichmentService
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly IcpScoringService $icp,
    ) {}

    public function enrich(
        string $cnpj,
        CnpjGroupDataProvider $provider,
    ): Company {
        $cnpj = Cnpj::normalize(
            $cnpj
        );

        Cnpj::assertValid(
            $cnpj
        );

        $root = Cnpj::root(
            $cnpj
        );

        $payload = $provider->lookupRoot(
            $root
        );

        $companyData =
            $payload['company'];

        $establishments =
            $payload['establishments'];

        if (
            empty(
                $companyData[
                    'corporate_name'
                ]
            )
        ) {
            throw new InvalidArgumentException(
                'A fonte não retornou dados válidos da empresa.'
            );
        }

        if ($establishments === []) {
            throw new InvalidArgumentException(
                'A fonte não retornou estabelecimentos para esta raiz de CNPJ.'
            );
        }

        $company = null;

        foreach (
            $establishments as $item
        ) {
            $establishmentData =
                $item['establishment'];

            $cnaes =
                $item['cnaes'];

            if (
                empty(
                    $establishmentData[
                        'cnpj'
                    ]
                )
            ) {
                throw new InvalidArgumentException(
                    'A fonte retornou um estabelecimento sem CNPJ.'
                );
            }

            $establishmentCnpj =
                Cnpj::normalize(
                    (string)
                        $establishmentData[
                            'cnpj'
                        ]
                );

            Cnpj::assertValid(
                $establishmentCnpj
            );

            if (
                Cnpj::root(
                    $establishmentCnpj
                ) !== $root
            ) {
                throw new InvalidArgumentException(
                    'A fonte retornou um estabelecimento pertencente a outra raiz de CNPJ.'
                );
            }

            $establishmentData[
                'cnpj'
            ] = $establishmentCnpj;

            $company =
                $this->companies
                    ->createOrUpdateFromEstablishment(
                        $companyData,
                        $establishmentData,
                        $cnaes,
                    );
        }

        /**
         * O array de estabelecimentos já foi validado
         * como não vazio antes do foreach.
         *
         * @var Company $company
         */
        $company = $company->fresh([
            'establishments.cnaes',
            'icpScore',
        ]);

        $this->icp->calculate(
            $company
        );

        return $company->fresh([
            'establishments.cnaes',
            'icpScore',
        ]);
    }
}
