<?php

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Services\ExportResearchQueryPlanner;
use App\Services\ExportResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function researchCompanyForTest(): Company
{
    return Company::query()->create([
        'cnpj_root' => '99887766',

        'corporate_name' => 'Cooperativa Pesquisa Teste',
    ]);
}

it('plans research queries for all export dimensions', function () {
    $company =
        researchCompanyForTest();

    $queries = app(
        ExportResearchQueryPlanner::class
    )->queries(
        $company
    );

    expect(
        $queries
    )->toHaveCount(8);

    $joined =
        implode(
            PHP_EOL,
            $queries
        );

    expect($joined)
        ->toContain(
            'Cooperativa Pesquisa Teste'
        )
        ->toContain(
            'fim específico de exportação'
        )
        ->toContain(
            'trading'
        );
});

it('turns provider findings into scored export intelligence', function () {
    $company =
        researchCompanyForTest();

    $provider =
        new class implements ExportResearchProvider
        {
            public function name(): string
            {
                return 'fake-web';
            }

            public function research(
                Company $company,
                array $queries,
            ): array {
                return [
                    [
                        'dimension' => 'direct',

                        'signal' => 'positive',

                        'confidence' => 90,

                        'source_type' => 'government',

                        'source_name' => 'Fonte pública',

                        'source_url' => 'https://example.com/direct',

                        'title' => 'Exportação própria',

                        'evidence_text' => 'A empresa aparece como '
                            .'exportadora em fonte pública.',

                        'metadata' => [],
                    ],
                    [
                        'dimension' => 'indirect',

                        'signal' => 'positive',

                        'confidence' => 80,

                        'source_type' => 'company_site',

                        'source_name' => 'Site institucional',

                        'source_url' => 'https://example.com/indirect',

                        'title' => 'Venda para exportação',

                        'evidence_text' => 'O site descreve vendas '
                            .'destinadas à exportação.',

                        'metadata' => [],
                    ],
                    [
                        'dimension' => 'trading',

                        'signal' => 'neutral',

                        'confidence' => 55,

                        'source_type' => 'news',

                        'source_name' => 'Notícia',

                        'source_url' => 'https://example.com/trading',

                        'title' => 'Relação comercial',

                        'evidence_text' => 'A fonte menciona trading, '
                            .'mas sem confirmar relação '
                            .'comercial direta.',

                        'metadata' => [],
                    ],
                ];
            }
        };

    $result = app(
        ExportResearchService::class
    )->research(
        company: $company,
        provider: $provider,
    );

    expect(
        $result->direct_status
    )->toBe('yes');

    expect(
        $result->direct_confidence
    )->toBe(90);

    expect(
        $result->indirect_status
    )->toBe('yes');

    expect(
        $result->indirect_confidence
    )->toBe(80);

    expect(
        $result->trading_status
    )->toBe('uncertain');

    expect(
        $result->trading_confidence
    )->toBe(55);

    expect(
        $company
            ->exportEvidence()
            ->count()
    )->toBe(3);
});

it('does not duplicate evidence when research runs again', function () {
    $company =
        researchCompanyForTest();

    $provider =
        new class implements ExportResearchProvider
        {
            public function name(): string
            {
                return 'fake-web';
            }

            public function research(
                Company $company,
                array $queries,
            ): array {
                return [
                    [
                        'dimension' => 'direct',

                        'signal' => 'positive',

                        'confidence' => 88,

                        'source_type' => 'government',

                        'source_name' => 'Fonte pública',

                        'source_url' => 'https://example.com/export',

                        'title' => 'Registro de exportação',

                        'evidence_text' => 'Registro público demonstra '
                            .'atividade exportadora.',

                        'metadata' => [],
                    ],
                ];
            }
        };

    $service = app(
        ExportResearchService::class
    );

    $service->research(
        $company,
        $provider,
    );

    $service->research(
        $company,
        $provider,
    );

    expect(
        $company
            ->exportEvidence()
            ->count()
    )->toBe(1);

    $evidence =
        $company
            ->exportEvidence()
            ->firstOrFail();

    expect(
        $evidence->fingerprint
    )->not->toBeNull();

    expect(
        strlen(
            $evidence->fingerprint
        )
    )->toBe(64);

    expect(
        $company
            ->exportIntelligence()
            ->firstOrFail()
            ->direct_confidence
    )->toBe(88);
});
