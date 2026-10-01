<?php

use App\Models\Company;
use App\Services\ExportIntelligenceService;
use App\Services\ExportResearchQueryPlanner;
use App\Services\Providers\TavilyExportResearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('treats an explicit export negation as negative evidence', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '12345678',

                'corporate_name' => 'EMPRESA NEGATIVA TESTE S.A.',
            ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Operacoes da companhia',

                        'url' => 'https://empresa.test/operacoes',

                        'content' => 'A EMPRESA NEGATIVA TESTE S.A. nao exporta para o exterior.',

                        'score' => 0.92,
                    ],
                ],
            ])
            ->push([
                'results' => [],
            ])
            ->push([
                'results' => [],
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
    )->toHaveCount(
        1
    );

    expect(
        $findings[0][
            'dimension'
        ]
    )->toBe(
        'direct'
    );

    expect(
        $findings[0][
            'signal'
        ]
    )->toBe(
        'negative'
    );

    expect(
        $findings[0][
            'confidence'
        ]
    )->toBeGreaterThanOrEqual(
        65
    );

    expect(
        (string)
            data_get(
                $findings[0],
                'metadata.matched_rule'
            )
    )->toContain(
        'nao exporta'
    );
});

it('does not turn a negated direct export sentence into positive evidence', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '13345678',

                'corporate_name' => 'EMPRESA DIRETA NEGADA S.A.',
            ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Atuacao internacional',

                        'url' => 'https://empresa.test/internacional',

                        'content' => 'A EMPRESA DIRETA NEGADA S.A. nao realiza exportacao direta.',

                        'score' => 0.88,
                    ],
                ],
            ])
            ->push([
                'results' => [],
            ])
            ->push([
                'results' => [],
            ]),
    ]);

    $findings =
        app(
            TavilyExportResearchProvider::class
        )->research(
            $company,
            app(
                ExportResearchQueryPlanner::class
            )->queries(
                $company
            ),
        );

    expect(
        $findings
    )->toHaveCount(
        1
    );

    expect(
        $findings[0][
            'signal'
        ]
    )->toBe(
        'negative'
    );
});

it('keeps conflicting positive and negative wording uncertain', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '22345678',

                'corporate_name' => 'EMPRESA CONFLITO TESTE S.A.',
            ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Historico de exportacoes',

                        'url' => 'https://empresa.test/historico',

                        'content' => 'A EMPRESA CONFLITO TESTE S.A. nao exporta atualmente. Em periodo anterior, exportou para outros paises.',

                        'score' => 0.90,
                    ],
                ],
            ])
            ->push([
                'results' => [],
            ])
            ->push([
                'results' => [],
            ]),
    ]);

    $findings =
        app(
            TavilyExportResearchProvider::class
        )->research(
            $company,
            app(
                ExportResearchQueryPlanner::class
            )->queries(
                $company
            ),
        );

    expect(
        $findings
    )->toHaveCount(
        1
    );

    expect(
        $findings[0][
            'signal'
        ]
    )->toBe(
        'neutral'
    );

    expect(
        (string)
            data_get(
                $findings[0],
                'metadata.matched_rule'
            )
    )->toStartWith(
        'conflict:'
    );
});

it('does not interpret nao so exporta as a negation', function () {
    config([
        'services.tavily.api_key' => 'fake-tavily-key',

        'services.tavily.base_url' => 'https://api.tavily.com',

        'services.tavily.max_results' => 5,
    ]);

    $company =
        Company::query()
            ->create([
                'cnpj_root' => '23345678',

                'corporate_name' => 'EMPRESA NAO SO TESTE S.A.',
            ]);

    Http::fake([
        'https://api.tavily.com/search' => Http::sequence()
            ->push([
                'results' => [
                    [
                        'title' => 'Mercados internacionais',

                        'url' => 'https://empresa.test/mercados',

                        'content' => 'A EMPRESA NAO SO TESTE S.A. nao so exporta para a Europa como tambem atende mercados da Asia.',

                        'score' => 0.93,
                    ],
                ],
            ])
            ->push([
                'results' => [],
            ])
            ->push([
                'results' => [],
            ]),
    ]);

    $findings =
        app(
            TavilyExportResearchProvider::class
        )->research(
            $company,
            app(
                ExportResearchQueryPlanner::class
            )->queries(
                $company
            ),
        );

    expect(
        $findings
    )->toHaveCount(
        1
    );

    expect(
        $findings[0][
            'signal'
        ]
    )->toBe(
        'positive'
    );
});

