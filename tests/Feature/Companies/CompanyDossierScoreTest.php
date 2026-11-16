<?php

use App\Models\Company;
use App\Models\User;
use App\Services\SdrScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function a13Company(): Company
{
    $company = Company::query()->create([
        'cnpj_root' => '12ABC345',
        'corporate_name' => 'Empresa A13 - Dossiê SDR',
    ]);
    $company->icpScore()->create([
        'score' => 100,
        'grade' => 'A',
        'label' => 'Perfil A13',
        'version' => 'v3',
        'factors' => [],
        'calculated_at' => now(),
    ]);

    return $company;
}

it('cria score ao abrir dossie quando ainda nao existe', function (): void {
    $company = a13Company();
    $service = app(SdrScoringService::class);
    expect($company->sdrScore()->exists())->toBeFalse();

    $score = $service->currentForDossier($company);

    expect($score->exists)->toBeTrue()
        ->and($company->relationLoaded('sdrScore'))->toBeTrue()
        ->and($company->sdrScore->id)->toBe($score->id)
        ->and($company->sdrScore()->count())->toBe(1);
});

it('nao regrava score recente sem mudanca de fontes', function (): void {
    $company = a13Company();
    $service = app(SdrScoringService::class);
    $first = $service->currentForDossier($company);
    $writes = [];

    DB::listen(static function ($event) use (&$writes): void {
        if (preg_match('/\b(insert|update|delete)\b/i', $event->sql) === 1
            && str_contains(strtolower($event->sql), 'company_sdr_scores')) {
            $writes[] = $event->sql;
        }
    });

    $second = $service->currentForDossier($company->fresh());

    expect($second->id)->toBe($first->id)
        ->and($writes)->toBe([]);
});

it('recalcula se os dados ICP mudarem apos o ultimo score', function (): void {
    $company = a13Company();
    $service = app(SdrScoringService::class);
    $original = $service->currentForDossier($company);

    $company->icpScore()->update([
        'score' => 20,
        'updated_at' => now()->addMinutes(5),
    ]);

    $next = $service->currentForDossier($company->fresh());

    expect($next->id)->toBe($original->id)
        ->and($next->score)->toBeLessThan($original->score);
});

it('recalcula scores antigos para atualizar classificacoes dependentes de tempo', function (): void {
    $company = a13Company();
    $service = app(SdrScoringService::class);
    $score = $service->currentForDossier($company);
    $company->sdrScore()->update(['calculated_at' => now()->subDays(2)]);

    $refreshed = $service->currentForDossier($company->fresh());

    expect($refreshed->id)->toBe($score->id)
        ->and($refreshed->calculated_at->greaterThan(now()->subMinute()))->toBeTrue();
});

it('a rota do dossie nao grava novamente o score que ja esta atualizado', function (): void {
    $company = a13Company();
    $service = app(SdrScoringService::class);
    $score = $service->currentForDossier($company);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $writes = [];

    DB::listen(static function ($event) use (&$writes): void {
        if (preg_match('/\b(insert|update|delete)\b/i', $event->sql) === 1
            && str_contains(strtolower($event->sql), 'company_sdr_scores')) {
            $writes[] = $event->sql;
        }
    });

    $this->actingAs($user)->get(route('companies.show', $company))->assertOk();

    expect($company->sdrScore()->count())->toBe(1)
        ->and($company->sdrScore()->firstOrFail()->id)->toBe($score->id)
        ->and($writes)->toBe([]);
});
