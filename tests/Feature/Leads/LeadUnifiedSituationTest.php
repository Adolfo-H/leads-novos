<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v15User(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function v15Company(string $root, string $name, bool $eligible = true): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
        'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => $eligible ? 76 : 0,
        'priority' => $eligible ? 'high' : 'blocked',
        'label' => $eligible ? 'Prioridade alta' : 'Não priorizar',
        'is_eligible' => $eligible,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v15-test',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    return $company;
}

/** @param array<string, mixed> $extra */
function v15Lead(Company $company, array $extra = []): CompanyHubSpotLead
{
    return CompanyHubSpotLead::query()->create(array_merge([
        'company_id' => $company->id,
        'hubspot_company_id' => 'v15-'.$company->cnpj_root,
        'work_status' => 'contacting',
        'open_task_count' => 0,
        'synced_at' => now(),
        'metadata' => [],
    ], $extra));
}

it('shows compact cards, unified CRM and restored colored HubSpot stages', function () {
    Livewire::actingAs(v15User())
        ->test('pages::leads.index')
        ->assertSee('Atrasados / Vencem hoje')
        ->assertSee('Minha fila')
        ->assertSee('Novos para prospectar')
        ->assertSee('Situação CRM')
        ->assertSee('Pipeline HubSpot')
        ->assertSee('Exportar Excel');
});

it('classifies new only when there is no HubSpot presence or commercial action', function () {
    $new = v15Company('98210001', 'Empresa V15 Nova');
    $linked = v15Company('98210002', 'Empresa V15 No HubSpot');
    v15Lead($linked, ['work_status' => 'new']);

    $page = Livewire::actingAs(v15User())->test('pages::leads.index');
    expect($page->get('v15NewCount'))->toBe(1);
    $page->set('crmSituation', 'new')
        ->assertSee($new->corporate_name)
        ->assertDontSee($linked->corporate_name);
});

it('uses actual activity in the last 30 days for known leads', function () {
    $recent = v15Company('98210003', 'Empresa V15 Contato Recente');
    v15Lead($recent, ['last_activity_at' => now()->subDays(10)]);
    $stale = v15Company('98210004', 'Empresa V15 Contato Antigo');
    v15Lead($stale, ['last_activity_at' => now()->subDays(100)]);

    Livewire::actingAs(v15User())->test('pages::leads.index')
        ->set('crmSituation', 'known')
        ->assertSee('Empresa V15 Contato Recente')
        ->assertDontSee('Empresa V15 Contato Antigo');
});

it('recognizes an internal client even when the SDR score is blocked', function () {
    $client = v15Company('98210005', 'Empresa V15 Cliente Interno', false);
    $client->crmCheck()->create([
        'provider' => 'hubspot',
        'status' => 'client',
        'metadata' => [],
        'checked_at' => now(),
    ]);
    v15Company('98210006', 'Empresa V15 Não Cliente');

    Livewire::actingAs(v15User())->test('pages::leads.index')
        ->set('crmSituation', 'client')
        ->assertSee('Empresa V15 Cliente Interno')
        ->assertDontSee('Empresa V15 Não Cliente');
});

it('also recognizes HubSpot won deals as clients', function () {
    $company = v15Company('98210007', 'Empresa V15 Cliente HubSpot', false);
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v15-hub-won',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v15-deal-won',
        'stage_label' => 'Negócio fechado',
        'is_closed' => true,
        'is_closed_won' => true,
    ]);
    $deal->companies()->attach($hub->id, ['is_primary' => true]);

    Livewire::actingAs(v15User())->test('pages::leads.index')
        ->set('crmSituation', 'client')
        ->assertSee('Empresa V15 Cliente HubSpot');
});

it('awaiting return requires an open task, not just a waiting label', function () {
    $pending = v15Company('98210008', 'Empresa V15 Tarefa Aberta');
    v15Lead($pending, ['work_status' => 'waiting', 'open_task_count' => 1]);
    $closed = v15Company('98210009', 'Empresa V15 Tarefa Concluída');
    v15Lead($closed, ['work_status' => 'waiting', 'open_task_count' => 0]);

    Livewire::actingAs(v15User())->test('pages::leads.index')
        ->set('crmSituation', 'waiting')
        ->assertSee('Empresa V15 Tarefa Aberta')
        ->assertDontSee('Empresa V15 Tarefa Concluída');
});

it('reprospecção requires an old dated activity without more recent activity', function () {
    $old = v15Company('98210010', 'Empresa V15 Sem Contato 100 Dias');
    v15Lead($old, ['last_activity_at' => now()->subDays(100)]);
    $recent = v15Company('98210011', 'Empresa V15 Contato 20 Dias');
    v15Lead($recent, ['last_activity_at' => now()->subDays(20)]);

    Livewire::actingAs(v15User())->test('pages::leads.index')
        ->set('crmSituation', 'reprospecting')
        ->assertSee('Empresa V15 Sem Contato 100 Dias')
        ->assertDontSee('Empresa V15 Contato 20 Dias');
});

it('does not count legacy task snapshots without an open HubSpot task assigned to me', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');
    try {
        $manager = v15User();
        $company = v15Company('98210012', 'Empresa V15 Snapshot Antigo');
        v15Lead($company, [
            'work_status' => 'waiting',
            'open_task_count' => 1,
            'last_task_due_at' => now()->subDay(),
        ]);
        $page = Livewire::actingAs($manager)->test('pages::leads.index');
        expect($page->get('v15DueCount'))->toBe(0);
        $page->call('applyV15TopCard', 'due')
            ->assertSet('dueActionOnly', true)
            ->assertDontSee('Empresa V15 Snapshot Antigo');
    } finally {
        Carbon::setTestNow();
    }
});

it('reads open tasks due today directly from the trusted HubSpot mirror', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');
    try {
        $manager = v15User();
        $company = v15Company('98210018', 'Empresa V15 Tarefa do Mirror');
        $hub = HubSpotCompany::query()->create([
            'hubspot_id' => 'v15-hub-task',
            'company_id' => $company->id,
            'match_source' => 'manual_verified',
        ]);
        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v15-open-task',
            'title' => 'Retornar hoje',
            'assigned_to' => $manager->name,
            'is_open' => true,
            'due_at' => now()->addHours(2),
        ]);
        $hub->tasks()->attach($task->id);

        $page = Livewire::actingAs($manager)->test('pages::leads.index');
        expect($page->get('v15DueCount'))->toBe(1);
        $page->call('applyV15TopCard', 'due')
            ->assertSee('Empresa V15 Tarefa do Mirror');
    } finally {
        Carbon::setTestNow();
    }
});

it('opens my queue by the assigned user and preserves current seller authorization', function () {
    $me = v15User();
    $other = v15User();
    $mine = v15Company('98210016', 'Empresa V15 Minha Carteira');
    $mine->leadWorkState()->create(['assigned_user_id' => $me->id]);
    $theirs = v15Company('98210017', 'Empresa V15 Outra Carteira');
    $theirs->leadWorkState()->create(['assigned_user_id' => $other->id]);

    Livewire::actingAs($me)->test('pages::leads.index')
        ->call('applyV15TopCard', 'mine')
        ->assertSet('owner', 'mine')
        ->assertSee('Empresa V15 Minha Carteira')
        ->assertDontSee('Empresa V15 Outra Carteira');
});
