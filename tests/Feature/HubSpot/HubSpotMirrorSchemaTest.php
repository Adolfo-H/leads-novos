<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotImportRun;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it(
    'stores multiple deals and associations from HubSpot',
    function () {
        $run =
            HubSpotImportRun::query()
                ->create([
                    'uuid' => (string) Str::uuid(),

                    'source' => 'export',

                    'status' => 'completed',
                ]);

        $stage =
            HubSpotPipelineStage::query()
                ->create([
                    'pipeline_label' => 'Pipeline de vendas',

                    'stage_label' => 'Leds qualificado',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $companyA =
            HubSpotCompany::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_id' => 'company-a',

                    'name' => 'Empresa A',
                ]);

        $companyB =
            HubSpotCompany::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_id' => 'company-b',

                    'name' => 'Empresa B',
                ]);

        $dealOne =
            HubSpotDeal::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_pipeline_stage_id' => $stage->id,

                    'hubspot_id' => 'deal-1',

                    'name' => 'Negócio 1',

                    'pipeline_label' => 'Pipeline de vendas',

                    'stage_label' => 'Leds qualificado',
                ]);

        $dealTwo =
            HubSpotDeal::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_id' => 'deal-2',

                    'name' => 'Negócio 2',

                    'pipeline_label' => 'Pipeline de vendas',

                    'stage_label' => 'Proposta apresentada',
                ]);

        /*
         * Uma empresa com vários negócios.
         */
        $companyA
            ->deals()
            ->attach(
                $dealOne->id,
                [
                    'is_primary' => true,
                ]
            );

        $companyA
            ->deals()
            ->attach(
                $dealTwo->id
            );

        /*
         * Um mesmo negócio associado
         * a mais de uma empresa.
         */
        $companyB
            ->deals()
            ->attach(
                $dealOne->id
            );

        expect(
            $companyA
                ->deals()
                ->count()
        )->toBe(2);

        expect(
            $dealOne
                ->companies()
                ->count()
        )->toBe(2);

        $contact =
            HubSpotContact::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_id' => 'contact-1',

                    'first_name' => 'Maria',

                    'email' => 'maria@example.com',
                ]);

        $companyA
            ->contacts()
            ->attach(
                $contact->id,
                [
                    'is_primary' => true,
                ]
            );

        $dealOne
            ->contacts()
            ->attach(
                $contact->id
            );

        $task =
            HubSpotTask::query()
                ->create([
                    'hubspot_import_run_id' => $run->id,

                    'hubspot_id' => 'task-1',

                    'title' => 'Retornar contato',

                    'status' => 'NOT_STARTED',

                    'is_open' => true,
                ]);

        $task
            ->companies()
            ->attach(
                $companyA->id
            );

        $task
            ->deals()
            ->attach(
                $dealOne->id
            );

        $task
            ->contacts()
            ->attach(
                $contact->id
            );

        expect(
            $contact
                ->companies()
                ->count()
        )->toBe(1);

        expect(
            $contact
                ->deals()
                ->count()
        )->toBe(1);

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

        expect(
            $dealOne
                ->stage
                ?->stage_label
        )->toBe(
            'Leds qualificado'
        );
    }
);