it('preserves human confirmed evidence when automation sees the same source again', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '32345678',

                'corporate_name' => 'EMPRESA REVISAO HUMANA S.A.',
            ]);

    $service =
        app(
            ExportIntelligenceService::class
        );

    /*
     * Simula uma evidência revisada e
     * confirmada manualmente.
     */
    $confirmed =
        $service
            ->recordEvidence(
                company: $company,

                dimension: 'direct',

                signal: 'positive',

                sourceType: 'company_site',

                evidenceText: 'Revisao humana confirmou exportacao.',

                confidence: 100,

                confirmed: true,

                sourceName: 'Site institucional',

                sourceUrl: 'https://empresa.test/exportacao',

                title: 'Exportacao confirmada',

                metadata: [
                    'review_source' => 'human',
                ],
            );

    /*
     * A mesma URL é encontrada novamente.
     *
     * A automação agora interpreta o conteúdo
     * como negativo.
     *
     * Isso NÃO pode apagar a decisão humana.
     */
    $automatic =
        $service
            ->recordEvidence(
                company: $company,

                dimension: 'direct',

                signal: 'negative',

                sourceType: 'company_site',

                evidenceText: 'Nova coleta automatica encontrou outro texto.',

                confidence: 75,

                confirmed: false,

                sourceName: 'Site institucional atualizado',

                sourceUrl: 'https://empresa.test/exportacao',

                title: 'Exportacao atualizada',

                metadata: [
                    'research_provider' => 'test-automation',
                ],
            );

    expect(
        $automatic->id
    )->toBe(
        $confirmed->id
    );

    expect(
        $automatic
            ->is_confirmed
    )->toBeTrue();

    expect(
        $automatic
            ->signal
    )->toBe(
        'positive'
    );

    expect(
        $automatic
            ->confidence
    )->toBe(
        100
    );

    /*
     * O snapshot que foi confirmado
     * também permanece intacto.
     */
    expect(
        $automatic
            ->evidence_text
    )->toBe(
        'Revisao humana confirmou exportacao.'
    );

    expect(
        $automatic
            ->title
    )->toBe(
        'Exportacao confirmada'
    );

    expect(
        data_get(
            $automatic
                ->metadata,
            'review_source'
        )
    )->toBe(
        'human'
    );

    /*
     * A observação automática nova
     * continua disponível para auditoria.
     */
    expect(
        data_get(
            $automatic
                ->metadata,
            'latest_automatic_observation.signal'
        )
    )->toBe(
        'negative'
    );

    expect(
        data_get(
            $automatic
                ->metadata,
            'latest_automatic_observation.confidence'
        )
    )->toBe(
        75
    );

    expect(
        data_get(
            $automatic
                ->metadata,
            'latest_automatic_observation.metadata.research_provider'
        )
    )->toBe(
        'test-automation'
    );

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence
            ->direct_status
    )->toBe(
        'yes'
    );

    expect(
        $intelligence
            ->direct_confirmed
    )->toBeTrue();
});

it('allows a new human review to deliberately change a confirmed evidence', function () {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => '42345678',

                'corporate_name' => 'EMPRESA NOVA REVISAO S.A.',
            ]);

    $service =
        app(
            ExportIntelligenceService::class
        );

    $first =
        $service
            ->recordEvidence(
                company: $company,

                dimension: 'direct',

                signal: 'positive',

                sourceType: 'company_site',

                evidenceText: 'Primeira revisao.',

                confidence: 100,

                confirmed: true,

                sourceUrl: 'https://empresa.test/revisao',
            );

    $second =
        $service
            ->recordEvidence(
                company: $company,

                dimension: 'direct',

                signal: 'negative',

                sourceType: 'company_site',

                evidenceText: 'Nova revisao humana alterou a conclusao.',

                confidence: 100,

                confirmed: true,

                sourceUrl: 'https://empresa.test/revisao',
            );

    expect(
        $second->id
    )->toBe(
        $first->id
    );

    expect(
        $second
            ->signal
    )->toBe(
        'negative'
    );

    expect(
        $second
            ->is_confirmed
    )->toBeTrue();

    $intelligence =
        $company
            ->exportIntelligence()
            ->firstOrFail();

    expect(
        $intelligence
            ->direct_status
    )->toBe(
        'no'
    );

    expect(
        $intelligence
            ->direct_confirmed
    )->toBeTrue();
});
