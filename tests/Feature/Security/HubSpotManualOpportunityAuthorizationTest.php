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
