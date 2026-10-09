<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v16Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function v16EligibleCompany(string $root, string $name): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
        'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Prioridade alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v16-test',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    return $company;
}

it('shows the colorful HubSpot pipeline without the secondary shortcuts or score legend', function () {
    Livewire::actingAs(v16Manager())->test('pages::leads.index')
        ->assertSee('Pipeline HubSpot')
        ->assertSee('Situação CRM')
        ->assertDontSee('Mais atalhos')
        ->assertDontSee('Score 50+ = bom potencial')
        ->assertSee('Exportar Excel');
});

it('only counts truly open personal HubSpot tasks due through today and filters by the same condition', function () {
    Carbon::setTestNow('2026-10-08 10:00:00');

    try {
        $me = v16Manager();
        $other = v16Manager();

        $cases = [
            ['98220001', 'V16 Tarefa Minha Aberta', $me->name, true, now()->subHour(), null],
            ['98220002', 'V16 Tarefa Outro Usuario', $other->name, true, now()->subDay(), null],
            ['98220003', 'V16 Tarefa Concluida', $me->name, false, now()->subHour(), now()],
            ['98220004', 'V16 Tarefa Amanha', $me->name, true, now()->addDay(), null],
        ];

        foreach ($cases as [$root, $name, $owner, $isOpen, $due, $completed]) {
            $company = v16EligibleCompany($root, $name);
            $hub = HubSpotCompany::query()->create([
                'hubspot_id' => 'v16-hub-'.$root,
                'company_id' => $company->id,
                'match_source' => 'manual_verified',
            ]);
            $task = HubSpotTask::query()->create([
                'hubspot_id' => 'v16-task-'.$root,
                'title' => 'Atividade de teste',
                'assigned_to' => $owner,
                'is_open' => $isOpen,
                'due_at' => $due,
                'completed_at' => $completed,
            ]);
            $hub->tasks()->attach($task->id);
        }

        $page = Livewire::actingAs($me)->test('pages::leads.index');
        expect($page->get('v15DueCount'))->toBe(1);
        $page->call('applyV15TopCard', 'due')
            ->assertSee('V16 Tarefa Minha Aberta')
            ->assertDontSee('V16 Tarefa Outro Usuario')
            ->assertDontSee('V16 Tarefa Concluida')
            ->assertDontSee('V16 Tarefa Amanha');
    } finally {
        Carbon::setTestNow();
    }
});

it('keeps the real dossie and detail controls without the old three-dots menu', function () {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $component = file_get_contents(resource_path('views/partials/leads-action-center-v15.blade.php'));

    expect($view)
        ->toContain('lv23-actions')
        ->toContain('HubSpot ↗')
        ->not->toContain('x-show="detailsOpen"')
        ->toContain('partials.leads-crm-stage-strip')
        ->toContain('wire:click="exportExcel"')
        ->toContain('assignOwner(')
        ->not->toContain('<details class="lv13-more">')
        ->and($component)->not->toContain('Mais atalhos');
});
