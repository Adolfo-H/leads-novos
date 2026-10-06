<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Services\HubSpotWebhookAssociationResolver;

it('resolves a HubSpot task locally to its Prospector company', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '97777777',

                'corporate_name' => 'Empresa Task Realtime',
            ]);

    $hubSpotCompany =
        HubSpotCompany::query()
            ->create([
                'company_id' => $company->id,

                'hubspot_id' => 'hs-company-task-realtime',

                'name' => 'Empresa Task Realtime',
            ]);

    $task =
        HubSpotTask::query()
            ->create([
                'hubspot_id' => 'hs-task-realtime',

                'title' => 'Follow-up realtime',
            ]);

    $hubSpotCompany
        ->tasks()
        ->attach(
            $task->id
        );

    $ids =
        app(
            HubSpotWebhookAssociationResolver::class
        )->companyIds(
            objectType: 'task',

            objectId: 'hs-task-realtime',
        );

    expect(
        $ids
    )->toBe([
        $company->id,
    ]);
});
