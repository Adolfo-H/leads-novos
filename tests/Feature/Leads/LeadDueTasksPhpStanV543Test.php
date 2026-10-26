<?php

use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\LeadDueTaskExcelV54Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('formats real HubSpot due dates consistently for overdue and today in the Excel', function (): void {
    Carbon::setTestNow('2026-10-26 10:00:00');
    try {
        $task = HubSpotTask::query()->create([
            'hubspot_id' => 'v543-due-overdue',
            'title' => 'Retornar ao cliente',
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => now()->subHour(),
        ]);

        $exporter = app(LeadDueTaskExcelV54Service::class);
        $overdue = $exporter->taskRow($task);
        expect($overdue['Status comercial'])->toBe('Atrasada')
            ->and($overdue['Próxima ação'])->toContain('26/10/2026 09:00');

        $task->update(['due_at' => now()->addHours(2)]);
        $today = $exporter->taskRow($task->fresh());
        expect($today['Status comercial'])->toBe('Vence hoje')
            ->and($today['Próxima ação'])->toContain('26/10/2026 12:00');
    } finally {
        Carbon::setTestNow();
    }
});

it('handles an unlinked task with no due date without inventing a due state', function (): void {
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'v543-due-missing',
        'title' => 'Tarefa sem prazo',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => null,
    ]);

    $row = app(LeadDueTaskExcelV54Service::class)->taskRow($task);
    expect($row['Status comercial'])->toBe('Sem prazo')
        ->and($row['Próxima ação'])->toBe('Tarefa sem prazo · ')
        ->and($row['CNPJ'])->toBe('')
        ->and($row['Score SDR'])->toBe('');
});

it('renders dated overdue task in the read-only diagnosis command', function (): void {
    Carbon::setTestNow('2026-10-26 10:00:00');
    try {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name' => 'Atendente V543',
            'hubspot_owner_id' => 'owner-v543',
        ]);
        HubSpotTask::query()->create([
            'hubspot_id' => 'v543-due-cli',
            'title' => 'Ligar antes do almoço',
            'assigned_to' => $user->name,
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => now()->subHour(),
            'raw_properties' => ['hubspot_owner_id' => 'owner-v543'],
        ]);

        expect(Artisan::call('prospector:check-due-tasks-v54', [
            'user' => (string) $user->id,
            '--sample' => 5,
        ]))->toBe(0);

        expect(Artisan::output())->toContain('v543-due-cli')
            ->toContain('Atrasada')
            ->toContain('26/10/2026 09:00');
    } finally {
        Carbon::setTestNow();
    }
});
