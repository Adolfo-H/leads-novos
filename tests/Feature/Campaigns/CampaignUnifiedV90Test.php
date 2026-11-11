<?php

use App\Models\EmailCampaign;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Services\Campaigns\CampaignPacingV90;
use App\Services\Campaigns\CampaignProspectingAudienceV80;
use App\Services\Campaigns\CampaignReplyCompanyV90;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function ec90Campaign(string $status = 'completed'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Campanha de teste V90',
        'subject' => 'Assunto V90',
        'body' => 'Mensagem sem envio de teste.',
        'status' => $status,
        'from_address' => 'comercial@example.org',
        'from_name' => 'Prospector',
        'reply_to' => 'comercial@example.org',
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
});

it('does not offer again an old SMTP-accepted email as a new prospecting audience', function (): void {
    $person = HubSpotContact::query()->create(['hubspot_id' => '900001', 'first_name' => 'Teste', 'email' => 'ja-enviado@example.org']);
    $campaign = ec90Campaign();
    $campaign->recipients()->create([
        'source_key' => 'hubspot:'.$person->hubspot_id,
        'email' => 'ja-enviado@example.org',
        'normalized_email' => 'ja-enviado@example.org',
        'status' => 'sent',
        'sent_at' => now('UTC')->subDays(600),
    ]);
    $query = app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot', 'days' => 90]);
    expect($query->where('eligible.normalized_email', 'ja-enviado@example.org')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('uses a linked HubSpot company and identifies the original campaign on a reply', function (): void {
    $company = HubSpotCompany::query()->create(['hubspot_id' => '900002', 'name' => 'Empresa Comercial Exemplo']);
    $person = HubSpotContact::query()->create(['hubspot_id' => '900003', 'first_name' => 'Exemplo', 'email' => 'recebeu@example.org']);
    $company->contacts()->attach($person->id, ['is_primary' => true]);
    $campaign = ec90Campaign();
    $recipient = $campaign->recipients()->create([
        'source_key' => 'hubspot:'.$person->hubspot_id,
        'email' => 'recebeu@example.org',
        'normalized_email' => 'recebeu@example.org',
        'status' => 'sent',
    ]);
    $reply = $campaign->replies()->create([
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'comercial@example.org',
        'uid_validity' => '1',
        'imap_uid' => 900003,
        'from_email' => 'recebeu@example.org',
    ])->load('recipient', 'campaign');
    $info = app(CampaignReplyCompanyV90::class)->forReplies([$reply]);
    expect($reply->campaign?->name)->toBe('Campanha de teste V90')
        ->and($info[(int) $reply->id]['label'])->toBe('Empresa Comercial Exemplo');
    Mail::assertNothingSent();
});

it('stores individual pacing without increasing the global SMTP limit or starting jobs', function (): void {
    $campaign = ec90Campaign('draft');
    $campaign->recipients()->create([
        'email' => 'ritmo@example.org',
        'normalized_email' => 'ritmo@example.org',
        'status' => 'pending',
    ]);
    app(CampaignPacingV90::class)->save($campaign, 1);
    expect(app(CampaignPacingV90::class)->limits((int) $campaign->id))
        ->toBe(['hour' => 1, 'tick' => 1]);
    expect(DB::table('email_campaigns')->where('id', $campaign->id)->value('status'))->toBe('draft');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
