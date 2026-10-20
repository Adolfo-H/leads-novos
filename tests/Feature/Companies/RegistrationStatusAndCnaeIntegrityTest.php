<?php

use App\Models\Company;
use App\Services\CnpjEnrichmentService;
use App\Services\IcpScoringService;

function enrichExamplePackage10(array $changes = []): Company
{
    return app(CnpjEnrichmentService::class)->enrich(
        '11.222.333/0001-81',
        array_replace([
            'razao_social' => 'AGRO INTEGRIDADE TESTE',
            'situacao_cadastral' => 2,
            'descricao_situacao_cadastral' => 'ATIVA',
            'uf' => 'MT',
            'cnae_fiscal' => 4622200,
            'cnae_fiscal_descricao' => 'Comércio atacadista de soja',
            'cnaes_secundarios' => [
                ['codigo' => 4711302, 'descricao' => 'Atividade complementar'],
            ],
        ], $changes),
    );
}

it('normaliza os codigos 2 e 02 e usa a unidade ativa no ICP', function () {
    $company = enrichExamplePackage10();
    $establishment = $company->establishments()->firstOrFail();

    expect($establishment->registration_status_code)->toBe('02')
        ->and($establishment->registration_status)->toBe('ATIVA');

    $score = app(IcpScoringService::class)->calculate($company->fresh());
    expect(data_get($score->factors, 'cnae.points'))->toBe(30)
        ->and(data_get($score->factors, 'state.points'))->toBe(15);

    // Dado historico gravado antes da normalizacao deve continuar elegivel.
    $establishment->update(['registration_status_code' => '2']);
    $historic = app(IcpScoringService::class)->calculate($company->fresh());
    expect(data_get($historic->factors, 'cnae.points'))->toBe(30)
        ->and(data_get($historic->factors, 'state.points'))->toBe(15);

    enrichExamplePackage10(['situacao_cadastral' => '02']);
    expect($establishment->fresh()->registration_status_code)->toBe('02');
});

it('nao apaga CNAEs conhecidos quando a resposta nao informa atividades', function () {
    $company = enrichExamplePackage10();

    app(CnpjEnrichmentService::class)->enrich('11.222.333/0001-81', [
        'razao_social' => 'AGRO INTEGRIDADE TESTE ATUALIZADA',
        'situacao_cadastral' => 2,
    ]);

    $matrix = $company->establishments()->firstOrFail();
    $codes = $matrix->cnaes()->pluck('code')->all();
    expect($codes)->toHaveCount(2)
        ->toContain('4622200', '4711302');
});

it('preserva CNAEs secundarios quando a fonte informa somente o principal', function () {
    $company = enrichExamplePackage10();

    app(CnpjEnrichmentService::class)->enrich('11.222.333/0001-81', [
        'razao_social' => 'AGRO INTEGRIDADE TESTE',
        'cnae_fiscal' => 115600,
    ]);

    $matrix = $company->establishments()->firstOrFail();
    $codes = $matrix->cnaes()->pluck('code')->all();
    expect($codes)->toHaveCount(3)
        ->toContain('4622200', '4711302', '0115600');
    expect($matrix->cnaes()->wherePivot('is_primary', true)->count())->toBe(1);
});

it('permite substituir CNAEs quando o retrato da fonte e completo', function () {
    $company = enrichExamplePackage10();

    app(CnpjEnrichmentService::class)->enrich('11.222.333/0001-81', [
        'razao_social' => 'AGRO INTEGRIDADE TESTE',
        'cnae_fiscal' => 115600,
        'cnaes_secundarios' => [],
    ]);

    $matrix = $company->establishments()->firstOrFail();
    expect($matrix->cnaes()->pluck('code')->all())->toBe(['0115600']);
});

it('nao elimina o CNAE anterior quando o payload esta malformado', function () {
    $company = enrichExamplePackage10();

    app(CnpjEnrichmentService::class)->enrich('11.222.333/0001-81', [
        'razao_social' => 'AGRO INTEGRIDADE TESTE',
        'cnae_fiscal' => null,
        'cnaes_secundarios' => [['codigo' => 'invalido']],
    ]);

    $matrix = $company->establishments()->firstOrFail();
    expect($matrix->cnaes()->count())->toBe(2);
});
