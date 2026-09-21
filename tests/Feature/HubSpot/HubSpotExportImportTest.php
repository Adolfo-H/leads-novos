<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use App\Services\HubSpotExportImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it(
    'imports a complete HubSpot export snapshot',
    function () {
        $legacy =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa A',
                ]);

        $legacy
            ->crmCheck()
            ->create([
                'provider' => 'hubspot',

                'status' => 'opportunity',

                'external_id' => 'company-a',

                'contacted_count' => 1,

                'associated_deals_count' => 2,

                'metadata' => [],

                'checked_at' => now(),
            ]);

        $directory =
            storage_path(
                'framework/testing/hubspot-import-'
                .Str::uuid()
            );

        File::ensureDirectoryExists(
            $directory
        );

        $writeCsv =
            static function (
                string $path,
                array $headers,
                array $rows,
            ): void {
                $handle =
                    fopen(
                        $path,
                        'wb'
                    );

                expect(
                    $handle
                )->not->toBeFalse();

                fputcsv(
                    $handle,
                    $headers
                );

                foreach ($rows as $row) {
                    fputcsv(
                        $handle,
                        $row
                    );
                }

                fclose(
                    $handle
                );
            };

        try {
            $companies =
                $directory
                .'/companies.csv';

            $deals =
                $directory
                .'/deals.csv';

            $contacts =
                $directory
                .'/contacts.csv';

            $tasks =
                $directory
                .'/tasks.csv';

            $writeCsv(
                $companies,
                [
                    'ID do registro',
                    'Nome da empresa',
                    'Nome de domínio da empresa',
                    'Fase do ciclo de vida',
                    'Status do lead',
                    'Proprietário da empresa',
                    'Cidade',
                    'Estado/Região',
                    'Número de telefone',
                    'Data da última atividade',
                    'Data de criação',
                    'Data da última modificação',
                    'Associated Deal IDs',
                    'Associated Contact IDs',
                ],
                [
                    [
                        'company-a',
                        'Empresa A',
                        'www.empresa-a.com.br',
                        'Oportunidade',
                        '',
                        'Adolfo',
                        'Palotina',
                        'PR',
                        '4499999999',
                        '2026-09-20 10:00',
                        '2026-01-01 08:00',
                        '2026-09-20 10:00',
                        'deal-1;deal-2',
                        'contact-1',
                    ],
                    [
                        'company-b',
                        'Empresa B',
                        'empresa-b.com.br',
                        'Lead',
                        '',
                        'Adolfo',
                        'Toledo',
                        'PR',
                        '',
                        '',
                        '2026-02-01 08:00',
                        '',
                        'deal-1',
                        'contact-1',
                    ],
                ]
            );

            $writeCsv(
                $deals,
                [
                    'ID do registro',
                    'Nome do negócio',
                    'Pipeline',
                    'Etapa do negócio',
                    'Proprietário do negócio',
                    'Valor',
                    'É negócio fechado',
                    'O negócio está fechado?',
                    'Está fechado (numérico)',
                    'Data de criação',
                    'Data de fechamento',
                    'Data da última atividade',
                    'Associated Company IDs',
                    'Associated Company IDs (Primary)',
                    'Associated Contact IDs',
                ],
                [
                    [
                        'deal-1',
                        'Negócio 1',
                        'Pipeline de vendas',
                        'Leds qualificado',
                        'Adolfo',
                        '8500.50',
                        'false',
                        'false',
                        '0.0',
                        '2026-02-01 08:00',
                        '',
                        '2026-09-20 09:00',
                        'company-a;company-b',
                        'company-a',
                        'contact-1',
                    ],
                    [
                        'deal-2',
                        'Negócio 2',
                        'Pipeline de vendas',
                        'Negócio fechado',
                        'Adolfo',
                        '10000',
                        'true',
                        'true',
                        '1.0',
                        '2026-03-01 08:00',
                        '2026-09-01 10:00',
                        '2026-09-01 10:00',
                        'company-a',
                        'company-a',
                        '',
                    ],
                ]
            );

            $writeCsv(
                $contacts,
                [
                    'ID do registro',
                    'Nome',
                    'Sobrenome',
                    'E-mail',
                    'Número de telefone',
                    'Número de telefone do WhatsApp',
                    'Cargo',
                    'Nome da empresa',
                    'Proprietário do contato',
                    'Fase do ciclo de vida',
                    'Data da última atividade',
                    'Data de criação',
                    'Associated Company IDs',
                    'Associated Company IDs (Primary)',
                    'Associated Deal IDs',
                ],
                [
                    [
                        'contact-1',
                        'Maria',
                        'Silva',
                        'maria@empresa-a.com.br',
                        '',
                        '4499999999',
                        'Gerente',
                        'Empresa A',
                        'Adolfo',
                        'Oportunidade',
                        '2026-09-20 09:00',
                        '2026-03-01 08:00',
                        'company-a;company-b',
                        'company-a',
                        'deal-1',
                    ],
                ]
            );

            $writeCsv(
                $tasks,
                [
                    'ID do registro',
                    'A tarefa está aberta',
                    'Atribuído a',
                    'Concluído em',
                    'Criado em',
                    'Data de vencimento',
                    'Fase da tarefa',
                    'Observações da tarefa',
                    'Status da tarefa',
                    'Tipo de tarefa',
                    'Título da tarefa',
                    'Vencido',
                    'Associated Contact IDs',
                    'Associated Company IDs',
                    'Associated Deal IDs',
                ],
                [
                    [
                        'task-1',
                        'true',
                        'Adolfo',
                        '',
                        '2026-09-20 08:00',
                        '2026-09-22 10:00',
                        'Não iniciado',
                        'Retornar contato',
                        'Não iniciado',
                        'Ligar',
                        'Ligar para Maria',
                        'false',
                        'contact-1',
                        'company-a',
                        'deal-1',
                    ],
                ]
            );

            $run =
                app(
                    HubSpotExportImportService::class
                )->import(
                    [
                        'companies' => $companies,

                        'deals' => $deals,

                        'contacts' => $contacts,

                        'tasks' => $tasks,
                    ]
                );

            expect(
                $run->status
            )->toBe(
                'completed'
            );

            expect(
                HubSpotCompany::query()
                    ->count()
            )->toBe(2);

            expect(
                HubSpotDeal::query()
                    ->count()
            )->toBe(2);

            expect(
                HubSpotContact::query()
                    ->count()
            )->toBe(1);

            expect(
                HubSpotTask::query()
                    ->count()
            )->toBe(1);

            expect(
                HubSpotPipelineStage::query()
                    ->count()
            )->toBe(2);

            $companyA =
                HubSpotCompany::query()
                    ->where(
                        'hubspot_id',
                        'company-a'
                    )
                    ->firstOrFail();

            expect(
                $companyA
                    ->matched_cnpj_root
            )->toBe(
                '12345678'
            );

            expect(
                $companyA
                    ->deals()
                    ->count()
            )->toBe(2);

            $dealOne =
                HubSpotDeal::query()
                    ->where(
                        'hubspot_id',
                        'deal-1'
                    )
                    ->firstOrFail();

            expect(
                $dealOne
                    ->companies()
                    ->count()
            )->toBe(2);

            expect(
                $dealOne
                    ->contacts()
                    ->count()
            )->toBe(1);

            expect(
                $dealOne
                    ->is_closed
            )->toBeFalse();

            $dealTwo =
                HubSpotDeal::query()
                    ->where(
                        'hubspot_id',
                        'deal-2'
                    )
                    ->firstOrFail();

            expect(
                $dealTwo
                    ->is_closed
            )->toBeTrue();

            expect(
                $dealTwo
                    ->is_closed_won
            )->toBeTrue();

            $task =
                HubSpotTask::query()
                    ->firstOrFail();

            expect(
                $task
                    ->companies()
                    ->count()
            )->toBe(1);

            expect(
                $task
                    ->deals()
                    ->count()
            )->toBe(1);

            expect(
                $task
                    ->contacts()
                    ->count()
            )->toBe(1);
        } finally {
            File::deleteDirectory(
                $directory
            );
        }
    }
);
