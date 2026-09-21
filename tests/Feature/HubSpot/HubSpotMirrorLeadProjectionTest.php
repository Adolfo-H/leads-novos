<?php

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyIcpScore;
use App\Services\HubSpotMirrorLeadProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'creates an operational projection without losing multiple deals',
    function () {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa Teste',
                ]);

        CompanyIcpScore::query()
            ->create([
                'company_id' => $company->id,

                'score' => 90,

                'grade' => 'A',

                'label' => 'Alta aderência',

                'version' => 'v2',

                'factors' => [],

                'calculated_at' => now(),
            ]);

        CompanyCrmCheck::query()
            ->create([
                'company_id' => $company->id,

                'provider' => 'hubspot',

                'status' => 'opportunity',

                'external_id' => 'company-1',

                'external_name' => 'Empresa Teste',

                'contacted_count' => 5,

                'associated_deals_count' => 2,

                'matched_by' => 'cnpj_root',

                'matched_value' => '12345678',

                'metadata' => [
                    'deals' => [
                        [
                            'id' => 'deal-1',

                            'name' => 'Negócio 1',

                            'stage_id' => 'stage-1',

                            'stage_label' => 'Proposta apresentada',

                            'pipeline_id' => 'pipeline-1',

                            'is_closed' => false,

                            'is_closed_won' => false,

                            'closed_at' => null,
                        ],
                        [
                            'id' => 'deal-2',

                            'name' => 'Negócio 2',

                            'stage_id' => 'stage-2',

                            'stage_label' => 'Recusou',

                            'pipeline_id' => 'pipeline-1',

                            'is_closed' => true,

                            'is_closed_won' => false,

                            'closed_at' => '2026-01-10T12:00:00-03:00',
                        ],
                    ],

                    'open_tasks' => [
                        [
                            'id' => 'task-1',

                            'title' => 'Retornar cliente',

                            'status' => 'Não iniciado',

                            'type' => 'Ligar',

                            'assigned_to' => 'Adolfo Heerdt',

                            'due_at' => '2026-09-22T10:00:00-03:00',
                        ],
                    ],

                    'owner_names' => [
                        'Adolfo Heerdt',
                    ],

                    'hubspot_company_ids' => [
                        'company-1',
                    ],
                ],

                'checked_at' => now(),
            ]);

        $lead =
            app(
                HubSpotMirrorLeadProjectionService::class
            )->sync(
                $company
            );

        expect(
            $lead->work_status
        )->toBe(
            'waiting'
        );

        expect(
            $lead->hubspot_company_id
        )->toBe(
            'company-1'
        );

        expect(
            $lead->hubspot_deal_id
        )->toBe(
            'deal-1'
        );

        expect(
            $lead->open_task_count
        )->toBe(1);

        expect(
            data_get(
                $lead->metadata,
                'deals'
            )
        )->toHaveCount(2);

        expect(
            data_get(
                $lead->metadata,
                'qualification_snapshot.score'
            )
        )->toBe(30);

        expect(
            data_get(
                $lead->metadata,
                'projection.source'
            )
        )->toBe(
            'hubspot_mirror'
        );
    }
);
