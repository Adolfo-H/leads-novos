<?php

use App\Models\Company;
use App\Services\ExportIntelligenceScoringService;
use App\Services\ExportIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function scoringCompanyForTest(): Company
{
    return Company::query()->create([
        'cnpj_root' => '87654321',

        'corporate_name' => 'Empresa Scoring Exportação',
    ]);
}

it('classifies strong positive evidence as yes', function () {
    $company =
        scoringCompanyForTest();

    app(
        ExportIntelligenceService::class
    )->recordEvidence(
        company: $company,
        dimension: 'direct',
        signal: 'positive',
        sourceType: 'government',
        evidenceText: 'Fonte pública indica '
            .'exportação própria.',
        confidence: 85,
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->direct_status
    )->toBe('yes');

    expect(
        $intelligence->direct_confidence
    )->toBe(85);

    expect(
        $intelligence->direct_confirmed
    )->toBeFalse();
});

it('combines multiple moderate evidences', function () {
    $company =
        scoringCompanyForTest();

    $service = app(
        ExportIntelligenceService::class
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'indirect',
        signal: 'positive',
        sourceType: 'company_site',
        evidenceText: 'Primeiro indício de '
            .'venda destinada à exportação.',
        confidence: 50,
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'indirect',
        signal: 'positive',
        sourceType: 'news',
        evidenceText: 'Segundo indício independente.',
        confidence: 50,
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->indirect_status
    )->toBe('yes');

    /*
     * 50 + 50 pela combinação probabilística
     * resulta em 75.
     */
    expect(
        $intelligence->indirect_confidence
    )->toBe(75);
});

it('marks conflicting strong evidence as uncertain', function () {
    $company =
        scoringCompanyForTest();

    $service = app(
        ExportIntelligenceService::class
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'trading',
        signal: 'positive',
        sourceType: 'news',
        evidenceText: 'Há relação comercial '
            .'com trading.',
        confidence: 85,
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'trading',
        signal: 'negative',
        sourceType: 'company_site',
        evidenceText: 'Outra fonte contradiz '
            .'a existência dessa relação.',
        confidence: 80,
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->trading_status
    )->toBe('uncertain');

    expect(
        $intelligence->trading_confidence
    )->toBe(85);
});

it('gives confirmed evidence maximum priority', function () {
    $company =
        scoringCompanyForTest();

    app(
        ExportIntelligenceService::class
    )->recordEvidence(
        company: $company,
        dimension: 'indirect',
        signal: 'positive',
        sourceType: 'manual',
        evidenceText: 'O responsável da empresa '
            .'confirmou a operação indireta.',
        confidence: 40,
        confirmed: true,
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->indirect_status
    )->toBe('yes');

    expect(
        $intelligence->indirect_confidence
    )->toBe(100);

    expect(
        $intelligence->indirect_confirmed
    )->toBeTrue();
});

it('detects conflicting confirmed evidence', function () {
    $company =
        scoringCompanyForTest();

    $service = app(
        ExportIntelligenceService::class
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'direct',
        signal: 'positive',
        sourceType: 'manual',
        evidenceText: 'Primeira confirmação.',
        confidence: 100,
        confirmed: true,
    );

    $service->recordEvidence(
        company: $company,
        dimension: 'direct',
        signal: 'negative',
        sourceType: 'manual',
        evidenceText: 'Segunda confirmação contraditória.',
        confidence: 100,
        confirmed: true,
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->direct_status
    )->toBe('uncertain');

    expect(
        $intelligence->direct_confidence
    )->toBe(100);

    expect(
        $intelligence->direct_confirmed
    )->toBeFalse();
});

it('does not overwrite a manual confirmed classification', function () {
    $company =
        scoringCompanyForTest();

    $service = app(
        ExportIntelligenceService::class
    );

    $service->classify(
        company: $company,
        dimension: 'direct',
        status: 'yes',
        confidence: 100,
        confirmed: true,
        summary: 'Confirmado manualmente.',
    );

    /*
     * Mesmo uma evidência automática
     * forte não deve sobrescrever
     * confirmação humana.
     */
    $service->recordEvidence(
        company: $company,
        dimension: 'direct',
        signal: 'negative',
        sourceType: 'news',
        evidenceText: 'Indício público contrário.',
        confidence: 95,
        confirmed: false,
    );

    $intelligence = app(
        ExportIntelligenceScoringService::class
    )->recalculateDimension(
        company: $company,
        dimension: 'direct',
    );

    expect(
        $intelligence->direct_status
    )->toBe('yes');

    expect(
        $intelligence->direct_confidence
    )->toBe(100);

    expect(
        $intelligence->direct_confirmed
    )->toBeTrue();
});
