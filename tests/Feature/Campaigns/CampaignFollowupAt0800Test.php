<?php

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupScheduleClock;
use App\Services\Campaigns\CampaignFollowupScheduleViewV4;
use App\Services\Campaigns\CampaignFollowupSettingsV5;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0:User,1:EmailCampaign} */
function makeClockFixture(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $root = EmailCampaign::query()->create([
        'name' => 'Campanha de terça',
        'subject' => 'Contato ExportControl',
        'body' => 'Apresentação comercial para conhecer a solução da ExportControl.',
        'from_address' => 'comercial@example.com',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.com',
        'status' => 'completed',
        'completed_at' => '2026-11-03 20:42:00', // Terça, 17:42 em São Paulo
        'created_by' => $user->id,
    ]);
    $root->recipients()->create([
        'email' => 'contato@empresa.example',
        'normalized_email' => 'contato@empresa.example',
        'status' => 'sent',
        'sent_at' => '2026-11-03 20:42:00',
    ]);

    return [$user, $root];
}

it('starts a calendar-day follow-up on Friday at 08:00 local instead of Friday at 17:42', function (): void {
    [, $root] = makeClockFixture();
    $clock = app(CampaignFollowupScheduleClock::class);
    $due = $clock->dueAt($root, 3, '08:00');
    expect($due)->not->toBeNull();
    expect($due->format('Y-m-d H:i'))->toBe('2026-11-06 11:00'); // UTC
    expect($due->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'))->toBe('2026-11-06 08:00');
});

it('preserves the old exact-hour interval when the sequence has no configured hour', function (): void {
    [, $root] = makeClockFixture();
    $due = app(CampaignFollowupScheduleClock::class)->dueAt($root, 3);
    expect($due)->not->toBeNull()
        ->and($due->format('Y-m-d H:i'))->toBe('2026-11-06 20:42');
});

it('honors an explicitly selected first follow-up date', function (): void {
    [, $root] = makeClockFixture();
    $due = app(CampaignFollowupScheduleClock::class)->dueAt($root, 3, '08:00', '2026-11-09');
    expect($due)->not->toBeNull()
        ->and($due->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'))->toBe('2026-11-09 08:00');
});

it('stores the manager-approved local time without starting SMTP or creating another campaign', function (): void {
    [$user, $root] = makeClockFixture();
    $planId = app(CampaignFollowupSettingsV5::class)->save($user, (int) $root->id, [
        'name' => 'Retomada comercial',
        'delay_days' => 3,
        'send_time_local' => '08:00',
        'first_send_date' => '2026-11-06',
        'max_rounds' => 1,
        'subject' => 'RE: Contato ExportControl',
        'body' => 'Bom dia, gostaria de retomar nossa conversa sobre o controle fiscal.',
    ]);
    $plan = DB::table('email_campaign_sequences')->find($planId);
    expect($plan->send_time_local)->toBe('08:00')
        ->and((string) $plan->first_send_date)->toBe('2026-11-06')
        ->and($plan->status)->toBe('active')
        ->and(EmailCampaign::query()->count())->toBe(1);
});

it('removes recipients who replied in another campaign from the next-round preview', function (): void {
    [$user, $root] = makeClockFixture();
    $another = EmailCampaign::query()->create([
        'name' => 'Outra campanha',
        'subject' => 'Contato anterior',
        'body' => 'Outro contato para a mesma empresa.',
        'from_address' => 'comercial@example.com',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.com',
        'status' => 'completed',
        'created_by' => $user->id,
    ]);
    $another->recipients()->create([
        'email' => 'contato@empresa.example',
        'normalized_email' => 'contato@empresa.example',
        'status' => 'sent',
        'sent_at' => '2026-11-02 20:00:00',
        'replied_at' => '2026-11-03 12:00:00',
    ]);

    app(CampaignFollowupSettingsV5::class)->save($user, (int) $root->id, [
        'name' => 'Retomada comercial',
        'delay_days' => 3,
        'send_time_local' => '08:00',
        'max_rounds' => 1,
        'subject' => 'RE: Contato ExportControl',
        'body' => 'Bom dia, gostaria de retomar nossa conversa sobre o controle fiscal.',
    ]);

    $view = app(CampaignFollowupScheduleViewV4::class)->forCampaign($root);
    expect($view)->not->toBeNull()
        ->and($view['eligible_count'])->toBe(0)
        ->and($view['due_datetime'])->toBe('06/11/2026 08:00');
});
