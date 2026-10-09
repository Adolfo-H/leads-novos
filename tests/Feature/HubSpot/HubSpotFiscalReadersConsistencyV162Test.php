<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Services\DashboardLeadMetricsV23Service;
use App\Services\HubSpotWebhookAssociationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function v162CommercialCompany(string $cnpj): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $cnpj,
        'corporate_name' => 'Fiscal V162 '.$cnpj,
    ]);

    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v162',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    return $company;
}

function v162LinkedCompany(Company $company, string $hubspotId, string $source, ?DateTimeInterface $activity = null): HubSpotCompany
{
    $data = [
        'hubspot_id' => $hubspotId,
        'company_id' => $company->id,
        'match_source' => $source,
        'last_activity_at' => $activity,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if (HubSpotCompany::isUnsafeFiscalMatchSource($source)) {
        // Simula um vinculo legado anterior a protecao do Model.
        DB::table('hubspot_companies')->insert($data);

        return HubSpotCompany::query()->where('hubspot_id', $hubspotId)->firstOrFail();
    }

    return HubSpotCompany::query()->create($data);
}

it('keeps Dashboard deal, overdue and stale counts consistent with trusted fiscal links', function (): void {
    $safeCompany = v162CommercialCompany('96162001');
    $unsafeCompany = v162CommercialCompany('96162002');

    $safeCompany->hubSpotLead()->create(['last_activity_at' => now()->subDays(120)]);
    $unsafeCompany->hubSpotLead()->create(['last_activity_at' => now()->subDays(120)]);

    $safe = v162LinkedCompany($safeCompany, 'v162-safe-dashboard', 'manual_manager', now()->subDay());
    $unsafe = v162LinkedCompany($unsafeCompany, 'v162-unsafe-dashboard', 'hubspot_related_legacy', now()->subDay());

    foreach ([['mirror' => $safe, 'tag' => 'safe'], ['mirror' => $unsafe, 'tag' => 'unsafe']] as $group) {
        $deal = HubSpotDeal::query()->create([
            'hubspot_id' => 'v162-deal-'.$group['tag'],
            'last_activity_at' => now()->subDay(),
        ]);
        $group['mirror']->deals()->attach($deal->id, ['is_primary' => true]);

        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v162-task-'.$group['tag'],
            'title' => 'Ligar para cliente',
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'is_overdue' => true,
            'due_at' => now()->subDay(),
        ]);
        $group['mirror']->tasks()->attach($task->id);
    }

    $metrics = app(DashboardLeadMetricsV23Service::class);

    expect($metrics->withDealCompanyIds())->toContain($safeCompany->id)
        ->not->toContain($unsafeCompany->id);

    expect($metrics->overdueCompanyIds())->toContain($safeCompany->id)
        ->not->toContain($unsafeCompany->id);

    expect($metrics->staleCompanyIds())->toContain($unsafeCompany->id)
        ->not->toContain($safeCompany->id);
});

it('blocks inherited and propagated legacy CNPJ links in association resolution', function (): void {
    $safeCompany = v162CommercialCompany('96162003');
    $unsafeCompany = v162CommercialCompany('96162004');

    $safe = v162LinkedCompany($safeCompany, 'v162-safe-resolver', 'manual_manager');
    $unsafe = v162LinkedCompany($unsafeCompany, 'v162-unsafe-resolver', 'legacy_propagated_cnpj_marker');

    foreach ([['mirror' => $safe, 'tag' => 'safe'], ['mirror' => $unsafe, 'tag' => 'unsafe']] as $group) {
        $deal = HubSpotDeal::query()->create(['hubspot_id' => 'v162-resolver-deal-'.$group['tag']]);
        $group['mirror']->deals()->attach($deal->id, ['is_primary' => true]);

        $task = HubSpotTask::query()->create(['hubspot_id' => 'v162-resolver-task-'.$group['tag']]);
        $group['mirror']->tasks()->attach($task->id);

        $contact = HubSpotContact::query()->create(['hubspot_id' => 'v162-resolver-contact-'.$group['tag']]);
        $group['mirror']->contacts()->attach($contact->id);
    }

    // Quando nao ha correspondencia local, o resolvedor pode procurar no HubSpot.
    // Nunca fazemos requisicoes externas neste teste.
    Http::fake(['*' => Http::response(['results' => []], 200)]);

    $resolver = app(HubSpotWebhookAssociationResolver::class);

    foreach (['deal', 'task', 'contact'] as $type) {
        expect($resolver->companyIds($type, 'v162-resolver-'.$type.'-safe'))
            ->toBe([$safeCompany->id]);
        expect($resolver->companyIds($type, 'v162-resolver-'.$type.'-unsafe'))
            ->toBe([]);
    }
});
