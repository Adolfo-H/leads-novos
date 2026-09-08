<?php

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Services\ExportIntelligenceService;
use App\Services\ExportResearchSummaryService;

it('creates a commercial summary from export evidence', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '55443322',

            'corporate_name' => 'EMPRESA RESUMO EXPORTACAO',
        ]);

    $intelligence =
        app(
            ExportIntelligenceService::class
        )->ensure(
            $company
        );

    $intelligence->update([
        'direct_status' => 'yes',

        'direct_confidence' => 88,

        'indirect_status' => 'uncertain',

        'indirect_confidence' => 55,

        'trading_status' => 'uncertain',

        'trading_confidence' => 50,
    ]);

    CompanyExportEvidence::query()
        ->create([
            'company_id' => $company->id,

            'fingerprint' => hash(
                'sha256',
                'summary-test-1'
            ),

            'dimension' => 'direct',

            'signal' => 'positive',

            'source_type' => 'news',

            'source_name' => 'Fonte teste',

            'source_url' => 'https://example.test/export',

            'title' => 'Exportação de soja para China',

            'evidence_text' => 'A companhia exporta soja '
                .'para a China e outros mercados.',

            'confidence' => 88,

            'is_confirmed' => false,

            'metadata' => [],
        ]);

    CompanyExportEvidence::query()
        ->create([
            'company_id' => $company->id,

            'fingerprint' => hash(
                'sha256',
                'summary-test-2'
            ),

            'dimension' => 'trading',

            'signal' => 'neutral',

            'source_type' => 'news',

            'source_name' => 'Fonte teste 2',

            'source_url' => 'https://example.test/trading',

            'title' => 'Operação comercial',

            'evidence_text' => 'Há referência comercial '
                .'relacionada a trading.',

            'confidence' => 50,

            'is_confirmed' => false,

            'metadata' => [],
        ]);

    $summary =
        app(
            ExportResearchSummaryService::class
        )->build(
            company: $company,

            intelligence: $intelligence->refresh(),
        );

    expect(
        $summary[
            'headline'
        ]
    )->toContain(
        'exportação direta'
    );

    expect(
        $summary[
            'products'
        ]
    )->toContain(
        'Soja'
    );

    expect(
        $summary[
            'markets'
        ]
    )->toContain(
        'China'
    );

    expect(
        $summary[
            'evidence_count'
        ]
    )->toBe(2);

    expect(
        $summary[
            'positive_count'
        ]
    )->toBe(1);

    expect(
        $summary[
            'dimensions'
        ][
            'direct'
        ][
            'evidence_count'
        ]
    )->toBe(1);
});
