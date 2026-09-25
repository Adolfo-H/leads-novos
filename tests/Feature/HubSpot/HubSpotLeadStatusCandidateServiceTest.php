<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Services\HubSpotLeadStatusCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function statusCandidateLead(
    string $root,
    string $name,
    string $status,
    ?string $dueAt,
    ?string $syncedAt,
): CompanyHubSpotLead {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => $root,

                'corporate_name' => $name,
            ]);

    return CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-'.$root,

            'hubspot_deal_id' => 'deal-'.$root,

            'work_status' => $status,

            'commercial_status' => 'known',

            'open_task_count' => $status === 'waiting'
                    ? 1
                    : 0,

            'last_task_due_at' => $dueAt,

            'status_synced_at' => $syncedAt,

            'metadata' => [],
        ]);
}

it(
    'prioritizes overdue waiting leads before the rest',
    function (): void {
        $oldOther =
            statusCandidateLead(
                root: '10000001',

                name: 'Lead comum antigo',

                status: 'contacting',

                dueAt: null,

                syncedAt: now()
                    ->subDays(10)
                    ->toIso8601String(),
            );

        $futureWaiting =
            statusCandidateLead(
                root: '10000002',

                name: 'Aguardando futuro',

                status: 'waiting',

                dueAt: now()
                    ->addDays(3)
                    ->toIso8601String(),

                syncedAt: now()
                    ->subDays(5)
                    ->toIso8601String(),
            );

        $overdueRecent =
            statusCandidateLead(
                root: '10000003',

                name: 'Atrasado recente',

                status: 'waiting',

                dueAt: now()
                    ->subHour()
                    ->toIso8601String(),

                syncedAt: now()
                    ->subHour()
                    ->toIso8601String(),
            );

        $overdueOld =
            statusCandidateLead(
                root: '10000004',

                name: 'Atrasado antigo',

                status: 'waiting',

                dueAt: now()
                    ->subDays(2)
                    ->toIso8601String(),

                syncedAt: now()
                    ->subDays(2)
                    ->toIso8601String(),
            );

        $result =
            app(
                HubSpotLeadStatusCandidateService::class
            )->candidates(
                10
            );

        expect(
            $result
                ->pluck('id')
                ->all()
        )->toBe([
            $overdueOld->id,
            $overdueRecent->id,
            $futureWaiting->id,
            $oldOther->id,
        ]);
    }
);

it(
    'prioritizes never synced records inside the same group',
    function (): void {
        $alreadySynced =
            statusCandidateLead(
                root: '20000001',

                name: 'Waiting sincronizado',

                status: 'waiting',

                dueAt: now()
                    ->addDay()
                    ->toIso8601String(),

                syncedAt: now()
                    ->subDays(20)
                    ->toIso8601String(),
            );

        $neverSynced =
            statusCandidateLead(
                root: '20000002',

                name: 'Waiting nunca sincronizado',

                status: 'waiting',

                dueAt: now()
                    ->addDay()
                    ->toIso8601String(),

                syncedAt: null,
            );

        $result =
            app(
                HubSpotLeadStatusCandidateService::class
            )->candidates(
                2
            );

        expect(
            $result
                ->pluck('id')
                ->all()
        )->toBe([
            $neverSynced->id,
            $alreadySynced->id,
        ]);
    }
);
