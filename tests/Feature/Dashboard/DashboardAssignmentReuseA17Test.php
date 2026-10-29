<?php

use App\Models\Company;
use App\Models\User;
use App\Services\DashboardLeadMetricsV23Service;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reaproveita a mesma consulta de atribuicoes no resumo do dashboard', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);

    $assigned = Company::query()->create([
        'cnpj_root' => '97517001',
        'corporate_name' => 'Lead atribuido A17',
    ]);
    $unassigned = Company::query()->create([
        'cnpj_root' => '97517002',
        'corporate_name' => 'Lead sem responsavel A17',
    ]);
    $outside = Company::query()->create([
        'cnpj_root' => '97517003',
        'corporate_name' => 'Empresa fora do recorte A17',
    ]);

    foreach ([$assigned, $unassigned] as $company) {
        $company->sdrScore()->create([
            'score' => 82,
            'priority' => 'high',
            'label' => 'Alta',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'metadata' => [],
            'version' => 'a17',
            'calculated_at' => now(),
        ]);
        $company->hubSpotLead()->create([
            'hubspot_deal_id' => 'a17-deal-'.$company->id,
            'work_status' => 'contacting',
        ]);
    }

    $assigned->leadWorkState()->create(['assigned_user_id' => $manager->id]);
    $outside->leadWorkState()->create(['assigned_user_id' => $manager->id]);

    $assignmentReads = 0;
    DB::listen(static function (QueryExecuted $event) use (&$assignmentReads): void {
        $sql = strtolower($event->sql);
        if (preg_match('/\bfrom\s+["`]?company_lead_work_states["`]?(?=\s|$)/i', $sql) === 1) {
            $assignmentReads++;
        }
    });

    $snapshot = app(DashboardLeadMetricsV23Service::class)->snapshotWithCompanyIds();

    expect($snapshot['lead_ids'])->toEqualCanonicalizing([$assigned->id, $unassigned->id])
        ->and($snapshot['assigned_ids'])->toBe([(int) $assigned->id])
        ->and($snapshot['with_deal_ids'])->toEqualCanonicalizing([$assigned->id, $unassigned->id])
        ->and($snapshot['summary']['unassigned'])->toBe(1)
        ->and(count(array_diff($snapshot['with_deal_ids'], $snapshot['assigned_ids'])))->toBe(1)
        ->and($assignmentReads)->toBe(1);
});

it('nao consulta atribuicoes novamente na view estrategica', function (): void {
    $view = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));

    expect($view)->toContain("\$ds25Bundle['assigned_ids']")
        ->toContain("\$ds25Bundle['with_deal_ids']")
        ->not->toContain('$ds25MyLeads')
        ->not->toContain('$ds26AssignedIds')
        ->not->toContain("DB::table('company_lead_work_states')");
});
