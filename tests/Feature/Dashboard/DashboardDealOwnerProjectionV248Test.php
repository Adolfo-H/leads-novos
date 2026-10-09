<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\DashboardOwnedDealsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('busca o owner JSON sem transferir todas as colunas do negocio', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor Projecao V248',
        'hubspot_owner_id' => '248-owner-id',
    ]);

    HubSpotDeal::query()->create([
        'hubspot_id' => 'v248-deal-owned',
        'owner_name' => 'Nome diferente',
        'stage_label' => 'V248 Etapa',
        'raw_properties' => [
            'hubspot_owner_id' => '248-owner-id',
            'texto_nao_utilizado' => str_repeat('X', 5000),
        ],
    ]);

    $selects = [];
    DB::listen(static function (QueryExecuted $event) use (&$selects): void {
        $sql = strtolower($event->sql);
        if (str_contains($sql, 'from "hubspot_deals"') && ! str_contains($sql, 'join "hubspot_company_deal"')) {
            $selects[] = $sql;
        }
    });

    $data = app(DashboardOwnedDealsV23Service::class)->forUser((int) $seller->id);

    expect($data['total'])->toBe(1)
        ->and($data['stages'][0]['label'])->toBe('V248 Etapa')
        ->and($selects)->toHaveCount(1)
        ->and($selects[0])->toContain('remote_owner_id')
        ->not->toContain('select * from "hubspot_deals"');
});

it('nao reassume pelo snapshot um negocio com outro owner HubSpot', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor V248',
        'hubspot_owner_id' => '248-my-owner',
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '99248001',
        'corporate_name' => 'Empresa V248 Outro Owner',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $seller->id]);
    $company->hubSpotLead()->create([
        'hubspot_deal_id' => 'v248-other-owner',
        'work_status' => 'contacting',
    ]);

    HubSpotDeal::query()->create([
        'hubspot_id' => 'v248-other-owner',
        'owner_name' => 'Vendedor V248',
        'raw_properties' => ['hubspot_owner_id' => '248-foreign-owner'],
    ]);

    expect(app(DashboardOwnedDealsV23Service::class)->forUser((int) $seller->id)['total'])->toBe(0);
});

it('preserva fallback pelo nome e por carteira local sem owner remoto', function (): void {
    $seller = User::factory()->create([
        'name' => 'Vendedor Nome V248',
        'hubspot_owner_id' => null,
    ]);

    HubSpotDeal::query()->create([
        'hubspot_id' => 'v248-name-fallback',
        'owner_name' => '  VENDEDOR NOME V248  ',
        'stage_label' => 'V248 Por Nome',
        'raw_properties' => ['hubspot_owner_id' => 'owner-sem-mapeamento'],
    ]);

    $company = Company::query()->create([
        'cnpj_root' => '99248002',
        'corporate_name' => 'Empresa V248 Carteira',
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $seller->id]);

    $linked = HubSpotCompany::query()->create([
        'hubspot_id' => 'v248-company-linked',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);

    $local = HubSpotDeal::query()->create([
        'hubspot_id' => 'v248-local-fallback',
        'stage_label' => 'V248 Carteira',
        'owner_name' => null,
    ]);
    $local->companies()->attach($linked->id, ['is_primary' => true]);

    $service = app(DashboardOwnedDealsV23Service::class);

    expect($service->forUser((int) $seller->id)['total'])->toBe(2)
        ->and(collect($service->forUser((int) $seller->id)['stages'])->pluck('label')->all())
        ->toContain('V248 Por Nome', 'V248 Carteira');
});
