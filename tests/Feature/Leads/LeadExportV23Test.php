<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Services\LeadUnifiedExcelV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exports CRM-only records with blank CNPJ and actual deal stage', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'crm-only-v23',
        'name' => 'Lead sem CNPJ V23',
        'owner_name' => 'Vendedor Importado',
        'phone' => '(11) 3444-5566',
        'state' => 'SP',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'deal-crm-v23', 'stage_label' => 'Descartes', 'is_closed' => true,
    ]);
    $deal->companies()->attach($company->id, ['is_primary' => true]);

    $row = app(LeadUnifiedExcelV23Service::class)->hubSpotRow($company);
    expect($row['CNPJ'])->toBe('')
        ->and($row['HubSpot Company ID'])->toBe('crm-only-v23')
        ->and($row['Etapas HubSpot'])->toBe('Descartes')
        ->and($row['Origem'])->toBe('HubSpot sem CNPJ');
    expect(app(LeadUnifiedExcelV23Service::class)->excel(collect(), collect([$company]))
        ->headers->get('content-type'))->toContain('spreadsheetml');
});

it('removes expanding Details but preserves direct dossier and HubSpot links', function (): void {
    $blade = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    expect($blade)->not->toContain('x-show="detailsOpen"')
        ->not->toContain('lv13-details lv16-details')
        ->toContain('lv23-actions')
        ->toContain('HubSpot ↗')
        ->toContain('Dossiê ↗')
        ->toContain('claimLead(')
        ->toContain('resumeLead(');
});
