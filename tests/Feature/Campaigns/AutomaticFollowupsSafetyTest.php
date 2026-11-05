<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignAutomaticFollowups;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('keeps automated SMTP continuation blocked by default', function (): void {
    config()->set('campaign_mail.auto_followups_enabled', false);
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);

    expect(app(CampaignAutomaticFollowups::class)->tick())->toBe(0);
    expect(EmailCampaign::query()->count())->toBe(0);
});

it('refuses to register automatic sends when the original campaign has not finished', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Primeira rodada', 'subject' => 'Contato ExportControl',
        'body' => 'Apresentação comercial para conhecer a solução ExportControl.',
        'from_address' => 'comercial@exportcontrol.com.br',
        'from_name' => 'ExportControl', 'reply_to' => 'comercial@exportcontrol.com.br',
        'status' => 'running', 'created_by' => $user->id,
    ]);
    $campaign->recipients()->create([
        'email' => 'contato@empresa.example', 'normalized_email' => 'contato@empresa.example',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(10),
    ]);
    expect(fn () => app(CampaignSequencePlanner::class)->create(
        $user, $campaign, 'Retomada', 4, 'Novamente',
        'Olá, gostaríamos de dar continuidade ao contato.', true, 2,
    ))->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
});

it('records explicit continuation without sending or adding a recipient until the gates open', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Primeira rodada', 'subject' => 'Contato ExportControl',
        'body' => 'Apresentação comercial para conhecer a solução ExportControl.',
        'from_address' => 'comercial@exportcontrol.com.br',
        'from_name' => 'ExportControl', 'reply_to' => 'comercial@exportcontrol.com.br',
        'status' => 'completed', 'created_by' => $user->id,
        'completed_at' => now('UTC')->subDays(6),
    ]);
    $campaign->recipients()->create([
        'email' => 'contato@empresa.example', 'normalized_email' => 'contato@empresa.example',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(6),
    ]);
    $id = app(CampaignSequencePlanner::class)->create(
        $user, $campaign, 'Retomada', 4, 'Novamente',
        'Olá, gostaríamos de dar continuidade ao contato.', true, 2,
    );
    config()->set('campaign_mail.auto_followups_enabled', false);
    expect(app(CampaignAutomaticFollowups::class)->tick())->toBe(0);
    expect(DB::table('email_campaign_sequences')->where('id', $id)->value('status'))->toBe('active');
    expect(EmailCampaign::query()->count())->toBe(1);
    expect(EmailCampaignRecipient::query()->count())->toBe(1);
});
