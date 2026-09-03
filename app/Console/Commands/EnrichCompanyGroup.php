<?php

namespace App\Console\Commands;

use App\Services\CompanyGroupEnrichmentService;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Support\Cnpj;
use Illuminate\Console\Command;
use Throwable;

class EnrichCompanyGroup extends Command
{
    protected $signature = 'receita:enrich-group
                            {cnpj : CNPJ da matriz ou de qualquer filial}';

    protected $description =
        'Enriquece uma empresa e todos os seus estabelecimentos usando a base local da Receita';

    public function handle(
        CompanyGroupEnrichmentService $service,
        ReceitaLocalCnpjGroupProvider $provider,
    ): int {
        $rawCnpj = (string) $this->argument(
            'cnpj'
        );

        $cnpj = Cnpj::normalize(
            $rawCnpj
        );

        if (! Cnpj::isValid($cnpj)) {
            $this->error(
                'O CNPJ informado é inválido.'
            );

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'Consultando grupo empresarial...'
        );

        try {
            $company = $service->enrich(
                $cnpj,
                $provider,
            );
        } catch (Throwable $exception) {
            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $company->load([
            'establishments.cnaes',
            'icpScore',
        ]);

        $matrix = $company
            ->establishments
            ->firstWhere(
                'type',
                'matrix'
            );

        $this->newLine();

        $this->info(
            'Grupo empresarial enriquecido com sucesso.'
        );

        $this->newLine();

        $this->table(
            [
                'Campo',
                'Valor',
            ],
            [
                [
                    'Razão social',
                    $company->corporate_name,
                ],
                [
                    'Raiz CNPJ',
                    $company->cnpj_root,
                ],
                [
                    'Matriz',
                    $matrix
                        ? Cnpj::format(
                            $matrix->cnpj
                        )
                        : 'Não identificada',
                ],
                [
                    'Estabelecimentos',
                    (string) $company
                        ->establishments
                        ->count(),
                ],
                [
                    'ICP',
                    $company->icpScore
                        ? sprintf(
                            '%s - %d/100 - %s',
                            $company
                                ->icpScore
                                ->grade,
                            $company
                                ->icpScore
                                ->score,
                            $company
                                ->icpScore
                                ->label,
                        )
                        : 'Não calculado',
                ],
                [
                    'Fonte',
                    $company->source
                        ?? $provider->name(),
                ],
            ]
        );

        $this->newLine();

        $this->line(
            '<fg=cyan>Estabelecimentos encontrados:</>'
        );

        $rows = $company
            ->establishments
            ->sortBy('order_number')
            ->map(
                fn ($establishment): array => [
                    Cnpj::format(
                        $establishment->cnpj
                    ),

                    $establishment->type
                        === 'matrix'
                            ? 'Matriz'
                            : 'Filial',

                    $establishment
                        ->municipality_name
                        ?? '—',

                    $establishment
                        ->state
                        ?? '—',

                    $establishment
                        ->registration_status
                        ?? '—',

                    (string) $establishment
                        ->cnaes
                        ->count(),
                ]
            )
            ->values()
            ->all();

        $this->table(
            [
                'CNPJ',
                'Tipo',
                'Município',
                'UF',
                'Situação',
                'CNAEs',
            ],
            $rows,
        );

        return self::SUCCESS;
    }
}
