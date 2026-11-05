<?php

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupScheduleView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{0: EmailCampaign, 1: int} */
function createVisualFollowupFixture(string $status = 'completed'): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $root = EmailCampaign::query()->create([
        'name' => 'Primeira campanha',
        'subject' => 'Apresentação ExportControl',
        'body' => 'Mensagem de apresentação para o contato.',
        'status' => $status,
        'from_address' => 'comercial@exportcontrol.com.br',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@exportcontrol.com.br',
        'created_by' => $user->id,
        'completed_at' => $status === 'completed' ? '2026-11-03 15:00:00' : null,
    ]);
    $root->recipients()->create([
        'email' => 'fiscal@empresa.example',
        'normalized_email' => 'fiscal@empresa.example',
        'status' => 'sent',
        'sent_at' => '2026-11-03 16:00:00',
    ]);
    $sequenceId = (int) DB::table('email_campaign_sequences')->insertGetId([
        'email_campaign_id' => $root->id,
        'last_campaign_id' => $root->id,
        'name' => 'Retomada comercial',
        'subject' => 'Podemos retomar o contato?',
        'body' => 'Olá, podemos conversar novamente sobre a solução ExportControl?',
        'delay_days' => 5,
        'status' => 'active',
        'automatic' => true,
        'max_rounds' => 2,
        'sent_rounds' => 0,
        'created_by' => $user->id,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    return [$root, $sequenceId];
}

it('shows the forecast, subject, body and real current eligibility without sending', function (): void {
    config()->set('campaign_mail.auto_followups_enabled', false);
    [$campaign] = createVisualFollowupFixture();

    $view = app(CampaignFollowupScheduleView::class)->forCampaign($campaign);

    expect($view)->not->toBeNull();
    expect($view['due_date'])->toBe('08/11/2026');
    expect($view['next_round'])->toBe(2);
    expect($view['subject'])->toBe('Podemos retomar o contato?');
    expect($view['body'])->toContain('ExportControl');
    expect($view['eligible_count'])->toBe(1);
    expect($view['state'])->toBe('blocked');
    expect(EmailCampaign::query()->count())->toBe(1);
});

it('excludes a contact that unsubscribed from the displayed eligibility count', function (): void {
    [$campaign] = createVisualFollowupFixture();
    DB::table('email_campaign_suppressions')->insert([
        'normalized_email' => 'fiscal@empresa.example',
        'reason' => 'unsubscribe',
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    $view = app(CampaignFollowupScheduleView::class)->forCampaign($campaign);

    expect($view)->not->toBeNull();
    expect($view['eligible_count'])->toBe(0);
    expect($view['state'])->toBe('no_recipients');
});

it('does not promise a date before the previous round completes', function (): void {
    [$campaign] = createVisualFollowupFixture('running');
    $view = app(CampaignFollowupScheduleView::class)->forCampaign($campaign);

    expect($view)->not->toBeNull();
    expect($view['due_date'])->toBeNull();
    expect($view['eligible_count'])->toBeNull();
    expect($view['state'])->toBe('waiting');
});

it('shows configured automatic followups on the campaigns home', function (): void {
    [$campaign] = createVisualFollowupFixture();
    $home = app(CampaignFollowupScheduleView::class)->home();

    expect($home)->toHaveCount(1);
    expect($home[0]['root_id'])->toBe((int) $campaign->id);
    expect($home[0]['max_followups'])->toBe(2);
});
