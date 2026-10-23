<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function lf42Company(string $root, string $name): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
    ]);
    $company->sdrScore()->create([
        'score' => 74, 'priority' => 'high', 'label' => 'Alta',
        'is_eligible' => true, 'is_provisional' => false, 'factors' => [],
        'version' => 'v42', 'metadata' => [], 'calculated_at' => now(),
    ]);
    return $company;
}

function lf42Task(Company $company, User $user, string $externalId, \DateTimeInterface $due): HubSpotTask
{
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v42-company-'.$externalId,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);
    $task = HubSpotTask::query()->create([
        'hubspot_id' => $externalId,
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'assigned_to' => $user->name,
        'due_at' => $due,
        'completed_at' => null,
    ]);
    $hub->tasks()->attach($task->id);
    return $task;
}

it('keeps the Leads table visible when overdue is empty but unmatched CRM deals exist', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $onlyCrm = HubSpotCompany::query()->create([
        'hubspot_id' => 'v42-only-crm', 'name' => 'V42 CRM APENAS EXTERNO',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v42-crm-deal', 'stage_label' => 'Prospects', 'is_closed' => false,
    ]);
    $onlyCrm->deals()->attach($deal->id);

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->call('applyV15TopCard', 'due')
        ->assertSet('dueActionOnly', true)
        ->assertSee('Sua fila comercial')
        ->assertSee('Empresas para trabalhar')
        ->assertSee('Nenhum lead encontrado')
        ->assertDontSee('Retornos que precisam de atenção');
});

it('uses real HubSpot tasks for overdue and today cards even with a different legacy work status', function (): void {
    Carbon::setTestNow('2026-10-23 09:00:00');
    try {
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
        $old = lf42Company('88420001', 'V42 RETORNO VENCIDO');
        $today = lf42Company('88420002', 'V42 RETORNO HOJE');
        lf42Task($old, $manager, 'v42-task-1', now()->subHour());
        lf42Task($today, $manager, 'v42-task-2', now()->addHour());

        Livewire::actingAs($manager)->test('pages::leads.index')
            ->call('applyFigmaFollowUp', 'overdue')
            ->assertSet('queueDuePeriod', 'overdue')
            ->assertSet('dueActionOnly', true)
            ->assertSet('workStatus', 'waiting')
            ->assertSet('followUp', 'overdue')
            ->assertSee('V42 RETORNO VENCIDO')
            ->assertDontSee('V42 RETORNO HOJE');

        Livewire::actingAs($manager)->test('pages::leads.index')
            ->call('applyFigmaFollowUp', 'today')
            ->assertSet('queueDuePeriod', 'today')
            ->assertSee('V42 RETORNO HOJE')
            ->assertDontSee('V42 RETORNO VENCIDO');
    } finally {
        Carbon::setTestNow();
    }
});

it('clears previous quick filters before selecting unassigned companies', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    lf42Company('88420003', 'V42 SEM RESPONSAVEL');

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->call('applyFigmaFollowUp', 'today')
        ->assertSet('dueActionOnly', true)
        ->call('applyFigmaUnassigned')
        ->assertSet('dueActionOnly', false)
        ->assertSet('queueDuePeriod', '')
        ->assertSet('owner', 'unassigned')
        ->assertSee('V42 SEM RESPONSAVEL')
        ->assertSee('Empresas para trabalhar');
});

it('finds an unlinked HubSpot company without deals by its ID and opens the identity flow', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $remote = HubSpotCompany::query()->create([
        'hubspot_id' => 'v42-remote-id-876',
        'name' => 'V42 EMPRESA SEM CNPJ NO PROSPECTOR',
    ]);

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->set('hubSpotOnly', true)
        ->set('search', 'v42-remote-id-876')
        ->assertSee('V42 EMPRESA SEM CNPJ NO PROSPECTOR')
        ->assertSee('Localizar empresa para identificar')
        ->assertSee('Identificar empresa')
        ->call('openCompanyLink', $remote->id)
        ->assertSet('linkingHubSpotCompanyId', $remote->id)
        ->assertSee('Registro atual do HubSpot')
        ->assertSee('CNPJ completo');
});

it('shows searchable unmatched HubSpot companies directly in the normal Leads page', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $remote = HubSpotCompany::query()->create([
        'hubspot_id' => 'v42-external-without-deal',
        'name' => 'V42 EMPRESA FORA DA FILA FISCAL',
    ]);

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->set('search', 'v42-external-without-deal')
        ->assertSet('hubSpotOnly', false)
        ->assertSee('Sua fila comercial')
        ->assertSee('Empresas para trabalhar')
        ->assertSee('Encontramos no HubSpot, sem CNPJ vinculado')
        ->assertSee('V42 EMPRESA FORA DA FILA FISCAL')
        ->assertSee('Identificar empresa')
        ->call('openCompanyLink', $remote->id)
        ->assertSet('linkingHubSpotCompanyId', $remote->id);
});

it('does not reveal unmatched CRM companies to sellers or allow unassigned filter elevation', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    HubSpotCompany::query()->create([
        'hubspot_id' => 'v42-private',
        'name' => 'V42 CRM SEM CNPJ PRIVADO',
    ]);
    Livewire::actingAs($seller)->test('pages::leads.index')
        ->set('hubSpotOnly', true)
        ->assertDontSee('V42 CRM SEM CNPJ PRIVADO')
        ->call('applyFigmaUnassigned')
        ->assertForbidden();
});
