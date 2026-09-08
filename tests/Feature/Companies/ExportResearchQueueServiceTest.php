<?php

use App\Contracts\ExportResearchProvider;
use App\Jobs\ResearchCompanyExports;
use App\Models\Company;
use App\Services\ExportResearchQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function queuedResearchCompany(
    string $crmStatus = 'not_found',
    string $grade = 'A',
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => '66778899',

            'corporate_name' => 'Empresa Pesquisa Background',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => $grade === 'A'
                    ? 90
                    : 40,

            'grade' => $grade,

            'label' => 'Teste',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => $crmStatus,

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    return $company;
}

it('queues export research for an eligible company', function () {
    Queue::fake();

    $company =
        queuedResearchCompany();

    $result = app(
        ExportResearchQueueService::class
    )->dispatch(
        $company
    );

    expect(
        $result->research_status
    )->toBe(
        'queued'
    );

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

it('skips automatic research for an existing client', function () {
    Queue::fake();

    $company =
        queuedResearchCompany(
            crmStatus: 'client',
        );

    $result = app(
        ExportResearchQueueService::class
    )->dispatch(
        $company
    );

    expect(
        $result->research_status
    )->toBe(
        'skipped'
    );

    expect(
        data_get(
            $result->metadata,
            'research_eligibility.reason'
        )
    )->toBe(
        'crm_client'
    );

    Queue::assertNothingPushed();
});

it('processes export research in background', function () {
    $company =
        queuedResearchCompany();

    $provider =
        new class implements ExportResearchProvider
        {
            public function name(): string
            {
                return 'fake-background';
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

                        'source_url' => 'https://example.com/export',

                        'title' => 'Registro exportador',

                        'evidence_text' => 'A empresa aparece como '
                            .'exportadora em fonte pública.',

                        'metadata' => [],
                    ],
                ];
            }
        };

    app()->instance(
        ExportResearchProvider::class,
        $provider
    );

    Bus::dispatchSync(
        new ResearchCompanyExports(
            companyId: $company->id,
        )
    );

    $result =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $result->research_status
    )->toBe(
        'completed'
    );

    expect(
        $result->research_provider
    )->toBe(
        'fake-background'
    );

    expect(
        $result->direct_status
    )->toBe(
        'yes'
    );

    expect(
        $result->direct_confidence
    )->toBe(90);

    expect(
        $result->research_started_at
    )->not->toBeNull();

    expect(
        $result->research_completed_at
    )->not->toBeNull();

    expect(
        $company
            ->exportEvidence()
            ->count()
    )->toBe(1);
});

it('allows a forced research even for a client', function () {
    Queue::fake();

    $company =
        queuedResearchCompany(
            crmStatus: 'client',
        );

    $result = app(
        ExportResearchQueueService::class
    )->dispatch(
        company: $company,
        force: true,
    );

    expect(
        $result->research_status
    )->toBe(
        'queued'
    );

    Queue::assertPushed(
        ResearchCompanyExports::class,
        fn (
            ResearchCompanyExports $job
        ): bool => $job->companyId
                === $company->id
            && $job->force
                === true
    );
});

it('does not automatically queue research while export research is disabled', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => false,
    ]);

    $company =
        queuedResearchCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $result =
        app(
            ExportResearchQueueService::class
        )->dispatchIfEnabled(
            $company
        );

    expect(
        $result['enabled']
    )->toBeFalse();

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['queued']
    )->toBeFalse();

    Queue::assertNothingPushed();
});

it('automatically queues research when an eligible company passes the filter', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => true,
    ]);

    $company =
        queuedResearchCompany(
            crmStatus: 'not_found',
            grade: 'A',
        );

    $result =
        app(
            ExportResearchQueueService::class
        )->dispatchIfEnabled(
            $company
        );

    expect(
        $result['enabled']
    )->toBeTrue();

    expect(
        $result['eligible']
    )->toBeTrue();

    expect(
        $result['queued']
    )->toBeTrue();

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
