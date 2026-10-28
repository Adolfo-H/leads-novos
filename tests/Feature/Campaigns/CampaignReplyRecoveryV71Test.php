<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('allows diagnosing disabled IMAP without connecting, creating messages or modifying state', function (): void {
    config()->set('campaign_mail.imap_enabled', false);
    Mail::fake();
    Queue::fake();

    expect(Artisan::call('campaigns:recover-replies-v71'))->toBe(0);
    expect(Artisan::output())->toContain('Leitura IMAP: desabilitada');
    expect(Artisan::call('campaigns:recover-replies-v71', ['--apply' => true]))->toBe(1);
    expect(EmailCampaignReply::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('recovers a real correlated reply below an already advanced IMAP cursor without duplicates', function (): void {
    Mail::fake();
    Queue::fake();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Resposta recuperada V71',
        'subject' => 'Convite para apresentação',
        'body' => 'Mensagem de teste de correlacao IMAP. Nao enviar.',
        'status' => 'completed',
        'from_address' => 'sales@example.invalid',
        'from_name' => 'ExportControl Teste',
        'reply_to' => 'sales@example.invalid',
    ]);
    $recipient = EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'client@example.invalid',
        'normalized_email' => 'client@example.invalid',
        'status' => 'sent',
        'attempted_at' => now('UTC')->subHour(),
        'sent_at' => now('UTC')->subHour(),
        'message_id' => 'uuid-v71@example.invalid',
    ]);
    $cursor = EmailCampaignInboxCursor::query()->create([
        'mailbox' => 'sales@example.invalid',
        'uid_validity' => '12345',
        'last_uid' => 500,
        'last_synced_at' => now('UTC'),
    ]);
    $batch = [
        'uidvalidity' => '12345',
        'last_uid' => 420,
        'initialized' => false,
        'messages' => [[
            'uid' => 420,
            'from_email' => 'client@example.invalid',
            'subject' => 'Re: Convite para apresentação',
            'message_id' => '<new-reply-v71@example.invalid>',
            'in_reply_to' => '<uuid-v71@example.invalid>',
            'references' => '',
            'auto_submitted' => '',
            'received_at' => now('UTC')->toIso8601String(),
        ]],
    ];

    expect(app(CampaignInbox::class)->import('sales@example.invalid', $batch))->toBe(1);
    expect(EmailCampaignReply::query()->where('email_campaign_id', $campaign->id)->count())->toBe(1);
    expect($recipient->fresh()->replied_at)->not->toBeNull();
    expect($cursor->fresh()->last_uid)->toBe(500);
    expect(app(CampaignInbox::class)->import('sales@example.invalid', $batch))->toBe(0);
    expect(EmailCampaignReply::query()->count())->toBe(1);

    $unrelated = $batch;
    $unrelated['messages'][0]['uid'] = 419;
    $unrelated['messages'][0]['in_reply_to'] = '';
    $unrelated['messages'][0]['references'] = '';
    expect(app(CampaignInbox::class)->import('sales@example.invalid', $unrelated))->toBe(0);
    expect(EmailCampaignReply::query()->count())->toBe(1);

    expect(Artisan::call('campaigns:reply-audit', ['campaign' => $campaign->id]))->toBe(0);
    expect(Artisan::output())->toContain('Respostas importadas via IMAP: 1');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
