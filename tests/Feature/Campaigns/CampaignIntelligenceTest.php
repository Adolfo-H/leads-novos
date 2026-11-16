<?php

use App\Mail\ProspectorHumanReplyMessage;
use App\Models\CommercialContact;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use App\Services\Campaigns\CampaignHumanReplies;
use App\Services\Campaigns\CampaignSegmentation;
use App\Services\Campaigns\CampaignSequenceGuard;
use App\Services\Campaigns\CampaignSequencePlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function ecV9Campaign(string $status = 'draft'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Teste inteligencia V9', 'subject' => 'Mensagem original',
        'body' => 'Mensagem original de homologacao', 'status' => $status,
        'from_address' => 'teste@exportcontrol.com.br',
        'from_name' => 'ExportControl', 'reply_to' => 'teste@exportcontrol.com.br',
    ]);
}

beforeEach(function (): void {
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.reply_send_enabled', false);
    config()->set('campaign_mail.followup_drafts_enabled', false);
    Mail::fake();
    Queue::fake();
});

it('restricts intelligence to authenticated managers', function (): void {
    $this->get(route('campaigns.intelligence.index'))->assertRedirect();
    $seller = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    });
    $this->actingAs($seller)->get(route('campaigns.intelligence.index'))->assertOk();
    $manager = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $this->actingAs($manager)->get(route('campaigns.intelligence.index'))->assertOk();
    Mail::assertNothingSent();
});

it('segments real manual contacts and respects suppression', function (): void {
    $user = User::factory()->create();
    CommercialContact::query()->create(['name' => 'Ana', 'email' => 'ana@example.org', 'job_title' => 'Fiscal', 'created_by' => $user->id]);
    CommercialContact::query()->create(['name' => 'Bruno', 'email' => 'bruno@example.org', 'job_title' => 'Fiscal', 'created_by' => $user->id]);
    EmailCampaignSuppression::query()->create(['normalized_email' => 'bruno@example.org', 'reason' => 'unsubscribe']);
    $rows = app(CampaignSegmentation::class)->preview(['origin' => 'manual', 'job' => 'Fiscal'], 20);
    expect(array_column($rows, 'email'))->toContain('ana@example.org')->not->toContain('bruno@example.org');
    Mail::assertNothingSent();
});

it('creates a segment record but not a campaign or mail', function (): void {
    $manager = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $original = EmailCampaign::query()->count();
    $this->actingAs($manager)->post(route('campaigns.intelligence.segments.save'), [
        'segment_name' => 'Gestores fiscais', 'origin' => 'manual', 'job' => 'Fiscal',
    ])->assertRedirect(route('campaigns.intelligence.index'));
    expect(DB::table('email_campaign_segments')->count())->toBe(1)
        ->and(EmailCampaign::query()->count())->toBe($original);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('prepares followups only as drafts after the delay and without replies or bounces', function (): void {
    $user = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $source = ecV9Campaign('completed');
    $original = $source->recipients()->create([
        'email' => 'lead@example.org', 'normalized_email' => 'lead@example.org',
        'source_key' => 'manual:999', 'status' => 'sent',
        'sent_at' => now('UTC')->subDays(10), 'attempted_at' => now('UTC')->subDays(10),
    ]);
    $replied = $source->recipients()->create([
        'email' => 'respondeu@example.org', 'normalized_email' => 'respondeu@example.org',
        'status' => 'sent', 'replied_at' => now('UTC'),
        'sent_at' => now('UTC')->subDays(10),
    ]);
    $planner = app(CampaignSequencePlanner::class);
    $id = $planner->create($user, $source, 'Retorno', 5, 'Olá novamente', 'Podemos conversar sobre a proposta?');
    $draftId = $planner->prepare($user, $id);
    expect($draftId)->not->toBeNull()
        ->and(EmailCampaign::query()->findOrFail($draftId)->status)->toBe('draft')
        ->and(EmailCampaignRecipient::query()->where('email_campaign_id', $draftId)->count())->toBe(1)
        ->and(DB::table('email_campaign_sequence_links')->where('original_recipient_id', $original->id)->exists())->toBeTrue()
        ->and(DB::table('email_campaign_sequence_links')->where('original_recipient_id', $replied->id)->exists())->toBeFalse();
    expect($planner->prepare($user, $id))->toBe($draftId);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('stops a previously prepared followup when the original contact answers later', function (): void {
    $user = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $source = ecV9Campaign('completed');
    $original = $source->recipients()->create([
        'email' => 'lead2@example.org', 'normalized_email' => 'lead2@example.org',
        'status' => 'sent', 'sent_at' => now('UTC')->subDays(10),
    ]);
    $planner = app(CampaignSequencePlanner::class);
    $id = $planner->create($user, $source, 'Retorno', 5, 'Olá novamente', 'Podemos conversar sobre a proposta?');
    $draftId = $planner->prepare($user, $id);
    $followup = EmailCampaignRecipient::query()->where('email_campaign_id', $draftId)->firstOrFail();
    $original->update(['replied_at' => now('UTC')]);
    expect(app(CampaignSequenceGuard::class)->suppressIfAnswered($followup))->toBeTrue()
        ->and($followup->fresh()->status)->toBe('suppressed');
    Mail::assertNothingSent();
});

it('blocks direct replies until separately authorized', function (): void {
    $user = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $campaign = ecV9Campaign();
    $person = $campaign->recipients()->create([
        'email' => 'pessoa@example.org', 'normalized_email' => 'pessoa@example.org',
        'status' => 'sent', 'message_id' => 'first@example.org',
    ]);
    $reply = EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $person->id,
        'mailbox' => 'teste@exportcontrol.com.br', 'uid_validity' => '900', 'imap_uid' => 1,
        'from_email' => 'pessoa@example.org', 'subject' => 'Re: Ola', 'received_at' => now('UTC'),
    ]);
    expect(fn () => app(CampaignHumanReplies::class)->send($user, $reply, 'Obrigado pela resposta.', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(DB::table('email_campaign_outbound_replies')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('allows an explicitly authorized human reply once per form confirmation', function (): void {
    $user = tap(User::factory()->create(['email_verified_at' => now()]), function (User $u): void {
        $u->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    });
    $campaign = ecV9Campaign();
    $recipient = $campaign->recipients()->create([
        'email' => 'lead3@example.org', 'normalized_email' => 'lead3@example.org',
        'status' => 'sent', 'message_id' => 'original@example.org',
    ]);
    $reply = EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@exportcontrol.com.br', 'uid_validity' => '999', 'imap_uid' => 29,
        'from_email' => 'lead3@example.org', 'subject' => 'Re: Mensagem',
        'incoming_message_id' => 'incoming@example.org', 'received_at' => now('UTC'),
    ]);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.reply_send_enabled', true);
    config()->set('campaign_mail.from_address', 'teste@exportcontrol.com.br');
    config()->set('campaign_mail.reply_to', 'teste@exportcontrol.com.br');
    $uuid = (string) Str::uuid();
    $service = app(CampaignHumanReplies::class);
    $first = $service->send($user, $reply, 'Obrigado, vamos agendar.', $uuid);
    $repeat = $service->send($user, $reply, 'Obrigado, vamos agendar.', $uuid);
    expect($first)->toBe($repeat)
        ->and(DB::table('email_campaign_outbound_replies')->count())->toBe(1);
    Mail::assertSent(ProspectorHumanReplyMessage::class, 1);
});

it('does not enable unattended followup drafting by default', function (): void {
    $this->artisan('campaigns:prepare-followup-drafts')->assertExitCode(0);
    expect(DB::table('email_campaign_sequences')->count())->toBe(0);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});
