<?php

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupScheduleViewV4;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeFollowupV4Root(): EmailCampaign
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $root = EmailCampaign::query()->create([
        'name' => 'Primeira campanha',
        'subject' => 'Primeiro contato ExportControl',
        'body' => 'Mensagem inicial de apresentacao',
        'status' => 'completed',
        'from_address' => 'contato@exportcontrol.com.br',
        'from_name' => 'ExportControl',
        'reply_to' => 'contato@exportcontrol.com.br',
        'created_by' => $user->id,
        'completed_at' => '2026-11-03 15:00:00',
    ]);
    $root->recipients()->create([
        'email' => 'fiscal@empresa.example',
        'normalized_email' => 'fiscal@empresa.example',
        'status' => 'sent',
        'sent_at' => '2026-11-03 16:00:00',
    ]);

    return $root;
}

function makeFollowupV4Plan(EmailCampaign $root, bool $automatic = false, string $status = 'planned'): int
{
    return (int) DB::table('email_campaign_sequences')->insertGetId([
        'email_campaign_id' => $root->id,
        'name' => 'Retomada comercial',
        'subject' => 'Podemos retomar o contato?',
        'body' => 'Olá, podemos continuar nossa conversa sobre a ExportControl?',
        'delay_days' => 5,
        'status' => $status,
        'automatic' => $automatic,
        'max_rounds' => 1,
        'sent_rounds' => 0,
        'last_campaign_id' => $automatic ? $root->id : null,
        'created_by' => $root->created_by,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

it('shows a manual planned follow-up with forecast, subject, message and clear no-automation warning', function (): void {
    $root = makeFollowupV4Root();
    makeFollowupV4Plan($root);
    $countBefore = EmailCampaign::query()->count();

    $view = app(CampaignFollowupScheduleViewV4::class)->forCampaign($root);
    $home = app(CampaignFollowupScheduleViewV4::class)->home();

    expect($view)->not->toBeNull()
        ->and($view['automatic'])->toBeFalse()
        ->and($view['state'])->toBe('manual')
        ->and($view['due_date'])->toBe('08/11/2026')
        ->and($view['next_round'])->toBe(2)
        ->and($view['subject'])->toBe('Podemos retomar o contato?')
        ->and($view['body'])->toContain('ExportControl')
        ->and($home)->toHaveCount(1)
        ->and($home[0]['state'])->toBe('manual')
        ->and(EmailCampaign::query()->count())->toBe($countBefore);
});

it('prefers an active automatic sequence over a manual draft of the same source', function (): void {
    $root = makeFollowupV4Root();
    makeFollowupV4Plan($root, true, 'active');
    makeFollowupV4Plan($root, false, 'planned');

    $detail = app(CampaignFollowupScheduleViewV4::class)->forCampaign($root);
    $home = app(CampaignFollowupScheduleViewV4::class)->home();

    expect($detail['automatic'])->toBeTrue()
        ->and($home)->toHaveCount(1)
        ->and($home[0]['automatic'])->toBeTrue();
});

it('does not fabricate a delivery date when campaign has not completed', function (): void {
    $root = makeFollowupV4Root();
    $root->update(['status' => 'running', 'completed_at' => null]);
    makeFollowupV4Plan($root, false, 'planned');

    $view = app(CampaignFollowupScheduleViewV4::class)->forCampaign($root);

    expect($view['due_date'])->toBeNull()
        ->and($view['state'])->toBe('manual');
});

it('keeps the home empty safely when no plan is registered', function (): void {
    $root = makeFollowupV4Root();

    expect(app(CampaignFollowupScheduleViewV4::class)->home())->toBe([])
        ->and(app(CampaignFollowupScheduleViewV4::class)->forCampaign($root))->toBeNull();
});
