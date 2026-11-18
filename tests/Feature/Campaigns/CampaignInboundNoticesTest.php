<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function inboundNoticesFixture(string $address = 'contato@example.test', string $subject = 'Vendas para tradings: como vocês comprovam a exportação?'): EmailCampaignRecipient
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha de teste retorno',
        'subject' => $subject,
        'body' => 'Mensagem fictícia para testes IMAP offline.',
        'status' => 'completed',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.test',
    ]);

    return EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => $address,
        'normalized_email' => $address,
        'status' => 'sent',
        'message_id' => 'msg-'.$campaign->id.'@example.test',
        'attempted_at' => now('UTC')->subHour(),
        'sent_at' => now('UTC')->subHour(),
    ]);
}

/** @return array{uidvalidity:string,last_uid:int,initialized:bool,messages:list<array<string,mixed>>} */
function inboundNoticesBatch(int $uid, array $data): array
{
    return ['uidvalidity' => '301', 'last_uid' => $uid, 'initialized' => false,
        'messages' => [array_merge([
            'uid' => $uid,
            'from_email' => 'contato@example.test',
            'subject' => 'Re: Vendas para tradings: como vocês comprovam a exportação?',
            'in_reply_to' => '',
            'references' => '',
            'auto_submitted' => '',
            'received_at' => now('UTC')->toIso8601String(),
        ], $data)],
    ];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
});

it('separa resposta de ferias com referencia exata da resposta humana e evita duplicacao', function (): void {
    $recipient = inboundNoticesFixture();
    $batch = inboundNoticesBatch(101, [
        'in_reply_to' => '<'.$recipient->message_id.'>',
        'auto_submitted' => 'auto-replied',
        'subject' => 'COMUNICADO DE FÉRIAS Re: Vendas para tradings',
    ]);
    $inbox = app(CampaignInbox::class);

    expect($inbox->import('comercial@example.test', $batch))->toBe(0)
        ->and($inbox->import('comercial@example.test', $batch))->toBe(0)
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(1)
        ->and(DB::table('email_campaign_inbound_notices')->first()->notice_type)->toBe('absence')
        ->and(DB::table('email_campaign_inbound_notices')->first()->link_method)->toBe('reference_exact')
        ->and(EmailCampaignReply::query()->count())->toBe(0)
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('registra aviso de e-mail alterado como retorno a revisar sem atualizar o CRM', function (): void {
    $recipient = inboundNoticesFixture();
    $batch = inboundNoticesBatch(102, [
        'from_email' => 'respostas@example.test',
        'in_reply_to' => '<'.$recipient->message_id.'>',
        'subject' => 'ATENÇÃO - E-MAIL NOVO! Re: Vendas para tradings',
    ]);

    expect(app(CampaignInbox::class)->import('comercial@example.test', $batch))->toBe(0);
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->notice_type)->toBe('address_change')
        ->and($notice->link_method)->toBe('reference_mismatch')
        ->and((bool) $notice->requires_review)->toBeTrue()
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
});

it('relaciona automaticamente um aviso sem referencias apenas se houve um unico envio recente ao remetente', function (): void {
    $recipient = inboundNoticesFixture();
    $batch = inboundNoticesBatch(103, [
        'subject' => 'Mensagem automática - recebemos seu contato',
        'auto_submitted' => 'auto-replied',
    ]);

    expect(app(CampaignInbox::class)->import('comercial@example.test', $batch))->toBe(0);
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and((int) $notice->email_campaign_id)->toBe((int) $recipient->email_campaign_id)
        ->and($notice->link_method)->toBe('sender_only')
        ->and((bool) $notice->requires_review)->toBeTrue();

    // Segunda campanha para o mesmo remetente torna o vinculo ambiguo.
    inboundNoticesFixture();
    $second = inboundNoticesBatch(104, ['subject' => 'Mensagem automática', 'auto_submitted' => 'auto-replied']);
    app(CampaignInbox::class)->import('comercial@example.test', $second);
    expect(DB::table('email_campaign_inbound_notices')->count())->toBe(1);
});

it('exibe possivel resposta humana sem referencias somente como revisao e preserva a regra exata para respostas normais', function (): void {
    $recipient = inboundNoticesFixture();
    $suspected = inboundNoticesBatch(105, []);
    app(CampaignInbox::class)->import('comercial@example.test', $suspected);
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->notice_type)->toBe('reply_needs_review')
        ->and(EmailCampaignReply::query()->count())->toBe(0);

    $human = inboundNoticesBatch(106, ['in_reply_to' => '<'.$recipient->message_id.'>']);
    expect(app(CampaignInbox::class)->import('comercial@example.test', $human))->toBe(1)
        ->and(EmailCampaignReply::query()->count())->toBe(1)
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(1)
        ->and($recipient->fresh()->replied_at)->not->toBeNull();
});

it('reconhece alteracao de e-mail como aviso para revisar, mesmo sem In-Reply-To quando so ha um envio recente', function (): void {
    $recipient = inboundNoticesFixture();
    $batch = inboundNoticesBatch(109, [
        'subject' => 'Alteração de e-mail',
        'in_reply_to' => '',
        'references' => '',
    ]);

    expect(app(CampaignInbox::class)->import('comercial@example.test', $batch))->toBe(0);
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->notice_type)->toBe('address_change')
        ->and($notice->link_method)->toBe('sender_only')
        ->and((bool) $notice->requires_review)->toBeTrue()
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
});

it('nunca registra um e-mail pessoal sem correlacao segura com a campanha', function (): void {
    inboundNoticesFixture();
    $unrelated = inboundNoticesBatch(107, [
        'from_email' => 'desconhecido@example.test',
        'subject' => 'Mensagem automática',
        'auto_submitted' => 'auto-replied',
    ]);
    expect(app(CampaignInbox::class)->import('comercial@example.test', $unrelated))->toBe(0)
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(0)
        ->and(EmailCampaignReply::query()->count())->toBe(0);
    Mail::assertNothingSent();
});
