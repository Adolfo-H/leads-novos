<?php

use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\Campaigns\CampaignFollowupScheduleViewV4;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('shows real SMTP acceptance for a follow-up round even before the campaign status closes', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $root = EmailCampaign::query()->create([
        'name' => 'primeira campanha',
        'subject' => 'Primeiro contato',
        'body' => 'Mensagem inicial para acompanhamento comercial.',
        'from_address' => 'comercial@example.com',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.com',
        'status' => 'completed',
        'created_by' => $user->id,
        'completed_at' => now('UTC')->subDays(3),
    ]);
    $root->recipients()->create([
        'email' => 'pessoa1@empresa.example',
        'normalized_email' => 'pessoa1@empresa.example',
        'status' => 'sent',
        'sent_at' => now('UTC')->subDays(3),
    ]);

    $round = EmailCampaign::query()->create([
        'name' => '[Rodada 2] Retomada comercial',
        'subject' => 'RE: Primeiro contato',
        'body' => 'Gostaríamos de dar continuidade ao contato anterior.',
        'from_address' => 'comercial@example.com',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.com',
        'status' => 'running',
        'created_by' => $user->id,
        'followup_root_campaign_id' => $root->id,
        'followup_round' => 2,
    ]);
    foreach (['pessoa1', 'pessoa2'] as $name) {
        $round->recipients()->create([
            'email' => $name.'@empresa.example',
            'normalized_email' => $name.'@empresa.example',
            'status' => 'sent',
            'sent_at' => now('UTC'),
        ]);
    }

    DB::table('email_campaign_sequences')->insert([
        'email_campaign_id' => $root->id,
        'name' => 'Retomada comercial',
        'subject' => 'RE: Primeiro contato',
        'body' => 'Gostaríamos de dar continuidade ao contato anterior.',
        'delay_days' => 3,
        'send_time_local' => '08:00',
        'first_send_date' => null,
        'status' => 'finished',
        'automatic' => true,
        'max_rounds' => 1,
        'sent_rounds' => 1,
        'last_campaign_id' => $round->id,
        'created_by' => $user->id,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);

    $service = app(CampaignFollowupScheduleViewV4::class);
    $snapshot = $service->forCampaign($root);
    $home = $service->home();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['state'])->toBe('finished')
        ->and($snapshot['state_label'])->toContain('2ª rodada')
        ->and($snapshot['state_label'])->toContain('SMTP')
        ->and($snapshot['latest_campaign_id'])->toBe($round->id)
        ->and($snapshot['latest_total_count'])->toBe(2)
        ->and($snapshot['accepted_smtp_count'])->toBe(2)
        ->and($snapshot['pending_count'])->toBe(0)
        ->and($snapshot['last_round_number'])->toBe(2)
        ->and($snapshot['latest_smtp_at'])->not->toBeNull()
        ->and($home)->toHaveCount(1)
        ->and($home[0]['latest_campaign_id'])->toBe($round->id)
        ->and(EmailCampaign::query()->count())->toBe(2);
});

it('contains a visible SMTP result and navigation to the actual round in both campaign views', function (): void {
    $home = file_get_contents(resource_path('views/campaigns/followup-schedule-home-v4.blade.php'));
    $detail = file_get_contents(resource_path('views/livewire/campaign-followup-editor.blade.php'));

    expect($home)->toContain('ecv6-home-result')
        ->toContain('latest_campaign_id')
        ->toContain('Aceitação SMTP não comprova entrega')
        ->and($detail)->toContain('ecv6-detail-result')
        ->toContain('RESULTADO REAL DO FOLLOW-UP')
        ->toContain('latest_total_count');
});
