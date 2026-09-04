<?php

use App\Models\Company;
use App\Services\ExportIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function exportCompanyForTest(): Company
{
    return Company::query()->create([
        'cnpj_root' => '12345678',

        'corporate_name' => 'Empresa Exportadora Teste',
    ]);
}

it('creates the initial export intelligence state', function () {
    $company =
        exportCompanyForTest();

    $intelligence = app(
        ExportIntelligenceService::class
    )->ensure(
        $company
    );

    expect(
        $intelligence->direct_status
    )->toBe(
        'not_researched'
    );

    expect(
        $intelligence->indirect_status
    )->toBe(
        'not_researched'
    );

    expect(
        $intelligence->trading_status
    )->toBe(
        'not_researched'
    );

    expect(
        $intelligence->direct_confidence
    )->toBe(0);

    expect(
        $intelligence->direct_confirmed
    )->toBeFalse();
});

it('classifies direct export with confidence', function () {
    $company =
        exportCompanyForTest();

    $intelligence = app(
        ExportIntelligenceService::class
    )->classify(
        company: $company,
        dimension: 'direct',
        status: 'yes',
        confidence: 92,
        confirmed: false,
        summary: 'Há evidências públicas de '
            .'exportação própria.',
    );

    expect(
        $intelligence->direct_status
    )->toBe('yes');

    expect(
        $intelligence->direct_confidence
    )->toBe(92);

    expect(
        $intelligence->direct_confirmed
    )->toBeFalse();

    expect(
        $intelligence->direct_summary
    )->toContain(
        'evidências públicas'
    );

    expect(
        $intelligence->researched_at
    )->not->toBeNull();
});

it('records auditable export evidence', function () {
    $company =
        exportCompanyForTest();

    $evidence = app(
        ExportIntelligenceService::class
    )->recordEvidence(
        company: $company,
        dimension: 'indirect',
        signal: 'positive',
        sourceType: 'company_site',
        evidenceText: 'A empresa informa vendas '
            .'destinadas à exportação.',
        confidence: 85,
        confirmed: false,
        sourceName: 'Site institucional',
        sourceUrl: 'https://example.com/exportacao',
        title: 'Operações de exportação',
    );

    expect(
        $evidence->dimension
    )->toBe('indirect');

    expect(
        $evidence->signal
    )->toBe('positive');

    expect(
        $evidence->confidence
    )->toBe(85);

    expect(
        $company
            ->exportEvidence()
            ->count()
    )->toBe(1);

    expect(
        $company
            ->exportIntelligence()
            ->exists()
    )->toBeTrue();
});

it('rejects invalid export classifications', function () {
    $company =
        exportCompanyForTest();

    $service = app(
        ExportIntelligenceService::class
    );

    expect(
        fn () => $service->classify(
            company: $company,
            dimension: 'invalid',
            status: 'yes',
            confidence: 90,
        )
    )->toThrow(
        InvalidArgumentException::class
    );

    expect(
        fn () => $service->classify(
            company: $company,
            dimension: 'direct',
            status: 'yes',
            confidence: 120,
        )
    )->toThrow(
        InvalidArgumentException::class
    );
});
