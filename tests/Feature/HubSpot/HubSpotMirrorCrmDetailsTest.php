<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Services\HubSpotMirrorCrmSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'keeps contacts tasks owners and deals in the CRM mirror',
    function () {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa Teste',
                ]);

        $hubspot =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'hubspot_id' => 'company-1',

                    'name' => 'Empresa Teste',

                    'owner_name' => 'Adolfo Heerdt',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'deal-1',

                    'name' => 'Negócio principal',

                    'stage_label' => 'Proposta apresentada',

                    'owner_name' => 'Samuel',

                    'is_closed' => false,

                    'is_closed_won' => false,
                ]);

        $contact =
            HubSpotContact::query()
                ->create([
                    'hubspot_id' => 'contact-1',

                    'first_name' => 'Maria',

                    'last_name' => 'Silva',

                    'email' => 'maria@example.com',

                    'job_title' => 'Gerente Fiscal',
                ]);

        $task =
            HubSpotTask::query()
                ->create([
                    'hubspot_id' => 'task-1',

                    'title' => 'Retornar contato',

                    'status' => 'Não iniciado',

                    'assigned_to' => 'Adolfo Heerdt',

                    'is_open' => true,

                    'due_at' => now()->addDay(),
                ]);

        $hubspot
            ->deals()
            ->attach(
                $deal->id
            );

        $hubspot
            ->contacts()
            ->attach(
                $contact->id
            );

        $hubspot
            ->tasks()
            ->attach(
                $task->id
            );

        $check =
            app(
                HubSpotMirrorCrmSyncService::class
            )->sync(
                $company
            );

        expect(
            data_get(
                $check->metadata,
                'contact_count'
            )
        )->toBe(1);

        expect(
            data_get(
                $check->metadata,
                'contacts.0.email'
            )
        )->toBe(
            'maria@example.com'
        );

        expect(
            data_get(
                $check->metadata,
                'open_task_count'
            )
        )->toBe(1);

        expect(
            data_get(
                $check->metadata,
                'deals.0.owner_name'
            )
        )->toBe(
            'Samuel'
        );

        expect(
            data_get(
                $check->metadata,
                'owner_names'
            )
        )->toContain(
            'Adolfo Heerdt',
            'Samuel',
        );
    }
);
