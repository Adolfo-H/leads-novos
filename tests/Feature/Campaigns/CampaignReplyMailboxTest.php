<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignReplyMailbox;
use App\Services\Campaigns\CampaignReplyReader;
use App\Services\Campaigns\CampaignResponseFeed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

function ecReplyV23Campaign(): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Diagnóstico de respostas', 'subject' => 'Mensagem de teste',
        'body' => 'Mensagem fictícia.', 'status' => 'draft',
        'from_address' => 'legacy@example.test', 'from_name' => 'Teste',
        'reply_to' => 'legacy@example.test',
    ]);
}

function ecReplyV23Config(): void
{
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_username', 'legacy@example.test');
    config()->set('campaign_mail.imap_host', 'mail.example.test');
    config()->set('campaign_mail.imap_password', 'ficticio');
    config()->set('campaign_mail.imap_port', 993);
    foreach (['publicidade', 'propaganda'] as $key) {
        $address = $key.'@exportcontrol.com.br';
        config()->set('campaign_sender_profiles.'.$key, [
            'address' => $address, 'imap_host' => 'mail.exportcontrol.com.br',
            'imap_username' => $address, 'imap_port' => 993, 'imap_password' => 'ficticio',
        ]);
    }
}

function ecReplyV23Test(EmailCampaign $campaign, string $key, bool $old = false): EmailCampaignTestMessage
{
    return EmailCampaignTestMessage::query()->create([
        'email_campaign_id' => $campaign->id,
        'test_to' => 'internal@example.test',
        'message_id' => 'ec-dedicated-test-'.$campaign->id.'-'.$key.'@exportcontrol.com.br',
        'status' => 'smtp_accepted', 'attempted_at' => now(), 'sent_at' => now(),
        'replied_at' => $old ? now() : null,
        'reply_subject' => $old ? 'Re: teste' : null,
        'reply_uid_validity' => $old ? '1234' : null,
        'reply_imap_uid' => $old ? 42 : null,
    ]);
}

function ecReplyV23Batch(EmailCampaignTestMessage $test): array
{
    return [
        'uidvalidity' => '1234', 'last_uid' => 42, 'initialized' => false,
        'messages' => [[
            'uid' => 42, 'from_email' => 'internal@example.test',
            'subject' => 'Re: teste', 'in_reply_to' => '<'.$test->message_id.'>',
            'references' => '', 'auto_submitted' => '', 'received_at' => now()->toIso8601String(),
        ]],
    ];
}

it('resolves each authorized receiving mailbox without live sending or SMTP credentials', function (): void {
    ecReplyV23Config();
    expect(CampaignReplyMailbox::imap('publicidade@exportcontrol.com.br')['username'])->toBe('publicidade@exportcontrol.com.br')
        ->and(CampaignReplyMailbox::imap('propaganda@exportcontrol.com.br')['username'])->toBe('propaganda@exportcontrol.com.br')
        ->and(CampaignReplyMailbox::imap('legacy@example.test')['username'])->toBe('legacy@example.test')
        ->and(CampaignReplyMailbox::imap('other@example.test'))->toBeNull()
        ->and(config('campaign_mail.live_enabled'))->toBeFalse();
});

