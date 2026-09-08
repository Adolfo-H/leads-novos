<?php

use App\Contracts\ExportResearchProvider;
use App\Jobs\ResearchCompanyExports;
use App\Models\Company;
use App\Services\ExportResearchQueryPlanner;
use App\Services\Providers\TavilyExportResearchProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

it('converts Tavily results into structured export evidence', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '12345678',

            'corporate_name' => 'AGRO EXPORTADORA TESTE S.A.',
        ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Empresa amplia exportações',

                        'url' => 'https://empresa.test/exportacoes',

                        'content' => 'A companhia exporta para diversos países.',

                        'score' => 0.92,
                    ],
                ],
            ])
            ->push([
                'results' => [
                    [
                        'title' => 'Operação indireta',

                        'url' => 'https://www.gov.br/exemplo',

                        'content' => 'A operação utiliza venda com fim específico de exportação.',

                        'score' => 0.88,
                    ],
                ],
            ])
            ->push([
                'results' => [
                    [
                        'title' => 'Parceria comercial',

                        'url' => 'https://portal.test/trading',

                        'content' => 'A empresa mantém operação com trading company.',

                        'score' => 0.81,
                    ],
                ],
            ]),
    ]);

    $queries =
        app(
            ExportResearchQueryPlanner::class
        )->queries(
            $company
        );

    $findings =
        app(
            TavilyExportResearchProvider::class
        )->research(
            $company,
            $queries,
        );

    expect(
        $findings
    )->toHaveCount(3);

    expect(
        $findings[0]['dimension']
    )->toBe('direct');

    expect(
        $findings[0]['signal']
    )->toBe('positive');

    expect(
        $findings[1]['dimension']
    )->toBe('indirect');

    expect(
        $findings[1]['signal']
    )->toBe('positive');

    expect(
        $findings[1]['source_type']
    )->toBe('government');

    expect(
        $findings[2]['dimension']
    )->toBe('trading');

    expect(
        $findings[2]['signal']
    )->toBe('positive');

    Http::assertSentCount(3);

    Http::assertSent(
        fn ($request): bool => $request->url()
                ===
                'https://api.tavily.com/search'
            && $request[
                'search_depth'
            ] === 'basic'
            && $request[
                'max_results'
            ] === 5
            && $request[
                'include_answer'
            ] === false
    );
});

it('uses Tavily as the default export research provider', function () {
    expect(
        app(
            ExportResearchProvider::class
        )
    )->toBeInstanceOf(
        TavilyExportResearchProvider::class
    );
});

it('researches an eligible company with Tavily and stores export intelligence', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Exportações da companhia',

                        'url' => 'https://empresa.test/exportacoes',

                        'content' => 'A empresa exporta para mercados da Europa e Ásia.',

                        'score' => 0.95,
                    ],
                ],
            ])
            ->push([
                'results' => [
                    [
                        'title' => 'Operação indireta',

                        'url' => 'https://www.gov.br/operacao-indireta',

                        'content' => 'A companhia realiza venda com fim específico de exportação.',

                        'score' => 0.91,
                    ],
                ],
            ])
            ->push([
                'results' => [
                    [
                        'title' => 'Operação com trading',

                        'url' => 'https://portal.test/trading',

                        'content' => 'A companhia mantém operação com trading company.',

                        'score' => 0.89,
                    ],
                ],
            ]),
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '87654321',

            'corporate_name' => 'EMPRESA EXPORTADORA INTEGRACAO S.A.',
        ]);

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

    Bus::dispatchSync(
        new ResearchCompanyExports(
            companyId: $company->id,
        )
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence->research_status
    )->toBe(
        'completed'
    );

    expect(
        $intelligence->research_provider
    )->toBe(
        'tavily'
    );

    expect(
        $intelligence->direct_status
    )->toBe(
        'yes'
    );

    expect(
        $intelligence->indirect_status
    )->toBe(
        'yes'
    );

    expect(
        $intelligence->trading_status
    )->toBe(
        'yes'
    );

    expect(
        $company
            ->exportEvidence()
            ->count()
    )->toBe(3);

    Http::assertSentCount(3);
});
