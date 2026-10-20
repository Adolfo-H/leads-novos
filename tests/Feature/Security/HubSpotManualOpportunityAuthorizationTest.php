<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows a commercial manager to create a manual HubSpot opportunity', function () {
    $manager =
        User::factory()->create([
            'commercial_role' => User::ROLE_MANAGER,
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '97979797',

            'corporate_name' => 'Empresa Autorização HubSpot',
        ]);

    expect(
        Gate::forUser(
            $manager
        )->allows(
            'createHubSpotOpportunity',
            $company
        )
    )->toBeTrue();
});

it('denies a seller from creating an opportunity for an unassigned company', function () {
    $seller =
        User::factory()->create([
            'commercial_role' => User::ROLE_SELLER,
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '96969696',
            'corporate_name' => 'Empresa Fora da Carteira',
        ]);

    expect(
        Gate::forUser($seller)->allows(
            'createHubSpotOpportunity',
            $company
        )
    )->toBeFalse();
});

it('allows a seller to create an opportunity for an assigned company', function () {
    $seller =
        User::factory()->create([
            'commercial_role' => User::ROLE_SELLER,
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '95959595',
            'corporate_name' => 'Empresa na Carteira',
        ]);

    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $seller->id,
            'status' => 'new',
        ]);

    expect(
        Gate::forUser($seller)->allows(
            'createHubSpotOpportunity',
            $company
        )
    )->toBeTrue();
});
