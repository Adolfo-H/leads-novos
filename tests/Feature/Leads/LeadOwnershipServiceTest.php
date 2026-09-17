<?php

use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\CompanyLeadWorkState;
use App\Models\User;
use App\Services\LeadOwnershipService;

it('assigns a salesperson and records the commercial history', function () {
    $actor =
        User::factory()
            ->create([
                'name' => 'Gestor Comercial',
            ]);

    $owner =
        User::factory()
            ->create([
                'name' => 'Vendedor A',
            ]);

    $company =
        Company::factory()
            ->create();

    $this->actingAs(
        $actor
    );

    $state =
        app(
            LeadOwnershipService::class
        )->assign(
            company: $company,
            owner: $owner,
        );

    expect(
        $state->assigned_user_id
    )->toBe(
        $owner->id
    );

    expect(
        $state->assignedUser?->name
    )->toBe(
        'Vendedor A'
    );

    $activity =
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'type',
                'owner_changed'
            )
            ->first();

    expect(
        $activity
    )->not->toBeNull();

    expect(
        $activity?->user_id
    )->toBe(
        $actor->id
    );

    expect(
        $activity?->description
    )->toBe(
        'Sem responsável → Vendedor A'
    );

    expect(
        data_get(
            $activity?->metadata,
            'to_user_id'
        )
    )->toBe(
        $owner->id
    );
});

it('transfers a lead between salespeople', function () {
    $first =
        User::factory()
            ->create([
                'name' => 'Vendedor A',
            ]);

    $second =
        User::factory()
            ->create([
                'name' => 'Vendedor B',
            ]);

    $company =
        Company::factory()
            ->create();

    $service =
        app(
            LeadOwnershipService::class
        );

    $service->assign(
        $company,
        $first
    );

    $state =
        $service->assign(
            $company,
            $second
        );

    expect(
        $state->assigned_user_id
    )->toBe(
        $second->id
    );

    expect(
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'type',
                'owner_changed'
            )
            ->count()
    )->toBe(2);

    expect(
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->latest('id')
            ->value(
                'description'
            )
    )->toBe(
        'Vendedor A → Vendedor B'
    );
});

it('can remove the salesperson from a lead', function () {
    $owner =
        User::factory()
            ->create([
                'name' => 'Vendedor A',
            ]);

    $company =
        Company::factory()
            ->create();

    $service =
        app(
            LeadOwnershipService::class
        );

    $service->assign(
        $company,
        $owner
    );

    $state =
        $service->assign(
            $company,
            null
        );

    expect(
        $state->assigned_user_id
    )->toBeNull();

    expect(
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->latest('id')
            ->value(
                'description'
            )
    )->toBe(
        'Vendedor A → Sem responsável'
    );
});

it('does not duplicate history when the owner does not change', function () {
    $owner =
        User::factory()
            ->create();

    $company =
        Company::factory()
            ->create();

    $service =
        app(
            LeadOwnershipService::class
        );

    $service->assign(
        $company,
        $owner
    );

    $service->assign(
        $company,
        $owner
    );

    expect(
        CompanyLeadActivity::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'type',
                'owner_changed'
            )
            ->count()
    )->toBe(1);

    expect(
        CompanyLeadWorkState::query()
            ->where(
                'company_id',
                $company->id
            )
            ->count()
    )->toBe(1);
});