it('records the actual mailbox for new test replies and enriches already tracked tests without counting twice', function (): void {
    ecReplyV23Config();
    Mail::fake();
    $campaign = ecReplyV23Campaign();
    $first = ecReplyV23Test($campaign, 'publicidade');
    $old = ecReplyV23Test($campaign, 'propaganda', true);
    $inbox = app(CampaignInbox::class);
    expect($inbox->import('publicidade@exportcontrol.com.br', ecReplyV23Batch($first)))->toBe(1)
        ->and($first->fresh()->getAttribute('reply_mailbox'))->toBe('publicidade@exportcontrol.com.br');
    expect($inbox->import('publicidade@exportcontrol.com.br', ecReplyV23Batch($old)))->toBe(0)
        ->and($old->fresh()->getAttribute('reply_mailbox'))->toBeNull();
    expect($inbox->import('propaganda@exportcontrol.com.br', ecReplyV23Batch($old)))->toBe(0)
        ->and($old->fresh()->getAttribute('reply_mailbox'))->toBe('propaganda@exportcontrol.com.br')
        ->and(EmailCampaignReply::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('reads existing dedicated test replies through the correct mailbox and caches only verified text', function (): void {
    ecReplyV23Config();
    Mail::fake();
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($manager);
    $campaign = ecReplyV23Campaign();
    $fake = tempnam(sys_get_temp_dir(), 'ec-reply-v23-');
    if ($fake === false) {
        throw new RuntimeException('Não foi possível criar fixture IMAP local.');
    }
    try {
        file_put_contents($fake, <<<'PY'
#!/usr/bin/env python3
import json, sys
p = json.load(sys.stdin)
key = p['username'].split('@')[0]
assert key in ('publicidade', 'propaganda')
if p['expected_reference']:
    assert p['expected_from'] == 'internal@example.test'
    assert p['expected_reference'].endswith('-' + key + '@exportcontrol.com.br')
else:
    assert p['expected_from'] == key + '@example.test'
assert p['uid'] == 42 and p['uidvalidity'] == '1234'
print(json.dumps({'body': 'Resposta ficticia de ' + key}))
PY
        );
        chmod($fake, 0700);
        config()->set('campaign_mail.python', $fake);
        foreach (['publicidade', 'propaganda'] as $key) {
            $test = ecReplyV23Test($campaign, $key, true);
            expect(app(CampaignReplyReader::class)->readPilotReply($test->id))->toBe('Resposta ficticia de '.$key)
                ->and($test->fresh()->getAttribute('reply_mailbox'))->toBe($key.'@exportcontrol.com.br')
                ->and($test->fresh()->reply_body_text)->toBe('Resposta ficticia de '.$key);
            $recipient = EmailCampaignRecipient::query()->create([
                'email_campaign_id' => $campaign->id, 'email' => $key.'@example.test',
                'normalized_email' => $key.'@example.test', 'status' => 'sent',
                'attempted_at' => now(), 'sent_at' => now(),
            ]);
            $reply = EmailCampaignReply::query()->create([
                'email_campaign_id' => $campaign->id, 'email_campaign_recipient_id' => $recipient->id,
                'mailbox' => $key.'@exportcontrol.com.br', 'uid_validity' => '1234', 'imap_uid' => 42,
                'from_email' => $recipient->normalized_email, 'subject' => 'Re: comercial', 'received_at' => now(),
            ]);
            expect(app(CampaignReplyReader::class)->readCampaignReply($reply->id))->toBe('Resposta ficticia de '.$key)
                ->and($reply->fresh()->body_text)->toBe('Resposta ficticia de '.$key);
        }
        // An unrelated mailbox is not a valid fallback.
        $test = EmailCampaignTestMessage::query()->firstOrFail();
        $test->update(['reply_mailbox' => 'unknown@example.test', 'reply_body_text' => null]);
        expect(fn () => app(CampaignReplyReader::class)->readPilotReply($test->id))->toThrow(ValidationException::class);
    } finally {
        unlink($fake);
    }
    Mail::assertNothingSent();
});

it('shows actual mailbox and original assigned sender across the commercial feed without mixing test replies', function (): void {
    ecReplyV23Config();
    $campaign = ecReplyV23Campaign();
    foreach (['publicidade', 'propaganda'] as $index => $key) {
        $address = $key.'@exportcontrol.com.br';
        $recipient = EmailCampaignRecipient::query()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'client'.$index.'@example.test',
            'normalized_email' => 'client'.$index.'@example.test',
            'status' => 'sent', 'attempted_at' => now(), 'sent_at' => now(),
        ]);
        $recipient->forceFill(['sender_profile' => $key, 'sender_address' => $address])->save();
        EmailCampaignReply::query()->create([
            'email_campaign_id' => $campaign->id,
            'email_campaign_recipient_id' => $recipient->id,
            'mailbox' => $address, 'uid_validity' => '1234', 'imap_uid' => 42,
            'from_email' => $recipient->normalized_email, 'subject' => 'Re: mensagem', 'received_at' => now(),
        ]);
    }
    ecReplyV23Test($campaign, 'publicidade', true);
    $feed = app(CampaignResponseFeed::class);
    $filtered = $feed->paginate(null, 'propaganda@exportcontrol.com.br');
    expect($feed->globalTotals()['human'])->toBe(2)
        ->and($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->mailbox)->toBe('propaganda@exportcontrol.com.br')
        ->and($filtered->items()[0]->recorded_sender)->toBe('propaganda@exportcontrol.com.br');
});
