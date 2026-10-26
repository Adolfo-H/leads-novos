<?php

use App\Models\Company;
use App\Models\User;
use App\Services\DossierIcpPresentationV60Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders ICP v3 using detailed Portuguese explanations instead of technical keys', function (): void {
    $factors = [
        'cnae' => ['points' => 20, 'max' => 30, 'matched' => '4622200', 'reason' => 'CNAE secundário prioritário.'],
        'size' => ['points' => 15, 'max' => 15, 'value' => '05', 'description' => 'Demais'],
        '_meta' => ['scope' => 'company_group', 'version' => 'v3'],
        'state' => ['points' => 15, 'max' => 15, 'states' => ['SP', 'GO'], 'matched_states' => ['SP', 'GO']],
        'capital' => ['points' => 15, 'max' => 15, 'value' => 8_000_000],
        'legal_nature' => ['points' => 0, 'max' => 15, 'code' => '2062', 'description' => 'Sociedade Empresária Limitada'],
        'regional_relevance' => ['points' => 10, 'max' => 10, 'establishments' => 5],
    ];

    $rows = app(DossierIcpPresentationV60Service::class)->rows($factors);

    expect($rows)->toHaveCount(6)
        ->and(array_column($rows, 'key'))->toBe(['cnae', 'state', 'size', 'capital', 'legal_nature', 'regional_relevance'])
        ->and($rows[0]['label'])->toBe('CNAE do grupo: secundário prioritário')
        ->and($rows[0]['pct'])->toBe(66.7)
        ->and($rows[1]['label'])->toBe('Estado: SP e GO prioritários')
        ->and($rows[2]['label'])->toBe('Porte: código 05 (Demais)')
        ->and($rows[3]['label'])->toBe('Capital: R$ 8 milhões > R$ 1 milhão')
        ->and($rows[4]['label'])->toBe('Natureza jurídica: 2062 (Ltda.)')
        ->and($rows[5]['label'])->toBe('Relevância regional: 5 unidades ativas');
});

it('renders a dossier with the back-to-leads action and no local-base caption', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create([
        'cnpj_root' => '33998811',
        'corporate_name' => 'Empresa Dossiê UX V60',
    ]);

    $response = $this->actingAs($user)->get(route('companies.show', $company));
    $response->assertSuccessful()
        ->assertSee('data-ec-return-leads', false)
        ->assertSee('Voltar aos leads')
        ->assertDontSee('Dados da base local');
});

it('preserves all business filters in the Leads URL and includes scroll restoration logic', function (): void {
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $script = file_get_contents(resource_path('js/leads-return-v60.js'));
    $app = file_get_contents(resource_path('js/app.js'));

    expect($leads)->toContain('public string $priority =')
        ->toContain('public string $queueDuePeriod =')
        ->toContain('public string $personalHubSpotStage =')
        ->and($script)->toContain('saveLeadsPosition')
        ->toContain('sessionStorage')
        ->toContain('restoreScroll')
        ->and($app)->toContain("import './leads-return-v60.js'");
});
