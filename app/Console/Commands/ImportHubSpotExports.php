<?php

namespace App\Console\Commands;

use App\Services\HubSpotExportImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportHubSpotExports extends Command
{
    protected $signature =
        'hubspot:import-export
        {directory=storage/app/hubspot-import/incoming}
        {--companies=}
        {--deals=}
        {--contacts=}
        {--tasks=}
        {--replace : Limpa somente o espelho hubspot_* antes da importação}';

    protected $description =
        'Importa empresas, negócios, contatos e tarefas exportados do HubSpot';

    public function handle(
        HubSpotExportImportService $service
    ): int {
        try {
            $directory =
                $this->absolutePath(
                    (string) $this->argument(
                        'directory'
                    )
                );

            $files = [
                'companies' => $this->file(
                    'companies',
                    $directory,
                    [
                        'companies',
                        'empresas',
                    ]
                ),

                'deals' => $this->file(
                    'deals',
                    $directory,
                    [
                        'deals',
                        'negocios',
                        'negócios',
                    ]
                ),

                'contacts' => $this->file(
                    'contacts',
                    $directory,
                    [
                        'contacts',
                        'contatos',
                    ]
                ),

                'tasks' => $this->file(
                    'tasks',
                    $directory,
                    [
                        'tasks',
                        'tarefas',
                    ]
                ),
            ];

            $this->info(
                'Arquivos identificados:'
            );

            foreach (
                $files as $type => $path
            ) {
                $this->line(
                    ' - '
                    .$type
                    .': '
                    .basename($path)
                );
            }

            $this->newLine();

            $run =
                $service->import(
                    $files,
                    (bool) $this->option(
                        'replace'
                    ),
                );

            $rawStats =
                $run->getAttribute(
                    'stats'
                );

            $stats =
                is_array(
                    $rawStats
                )
                    ? $rawStats
                    : [];

            $this->table(
                [
                    'Objeto',
                    'Importados',
                ],
                [
                    [
                        'Empresas',
                        data_get(
                            $stats,
                            'companies.imported',
                            0
                        ),
                    ],
                    [
                        'Negócios',
                        data_get(
                            $stats,
                            'deals.imported',
                            0
                        ),
                    ],
                    [
                        'Contatos',
                        data_get(
                            $stats,
                            'contacts.imported',
                            0
                        ),
                    ],
                    [
                        'Tarefas',
                        data_get(
                            $stats,
                            'tasks.imported',
                            0
                        ),
                    ],
                ]
            );

            $this->newLine();

            $this->table(
                [
                    'Associação',
                    'Quantidade',
                ],
                [
                    [
                        'Empresa ↔ Negócio',
                        data_get(
                            $stats,
                            'associations.company_deal',
                            0
                        ),
                    ],
                    [
                        'Empresa ↔ Contato',
                        data_get(
                            $stats,
                            'associations.company_contact',
                            0
                        ),
                    ],
                    [
                        'Negócio ↔ Contato',
                        data_get(
                            $stats,
                            'associations.deal_contact',
                            0
                        ),
                    ],
                    [
                        'Empresa ↔ Tarefa',
                        data_get(
                            $stats,
                            'associations.company_task',
                            0
                        ),
                    ],
                    [
                        'Negócio ↔ Tarefa',
                        data_get(
                            $stats,
                            'associations.deal_task',
                            0
                        ),
                    ],
                    [
                        'Contato ↔ Tarefa',
                        data_get(
                            $stats,
                            'associations.contact_task',
                            0
                        ),
                    ],
                ]
            );

            $this->newLine();

            $this->line(
                'Negócios sem empresa: '
                .data_get(
                    $stats,
                    'associations.deals_without_company',
                    0
                )
            );

            $this->line(
                'Negócios multiempresa: '
                .data_get(
                    $stats,
                    'associations.multi_company_deals',
                    0
                )
            );

            $this->line(
                'CNPJs preservados da base antiga: '
                .data_get(
                    $stats,
                    'companies.matched_cnpj',
                    0
                )
            );

            $this->newLine();

            $this->info(
                'Importação concluída.'
            );

            $this->line(
                'Relatório: '
                .data_get(
                    $stats,
                    'report_path',
                    'não gerado'
                )
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }
    }

    /**
     * @param  list<string>  $tokens
     */
    private function file(
        string $option,
        string $directory,
        array $tokens,
    ): string {
        $rawOption =
            $this->option(
                $option
            );

        $optionValue =
            is_string(
                $rawOption
            )
                ? trim(
                    $rawOption
                )
                : '';

        if ($optionValue !== '') {
            return $this->absolutePath(
                $optionValue
            );
        }

        if (! File::isDirectory($directory)) {
            throw new RuntimeException(
                'Diretório não encontrado: '
                .$directory
            );
        }

        $matches = [];

        foreach (
            File::files(
                $directory
            ) as $file
        ) {
            $name =
                Str::lower(
                    $file->getFilename()
                );

            foreach ($tokens as $token) {
                if (
                    str_contains(
                        $name,
                        Str::lower(
                            $token
                        )
                    )
                ) {
                    $matches[] =
                        $file;

                    break;
                }
            }
        }

        if ($matches === []) {
            throw new RuntimeException(
                'Arquivo de '
                .$option
                .' não encontrado em '
                .$directory
            );
        }

        usort(
            $matches,
            static fn (
                $a,
                $b
            ): int => $b->getMTime()
                <=>
                $a->getMTime()
        );

        return $matches[0]
            ->getPathname();
    }

    private function absolutePath(
        string $path
    ): string {
        if (
            str_starts_with(
                $path,
                DIRECTORY_SEPARATOR
            )
        ) {
            return $path;
        }

        return base_path(
            $path
        );
    }
}
