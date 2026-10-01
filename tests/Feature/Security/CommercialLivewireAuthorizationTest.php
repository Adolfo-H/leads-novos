<?php

use App\Jobs\ResearchCompanyExports;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function securityCompany(
    string $root,
    string $name,
): Company {
    return Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
    ]);
}

function assignSecurityCompany(
    Company $company,
    User $user,
): void {
    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,
            'status' => 'new',
        ]);
}

it('revalidates manager access before management actions', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,
            ]);

    $component =
        Livewire::actingAs(
            $manager
        )->test(
            'pages::leads.management'
        );

    /*
     * Simula o usuário perdendo o
     * perfil enquanto a página está
     * aberta no navegador.
     */
    $manager
        ->forceFill([
            'commercial_role' => User::ROLE_SELLER,
        ])
        ->save();

    $component
        ->call(
            'clearDistributionSellers'
        )
        ->assertStatus(
            403
        );
});

it('allows an assigned seller to open the dossier', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '81111111',
            'Empresa Dossie Atribuida',
        );

    assignSecurityCompany(
        $company,
        $seller,
    );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->assertSee(
            'Empresa Dossie Atribuida'
        );
});

it('blocks direct dossier access without ownership', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '82222222',
            'Empresa Dossie Bloqueada',
        );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->assertStatus(
            403
        );
});

it('revalidates ownership after the dossier was opened', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '83333333',
            'Empresa Carteira Revogada',
        );

    assignSecurityCompany(
        $company,
        $seller,
    );

    $component =
        Livewire::actingAs(
            $seller
        )
            ->test(
                'pages::companies.show',
                [
                    'company' => $company,
                ]
            );

    $company
        ->leadWorkState()
        ->delete();

    $component
        ->call(
            'refreshExportResearch'
        )
        ->assertStatus(
            403
        );
});

it('blocks sellers from changing cnaes', function () {
    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '84444444',
            'Empresa CNAE Protegido',
        );

    assignSecurityCompany(
        $company,
        $seller,
    );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->set(
            'newCnaeCode',
            '4622200'
        )
        ->call(
            'addCnae'
        )
        ->assertStatus(
            403
        );
});

it('allows assigned sellers to run normal eligible export research', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => true,
        'services.tavily.api_key' => 'fake-tavily-key',
    ]);

    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '85555555',
            'Empresa Pesquisa Normal',
        );

    assignSecurityCompany(
        $company,
        $seller,
    );

    $company
        ->icpScore()
        ->create([
            'score' => 90,
            'grade' => 'A',
            'label' => 'ICP A',
            'version' => 'test',
            'factors' => [],
            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',
            'status' => 'not_found',
            'contacted_count' => 0,
            'associated_deals_count' => 0,
            'metadata' => [],
            'checked_at' => now(),
        ]);

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->call(
            'researchExports'
        )
        ->assertHasNoErrors();

    Queue::assertPushed(
        ResearchCompanyExports::class,
        fn (
            ResearchCompanyExports $job
        ): bool => $job->companyId
                === $company->id
            && $job->force
                === false
    );
});

it('blocks forced export research for sellers', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => true,
        'services.tavily.api_key' => 'fake-tavily-key',
    ]);

    $seller =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_SELLER,
            ]);

    $company =
        securityCompany(
            '86666666',
            'Empresa Pesquisa Forcada',
        );

    assignSecurityCompany(
        $company,
        $seller,
    );

    Livewire::actingAs(
        $seller
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->call(
            'researchExportsForce'
        )
        ->assertStatus(
            403
        );

    Queue::assertNotPushed(
        ResearchCompanyExports::class,
    );
});
