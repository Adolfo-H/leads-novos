<?php

use App\Models\Company;
use App\Services\CompanyExportDossierV58Service;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps an empty dossier safe when export intelligence does not exist', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '99585882',
        'corporate_name' => 'Empresa V582 Sem Pesquisa',
    ]);

    $result = app(CompanyExportDossierV58Service::class)->build($company);

    expect($result['status'])->toBe('Não identificada')
        ->and($result['index'])->toBe(0)
        ->and($result['has_support'])->toBeFalse()
        ->and($result['dimensions'][0]['badge'])->toBe('Não identificada')
        ->and($result['dimensions'][1]['badge'])->toBe('Não identificada');
});

it('preserves manual confirmations of both direct and indirect exporting', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '99585883',
        'corporate_name' => 'Empresa V582 Confirmada',
    ]);

    $company->exportIntelligence()->create([
        'direct_status' => 'yes',
        'direct_confidence' => 100,
        'direct_confirmed' => true,
        'indirect_status' => 'yes',
        'indirect_confidence' => 100,
        'indirect_confirmed' => true,
        'trading_status' => 'not_researched',
        'trading_confidence' => 0,
        'trading_confirmed' => false,
        'metadata' => [],
    ]);

    $result = app(CompanyExportDossierV58Service::class)->build($company);

    expect($result['status'])->toBe('Sim, confirmado')
        ->and($result['index'])->toBe(100)
        ->and($result['has_support'])->toBeTrue()
        ->and($result['dimensions'][0]['badge'])->toBe('Sim, validada')
        ->and($result['dimensions'][1]['badge'])->toBe('Sim, validada');
});
