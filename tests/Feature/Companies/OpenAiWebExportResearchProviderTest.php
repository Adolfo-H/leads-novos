<?php

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Services\Providers\OpenAiWebExportResearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function openAiProviderCompany(): Company
{
    return Company::query()->create([
        'cnpj_root' => '55443322',

        'corporate_name' => 'Empresa Provider Teste',
    ]);
}

it('accepts a finding backed by a real response citation', function () {
    config([
        'services.openai.api_key' => 'fake-api-key',

        'services.openai.base_url' => 'https://api.openai.com/v1',

        'services.openai.export_research_model' => 'fake-model',
    ]);

    $resultJson =
        json_encode(
            [
                'findings' => [
                    [
                        'dimension' => 'direct',

                        'signal' => 'positive',

                        'confidence' => 91,

                        'source_type' => 'government',

                        'source_name' => 'Fonte oficial',

                        'source_url' => 'https://example.com/export',

                        'title' => 'Registro exportador',

                        'evidence_text' => 'A fonte indica '
                            .'exportação própria.',

                        'matched_query' => 'empresa exportação',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            [
                'id' => 'resp_fake',

                'output' => [
                    [
                        'type' => 'message',

                        'content' => [
                            [
                                'type' => 'output_text',

                                'text' => $resultJson,

                                'annotations' => [
                                    [
                                        'type' => 'url_citation',

                                        'url' => 'https://example.com/export',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            200
        ),
    ]);

    $result = app(
        OpenAiWebExportResearchProvider::class
    )->research(
        openAiProviderCompany(),
        [
            'empresa exportação',
        ],
    );

    expect($result)
        ->toHaveCount(1);

    expect(
        $result[0]['dimension']
    )->toBe('direct');

    expect(
        $result[0]['confidence']
    )->toBe(91);

    expect(
        $result[0]['source_url']
    )->toBe(
        'https://example.com/export'
    );

    expect(
        data_get(
            $result[0],
            'metadata.source_validated'
        )
    )->toBeTrue();

    Http::assertSent(
        fn ($request): bool => $request->url()
                ===
                'https://api.openai.com/v1/responses'
            && $request[
                'tools'
            ][0]['type']
                ===
                'web_search'
    );
});

it('rejects a source that was not cited by web search', function () {
    config([
        'services.openai.api_key' => 'fake-api-key',

        'services.openai.base_url' => 'https://api.openai.com/v1',
    ]);

    $resultJson =
        json_encode(
            [
                'findings' => [
                    [
                        'dimension' => 'indirect',

                        'signal' => 'positive',

                        'confidence' => 99,

                        'source_type' => 'news',

                        'source_name' => 'Fonte qualquer',

                        'source_url' => 'https://fake.example',

                        'title' => 'Fonte não citada',

                        'evidence_text' => 'Texto qualquer.',

                        'matched_query' => 'consulta',
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

    Http::fake([
        '*' => Http::response(
            [
                'id' => 'resp_fake',

                'output' => [
                    [
                        'type' => 'message',

                        'content' => [
                            [
                                'type' => 'output_text',

                                'text' => $resultJson,

                                'annotations' => [
                                    [
                                        'type' => 'url_citation',

                                        'url' => 'https://real.example',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            200
        ),
    ]);

    $result = app(
        OpenAiWebExportResearchProvider::class
    )->research(
        openAiProviderCompany(),
        [
            'consulta',
        ],
    );

    expect($result)
        ->toBe([]);
});

it('binds the research contract to the OpenAI provider', function () {
    expect(
        app(
            ExportResearchProvider::class
        )
    )->toBeInstanceOf(
        OpenAiWebExportResearchProvider::class
    );
});
