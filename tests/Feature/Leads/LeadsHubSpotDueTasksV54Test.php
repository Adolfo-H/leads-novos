<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\LeadDueTaskExcelV54Service;
use App\Services\LeadDueTaskQueueV54Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v54Owner(): User
{
    $manager = User::factory()->create([
        'name' => 'Responsavel V54',
        'email_verified_at' => now(),
        'hubspot_owner_id' => '1226951898',
    ]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function v54Task(int $number, string $owner, \DateTimeInterface $due, bool $withFiscal = false): HubSpotTask
{
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v54-task-'.$number,
        'title' => 'V54 atividade numero '.$number,
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'assigned_to' => $owner,
        'due_at' => $due,
        'raw_properties' => ['hubspot_owner_id' => $owner === 'Responsavel V54' ? '1226951898' : '99999'],
    ]);

    if ($withFiscal) {
        $company = Company::query()->create([
            'cnpj_root' => '9954'.str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'corporate_name' => 'Empresa V54 '.$number,
        ]);
        $hub = HubSpotCompany::query()->create([
            'hubspot_id' => 'v54-company-'.$number,
            'company_id' => $company->id,
            'match_source' => 'manual_verified',
        ]);
        $hub->tasks()->attach($task->id);
    }

    return $task;
}

it('counts 17 individual tasks, including tasks without fiscal links, and moves them from today to overdue at the hour', function (): void {
    Carbon::setTestNow('2026-10-26 07:30:00');
    try {
        $user = v54Owner();
        // São 17 tarefas, mas somente 12 possuem uma Company fiscal.
        for ($i = 1; $i <= 17; $i++) {
            v54Task($i, $user->name, today()->setTime($i <= 13 ? 8 : 9, 0), $i <= 12);
        }
        v54Task(18, 'Outro vendedor', today()->setTime(8, 0));
        $completed = v54Task(19, $user->name, today()->setTime(8, 0));
        $completed->update(['status' => 'COMPLETED']);

        $service = app(LeadDueTaskQueueV54Service::class);
        expect($service->counts($user))->toBe(['total' => 17, 'overdue' => 0, 'today' => 17]);

        Carbon::setTestNow('2026-10-26 08:30:00');
        expect($service->counts($user))->toBe(['total' => 17, 'overdue' => 13, 'today' => 4]);

        Carbon::setTestNow('2026-10-26 09:00:00');
        expect($service->counts($user))->toBe(['total' => 17, 'overdue' => 17, 'today' => 0]);

        Carbon::setTestNow('2026-10-26 09:30:00');
        expect($service->counts($user))->toBe(['total' => 17, 'overdue' => 17, 'today' => 0]);

        $page = Livewire::actingAs($user)->test('pages::leads.index');
        expect($page->get('v15DueCount'))->toBe(17);
        expect($page->get('v17DueBreakdown'))->toBe(['overdue' => 17, 'today' => 0]);

        $page->call('applyFigmaFollowUp', 'overdue')
            ->assertSee('V54 atividade numero 17')
            ->assertSee('Empresa V54 1')
            ->assertSee('Tarefa sem empresa vinculada')
            ->assertDontSee('V54 atividade numero 18');
        expect($page->get('dueHubSpotTasks')->total())->toBe(17);
        $page->call('applyFigmaFollowUp', 'today')
            ->assertSee('Nenhuma tarefa aberta nesse período');
        expect($page->get('dueHubSpotTasks')->total())->toBe(0);
    } finally {
        Carbon::setTestNow();
    }
});

it('never leaks other owners, completed tasks, or tasks assigned by a mismatched remote owner ID', function (): void {
    Carbon::setTestNow('2026-10-26 11:00:00');
    try {
        $user = v54Owner();
        v54Task(101, $user->name, today()->setTime(8, 0));
        v54Task(102, 'Outro vendedor', today()->setTime(8, 0));
        $falseOwner = v54Task(103, $user->name, today()->setTime(8, 0));
        $falseOwner->update(['raw_properties' => ['hubspot_owner_id' => '99999']]);
        $closed = v54Task(104, $user->name, today()->setTime(8, 0));
        $closed->update(['completed_at' => now()]);
        v54Task(105, $user->name, today()->addDay()->setTime(8, 0));

        expect(app(LeadDueTaskQueueV54Service::class)->counts($user))
            ->toBe(['total' => 1, 'overdue' => 1, 'today' => 0]);

        Livewire::actingAs($user)->test('pages::leads.index')
            ->call('applyV15TopCard', 'due')
            ->assertSee('V54 atividade numero 101')
            ->assertDontSee('V54 atividade numero 102')
            ->assertDontSee('V54 atividade numero 103')
            ->assertDontSee('V54 atividade numero 104')
            ->assertDontSee('V54 atividade numero 105');
    } finally {
        Carbon::setTestNow();
    }
});

it('retains 21 Excel columns, one task per row and neutralizes formula-looking names', function (): void {
    Carbon::setTestNow('2026-10-26 10:00:00');
    try {
        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v54-excel-safe',
            'title' => '=2+2',
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => now()->subHour(),
        ]);
        $row = app(LeadDueTaskExcelV54Service::class)->taskRow($task);
        expect($row['Status comercial'])->toBe('Atrasada')
            ->and($row['Próxima ação'])->toContain('=2+2')
            ->and($row['Origem'])->toContain('v54-excel-safe');
        expect(app(LeadDueTaskExcelV54Service::class)->excel(collect([$task]))
            ->headers->get('Content-Type'))->toContain('spreadsheetml');
    } finally {
        Carbon::setTestNow();
    }
});

it('limits the Excel download to selected tasks authorized for the current owner', function (): void {
    Carbon::setTestNow('2026-10-26 10:30:00');
    try {
        $user = v54Owner();
        $mine = v54Task(201, $user->name, today()->setTime(9, 0));
        $alsoMine = v54Task(202, $user->name, today()->setTime(8, 0));
        $other = v54Task(203, 'Outro vendedor', today()->setTime(9, 0));

        $page = Livewire::actingAs($user)->test('pages::leads.index')
            ->call('applyFigmaFollowUp', 'overdue')
            ->set('selectedDueTaskIds', [(string) $mine->id, (string) $other->id]);

        $response = $page->instance()->exportExcel(app(\App\Services\LeadExportService::class));
        ob_start();
        try {
            $response->sendContent();
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $file = tempnam(sys_get_temp_dir(), 'v54-excel-');
        if ($file === false) {
            throw new RuntimeException('Arquivo temporário indisponível.');
        }
        $book = null;
        try {
            file_put_contents($file, $bytes);
            $book = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($file);
            $sheet = $book->getActiveSheet();
            expect($sheet->getHighestColumn())->toBe('U')
                ->and($sheet->getHighestDataRow())->toBe(2)
                ->and((string) $sheet->getCell('U2')->getValue())->toBe('')
                ->and((string) $sheet->getCell('T2')->getValue())->toContain($mine->hubspot_id)
                ->and((string) $sheet->getCell('K2')->getValue())->toContain('V54 atividade numero 201');
        } finally {
            $book?->disconnectWorksheets();
            @unlink($file);
        }
        expect($alsoMine->id)->not->toBe($other->id);
    } finally {
        Carbon::setTestNow();
    }
});
