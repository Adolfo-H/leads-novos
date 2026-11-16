<?php

declare(strict_types=1);

use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('refuses unbounded recovery without reading mail or sending anything', function (): void {
    Mail::fake();
    Queue::fake();

    expect(Artisan::call('campaigns:recover-replies', ['--hours' => '0']))->toBe(1);
    expect(Artisan::call('campaigns:recover-replies', ['--hours' => '169']))->toBe(1);
    expect(Artisan::call('campaigns:recover-replies', ['--limit' => '1201']))->toBe(1);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps real sending untouched when IMAP is disabled', function (): void {
    config()->set('campaign_mail.imap_enabled', false);
    Mail::fake();
    Queue::fake();

    expect(Artisan::call('campaigns:recover-replies', ['--apply' => true]))->toBe(1);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reconciles a recent reply below the existing IMAP cursor without moving the cursor backwards', function (): void {
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha resposta V77',
        'subject' => 'Contato ExportControl',
        'body' => 'Mensagem comercial de teste sem envio real.',
        'status' => 'completed',
        'from_address' => 'atendimento@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'atendimento@example.test',
    ]);
    $recipient = EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'contato@example.test',
        'normalized_email' => 'contato@example.test',
        'status' => 'sent',
        'message_id' => 'aabbccdd-1122-3344-5566-778899aabbcc@example.test',
        'attempted_at' => now('UTC')->subDay(),
        'sent_at' => now('UTC')->subDay(),
    ]);
    $cursor = EmailCampaignInboxCursor::query()->create([
        'mailbox' => 'atendimento@example.test',
        'uid_validity' => '98765',
        'last_uid' => 200,
    ]);
    $batch = [
        'uidvalidity' => '98765',
        'last_uid' => 200,
        'initialized' => false,
        'messages' => [[
            'uid' => 150,
            'from_email' => 'contato@example.test',
            'in_reply_to' => '<'.$recipient->message_id.'>',
            'references' => '',
            'auto_submitted' => '',
            'subject' => 'Re: Contato ExportControl',
            'received_at' => now('UTC')->toIso8601String(),
        ]],
    ];

    Mail::fake();
    Queue::fake();
    expect(app(CampaignInbox::class)->import('atendimento@example.test', $batch))->toBe(1)
        ->and(app(CampaignInbox::class)->import('atendimento@example.test', $batch))->toBe(0)
        ->and(EmailCampaignReply::query()->where('email_campaign_recipient_id', $recipient->id)->count())->toBe(1)
        ->and((int) $cursor->fresh()->last_uid)->toBe(200);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('imports no response without exact sender or Message-ID', function (): void {
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha segura V77',
        'subject' => 'Assunto semelhante',
        'body' => 'Mensagem comercial de teste sem envio real.',
        'status' => 'completed',
        'from_address' => 'atendimento@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'atendimento@example.test',
    ]);
    EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'destino@example.test',
        'normalized_email' => 'destino@example.test',
        'status' => 'sent',
        'message_id' => 'msg-77@example.test',
        'attempted_at' => now('UTC')->subDay(),
    ]);
    $batch = [
        'uidvalidity' => '98765', 'last_uid' => 200, 'initialized' => false,
        'messages' => [[
            'uid' => 178,
            'from_email' => 'pessoa-diferente@example.test',
            'in_reply_to' => '<msg-77@example.test>',
            'references' => '', 'auto_submitted' => '',
            'subject' => 'Re: Assunto semelhante',
            'received_at' => now('UTC')->toIso8601String(),
        ], [
            'uid' => 179,
            'from_email' => 'destino@example.test',
            'in_reply_to' => '', 'references' => '',
            'auto_submitted' => '',
            'subject' => 'Re: Assunto semelhante',
            'received_at' => now('UTC')->toIso8601String(),
        ]],
    ];
    expect(app(CampaignInbox::class)->import('atendimento@example.test', $batch))->toBe(0)
        ->and(EmailCampaignReply::query()->count())->toBe(0);
});
