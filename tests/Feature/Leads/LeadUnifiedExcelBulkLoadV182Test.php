<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Services\LeadExportService;
use App\Services\LeadUnifiedExcelV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

it('loads all company and CRM-only relations in batches rather than one query per row', function (): void {
    $companies = Company::factory()->count(30)->create();

    $crmCompanies = collect();
    for ($i = 1; $i <= 30; $i++) {
        $crmCompanies->push(HubSpotCompany::query()->create([
            'hubspot_id' => 'v182-crm-'.$i,
            'name' => 'Empresa CRM '.$i,
        ]));
    }

    $service = app(LeadUnifiedExcelV23Service::class);
    $measuring = false;
    $queryCount = 0;

    DB::listen(static function (QueryExecuted $event) use (&$measuring, &$queryCount): void {
        if ($measuring) {
            $queryCount++;
        }
    });

    $countQueries = static function (Collection $local, Collection $crm) use (
        $service,
        &$measuring,
        &$queryCount
    ): int {
        $queryCount = 0;
        $measuring = true;

        try {
            $response = $service->excel($local, $crm);
        } finally {
            $measuring = false;
        }

        expect($response)->toBeInstanceOf(StreamedResponse::class);

        return $queryCount;
    };

    $local10 = Company::query()->whereKey($companies->take(10)->modelKeys())->get();
    $smallLocal = $countQueries(collect($local10->all()), collect());

    $local30 = Company::query()->whereKey($companies->modelKeys())->get();
    $largeLocal = $countQueries(collect($local30->all()), collect());

    $crm10 = HubSpotCompany::query()->whereKey($crmCompanies->take(10)->pluck('id')->all())->get();
    $smallCrm = $countQueries(collect(), collect($crm10->all()));

    $crm30 = HubSpotCompany::query()->whereKey($crmCompanies->pluck('id')->all())->get();
    $largeCrm = $countQueries(collect(), collect($crm30->all()));

    expect($smallLocal)->toBeGreaterThan(0)
        ->and($largeLocal)->toBeLessThanOrEqual($smallLocal + 2)
        ->and($largeLocal)->toBeLessThan(30)
        ->and($smallCrm)->toBeGreaterThan(0)
        ->and($largeCrm)->toBeLessThanOrEqual($smallCrm + 1)
        ->and($largeCrm)->toBeLessThanOrEqual(5);
});

it('preserves standard lead column values after eager loading', function (): void {
    $company = Company::factory()->create([
        'corporate_name' => 'Cliente Exportação V182',
    ]);

    $expected = app(LeadExportService::class)
        ->rows(collect([$company->fresh()]))[0];

    $selected = $company->fresh();

    app(LeadUnifiedExcelV23Service::class)
        ->excel(collect([$selected]), collect());

    $actual = app(LeadExportService::class)
        ->rows(collect([$selected]))[0];

    expect($actual)->toEqual($expected)
        ->and($actual['Empresa'])->toBe('Cliente Exportação V182')
        ->and($selected->relationLoaded('establishments'))->toBeTrue()
        ->and($selected->relationLoaded('sdrScore'))->toBeTrue()
        ->and($selected->relationLoaded('hubSpotCompanies'))->toBeTrue();
});

it('preserves CRM-only deal stage, contact and task data after eager loading', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'v182-verified-crm',
        'name' => 'Cliente CRM V182',
        'phone' => '(45) 3000-1111',
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v182-deal',
        'stage_label' => 'Em negociação',
    ]);
    $company->deals()->attach($deal->id);

    $contact = HubSpotContact::query()->create([
        'hubspot_id' => 'v182-contact',
        'email' => 'contato@exemplo.com.br',
        'phone' => '(45) 99999-2222',
    ]);
    $company->contacts()->attach($contact->id);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v182-task',
        'is_open' => true,
        'due_at' => now()->addDays(2),
    ]);
    $company->tasks()->attach($task->id);

    $service = app(LeadUnifiedExcelV23Service::class);
    $expected = $service->hubSpotRow($company->fresh());
    $selected = $company->fresh();

    $service->excel(collect(), collect([$selected]));
    $actual = $service->hubSpotRow($selected);

    expect($actual)->toEqual($expected)
        ->and($actual['CNPJ'])->toBe('')
        ->and($actual['Etapas HubSpot'])->toBe('Em negociação')
        ->and($actual['E-mails'])->toContain('contato@exemplo.com.br')
        ->and($actual['Telefones'])->toContain('(45) 99999-2222')
        ->and($actual['Próxima ação'])->not->toBe('')
        ->and($selected->relationLoaded('deals'))->toBeTrue()
        ->and($selected->relationLoaded('contacts'))->toBeTrue()
        ->and($selected->relationLoaded('tasks'))->toBeTrue();
});
