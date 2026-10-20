<?php

use App\Models\Company;
use App\Models\CompanyIcpScore;
use App\Models\CompanySdrScore;
use App\Models\Establishment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function package11CreateIcpCompany(): Company
{
    return Company::factory()->create([
        'corporate_name' => 'Empresa de teste ICP pacote 11',
    ]);
}

function package11CreateIcpScore(
    Company $company,
    string $version,
    int $score = 70,
): CompanyIcpScore {
    $calculatedAt = now()->subDays(10);

    return CompanyIcpScore::query()->create([
        'company_id' => $company->id,
        'score' => $score,
        'grade' => 'B',
        'label' => 'Boa aderência',
        'version' => $version,
        'factors' => [
            'cnae' => [
                'points' => 20,
                'reason' => 'Fator histórico de teste.',
            ],
        ],
        'calculated_at' => $calculatedAt,
        'created_at' => $calculatedAt,
        'updated_at' => $calculatedAt,
    ]);
}

it('mantém ICP e SDR sem alterações quando a execução usa o padrão dry-run', function () {
    Http::fake();

    $company = package11CreateIcpCompany();
    $icp = package11CreateIcpScore($company, 'v2');
    $sdr = CompanySdrScore::query()->create([
        'company_id' => $company->id,
        'score' => 42,
        'priority' => 'medium',
        'label' => 'Prioridade média',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => ['legacy' => true],
        'version' => 'v2',
        'metadata' => ['fixture' => true],
        'calculated_at' => now()->subDays(5),
    ]);

    $icpBefore = $icp->fresh()->toArray();
    $sdrBefore = $sdr->fresh()->toArray();

    $this->artisan('icp:recalculate-affected')
        ->expectsOutputToContain('Prévia somente leitura')
        ->expectsOutputToContain('Nenhum score ICP ou SDR foi gravado.')
        ->assertExitCode(0);

    expect($icp->fresh()->toArray())->toEqual($icpBefore)
        ->and($sdr->fresh()->toArray())->toEqual($sdrBefore);

    Http::assertNothingSent();
});

it('aplica somente o limite selecionado e não chama integrações externas', function () {
    Http::fake();

    $first = package11CreateIcpCompany();
    $firstScore = package11CreateIcpScore($first, 'v2');
    $second = package11CreateIcpCompany();
    $secondScore = package11CreateIcpScore($second, 'v1');

    $this->artisan('icp:recalculate-affected', [
        '--apply' => true,
        '--limit' => '1',
        '--chunk' => '1',
        '--reason' => 'Amostra autorizada de teste',
    ])
        ->expectsOutputToContain('Empresas recalculadas: 1')
        ->assertExitCode(0);

    expect($firstScore->fresh()->version)->toBe('v3')
        ->and($firstScore->fresh()->factors)
        ->toHaveKey('cnae')
        ->and(data_get($firstScore->fresh()->factors, 'cnae.reason'))
        ->toBeString()
        ->and($secondScore->fresh()->version)->toBe('v1');

    Http::assertNothingSent();
});

it('não seleciona um score v3 atualizado nem recalcula novamente uma versão atual', function () {
    $company = package11CreateIcpCompany();
    $score = package11CreateIcpScore($company, 'v3', 0);
    $score->update([
        'calculated_at' => now(),
    ]);

    $before = $score->fresh()->toArray();

    $this->artisan('icp:recalculate-affected')
        ->expectsOutputToContain('Nenhum score ICP elegível')
        ->assertExitCode(0);

    expect($score->fresh()->toArray())->toEqual($before);
});

it('audita versões e situações cadastrais somente com totais agregados', function () {
    $company = package11CreateIcpCompany();
    package11CreateIcpScore($company, 'v3');

    foreach (['2', '02', '03'] as $index => $statusCode) {
        Establishment::query()->create([
            'company_id' => $company->id,
            'cnpj' => '12345678000'.($index + 1).'90',
            'order_number' => str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'check_digits' => '90',
            'type' => $index === 0 ? 'matrix' : 'branch',
            'registration_status_code' => $statusCode,
        ]);
    }

    $exitCode = Artisan::call('icp:audit-scores', [
        '--format' => 'json',
    ]);
    $output = Artisan::output();
    $summary = json_decode(
        $output,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($exitCode)->toBe(0)
        ->and($summary['icp_version_v3'])->toBe(1)
        ->and($summary['establishment_code_2'])->toBe(1)
        ->and($summary['establishment_code_02'])->toBe(1)
        ->and($summary['establishment_code_other'])->toBe(1)
        ->and($output)->not->toContain($company->corporate_name);
});
