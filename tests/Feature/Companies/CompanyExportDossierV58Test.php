<?php

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Models\User;
use App\Services\CompanyExportDossierV58Service;
use App\Services\ExportResearchQueryPlanner;
use App\Services\Providers\TavilyExportResearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @param  list<CompanyExportEvidence>  $evidences
 */
function v58CompanyWithEvidence(array $evidences): Company
{
    $company = Company::query()->create([
        'cnpj_root' => '99585858',
        'corporate_name' => 'Empresa Exportadora V58',
    ]);

    $company->exportIntelligence()->create([
        'direct_status' => 'uncertain',
        'direct_confidence' => 50,
        'indirect_status' => 'uncertain',
        'indirect_confidence' => 51,
        'trading_status' => 'uncertain',
        'trading_confidence' => 51,
        'research_status' => 'completed',
        'metadata' => [],
    ]);

    foreach ($evidences as $evidence) {
        $company->exportEvidence()->save($evidence);
    }

    return $company;
}

function v58Evidence(string $dimension, string $signal, int $confidence, string $url, array $metadata = []): CompanyExportEvidence
{
    return new CompanyExportEvidence([
        'fingerprint' => hash('sha256', $dimension.'|'.$signal.'|'.$url),
        'dimension' => $dimension,
        'signal' => $signal,
        'confidence' => $confidence,
        'source_type' => 'news',
        'source_name' => 'Portal de negócios',
        'source_url' => $url,
        'title' => 'Empresa atua com trading de grãos e logística para exportação',
        'evidence_text' => 'A empresa descreve operação de trading com soja e milho para o mercado asiático.',
        'is_confirmed' => false,
        'metadata' => $metadata,
    ]);
}

it('does not turn a neutral confidence of 50 into proof of exportation', function (): void {
    $company = v58CompanyWithEvidence([
        v58Evidence('direct', 'neutral', 50, 'https://example.com/direta'),
        v58Evidence('trading', 'neutral', 51, 'https://example.com/trading'),
    ]);
    $view = app(CompanyExportDossierV58Service::class)->build($company);

    expect($view['status'])->toBe('Possivelmente exporta')
        ->and($view['index'])->toBeLessThanOrEqual(35)
        ->and($view['has_support'])->toBeFalse()
        ->and($view['dimensions'][0]['context_count'])->toBe(1)
        ->and($view['dimensions'][1]['context_count'])->toBe(1)
        ->and($view['dimensions'])->toHaveCount(2)
        ->and($view['dimensions'][0]['badge'])->toBe('Indício contextual')
        ->and($view['dimensions'][1]['badge'])->toBe('Indício contextual');
});

it('rewards separate positive source URLs but never duplicates the same article', function (): void {
    $company = v58CompanyWithEvidence([
        v58Evidence('direct', 'positive', 80, 'https://example.com/artigo-1'),
        v58Evidence('direct', 'positive', 80, 'https://example.com/artigo-2'),
        v58Evidence('direct', 'positive', 80, 'https://example.com/artigo-1?utm_source=test'),
    ]);
    $view = app(CompanyExportDossierV58Service::class)->build($company);

    expect($view['status'])->toBe('Possivelmente exporta')
        ->and($view['index'])->toBe(76)
        ->and($view['dimensions'][0]['positive_count'])->toBe(2);
});

it('never presents query or observation dates as publication dates', function (): void {
    $service = app(CompanyExportDossierV58Service::class);
    $undated = v58Evidence('indirect', 'neutral', 50, 'https://example.com/sem-data');
    $undated->setAttribute('observed_at', '2026-10-26 16:00:00');

    $published = v58Evidence('indirect', 'positive', 78, 'https://example.com/publicacao', [
        'source_published_at' => '2026-10-21',
        'research_query_count' => 8,
    ]);
    $published->setAttribute('observed_at', '2026-10-26 16:00:00');

    expect($service->publicationDate($undated))->toBeNull()
        ->and($service->publicationDate($published))->toBe('21/10/2026');
});

it('renders concise findings and only direct or indirect dimensions in the real dossier', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = v58CompanyWithEvidence([
        v58Evidence('trading', 'positive', 78, 'https://example.com/trading-v58', ['published_at' => '2026-10-21']),
    ]);

    $this->actingAs($user)->get(route('companies.show', $company))
        ->assertSuccessful()
        ->assertSee('Possivelmente exporta')
        ->assertSee('Produtos citados')
        ->assertSee('Força dos indícios')
        ->assertSee('21/10/2026')
        ->assertSee('Direta')
        ->assertSee('Indireta')
        ->assertDontSee('<td><strong>Trading</strong></td>', false);
});

it('preserves the publication date from Tavily when the result actually supplies one', function (): void {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',
        'services.tavily.base_url' => 'https://api.tavily.com',
        'services.tavily.max_results' => 5,
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '55667788',
        'corporate_name' => 'AGRO EXPORTADORA TESTE S.A.',
    ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'results' => [[
                'title' => 'Exportação de produtos',
                'url' => 'https://empresa.test/exportacoes',
                'content' => 'A AGRO EXPORTADORA TESTE S.A. exporta para diversos países.',
                'score' => 0.92,
                'published_date' => '2026-10-20',
            ]],
        ]),
    ]);

    $findings = app(TavilyExportResearchProvider::class)
        ->research($company, app(ExportResearchQueryPlanner::class)->queries($company));

    expect($findings)->not->toBeEmpty()
        ->and($findings[0]['metadata']['source_published_at'] ?? null)->toBe('2026-10-20');
});
