<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignInboundNoticeFilter;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ecUsefulNoticeRecipient(): EmailCampaignRecipient
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha teste de avisos uteis',
        'subject' => 'Vendas para tradings: como voces comprovam a exportacao?',
        'body' => 'Mensagem ficticia de teste sem SMTP.',
        'status' => 'completed',
        'from_address' => 'comercial@example.test',
        'from_name' => 'Prospector',
        'reply_to' => 'comercial@example.test',
    ]);

    return $campaign->recipients()->create([
        'email' => 'contato@example.test',
        'normalized_email' => 'contato@example.test',
        'message_id' => 'teste-retorno-'.$campaign->id.'@example.test',
        'status' => 'sent',
        'attempted_at' => now('UTC')->subMinutes(30),
        'sent_at' => now('UTC')->subMinutes(30),
    ]);
}

/** @param array<string, mixed> $changes */
function ecUsefulNoticeBatch(EmailCampaignRecipient $recipient, int $uid, array $changes): array
{
    return [
        'uidvalidity' => '9981',
        'last_uid' => $uid,
        'initialized' => false,
        'messages' => [array_replace([
            'uid' => $uid,
            'from_email' => 'contato@example.test',
            'subject' => 'Re: Vendas para tradings: como voces comprovam a exportacao?',
            'in_reply_to' => '<'.$recipient->message_id.'>',
            'references' => '',
            'auto_submitted' => '',
            'received_at' => now('UTC')->toIso8601String(),
        ], $changes)],
    ];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
});

it('nao grava entrega falha caixa cheia ou spam como retorno automatico nem resposta humana', function (): void {
    $recipient = ecUsefulNoticeRecipient();
    $subjects = [
        'Mail delivery failed: returning message to sender',
        'Delivery Status Notification (Failure)',
        'Mailbox full - quota exceeded',
        'Spam policy: message rejected',
        'Falha na entrega - destinatario invalido',
    ];
    foreach ($subjects as $index => $subject) {
        app(CampaignInbox::class)->import('comercial@example.test', ecUsefulNoticeBatch(
            $recipient, $index + 100, ['subject' => $subject, 'auto_submitted' => 'auto-generated'],
        ));
    }
    expect(DB::table('email_campaign_inbound_notices')->count())->toBe(0)
        ->and(EmailCampaignReply::query()->count())->toBe(0)
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('nao interpreta mensagem auto-generated sem indicio comercial como retorno relevante', function (): void {
    $recipient = ecUsefulNoticeRecipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecUsefulNoticeBatch(
        $recipient, 201, ['auto_submitted' => 'auto-generated'],
    ));
    expect(DB::table('email_campaign_inbound_notices')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('registra ferias novo email mensagem automatica e resposta auto-replied e preserva o contador humano', function (): void {
    $recipient = ecUsefulNoticeRecipient();
    $examples = [
        ['subject' => 'Comunicado de ferias', 'auto_submitted' => 'auto-replied'],
        ['subject' => 'ATENCAO - NOVO E-MAIL! Re: Vendas para tradings'],
        ['subject' => 'Mensagem automatica - recebemos seu contato', 'auto_submitted' => 'auto-generated'],
        ['subject' => 'Re: Vendas para tradings: como voces comprovam a exportacao?', 'auto_submitted' => 'auto-replied'],
    ];
    foreach ($examples as $index => $example) {
        app(CampaignInbox::class)->import('comercial@example.test', ecUsefulNoticeBatch(
            $recipient, $index + 301, $example,
        ));
    }
    expect(CampaignInboundNoticeFilter::query()->count())->toBe(4)
        ->and(DB::table('email_campaign_inbound_notices')->where('notice_type', 'auto_reply')->count())->toBe(1)
        ->and(EmailCampaignReply::query()->count())->toBe(0)
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
});

it('esconde registros tecnicos antigos sem excluir historico ou alterar a fila de envio', function (): void {
    $recipient = ecUsefulNoticeRecipient();
    foreach ([
        ['delivery_notice', 'Falha na entrega ao destinatario'],
        ['automatic', 'Mail delivery failed'],
        ['automatic', 'Re: Vendas para tradings: como voces comprovam a exportacao?'],
        ['automatic', 'Mensagem automatica - recebemos seu contato'],
        ['address_change', 'Alteracao de e-mail'],
    ] as $index => [$type, $subject]) {
        DB::table('email_campaign_inbound_notices')->insert([
            'email_campaign_id' => $recipient->email_campaign_id,
            'email_campaign_recipient_id' => $recipient->id,
            'mailbox' => 'comercial@example.test',
            'uid_validity' => '909',
            'imap_uid' => $index + 901,
            'from_email' => 'contato@example.test',
            'subject' => $subject,
            'notice_type' => $type,
            'link_method' => 'reference_exact',
            'requires_review' => false,
            'received_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
    expect(DB::table('email_campaign_inbound_notices')->count())->toBe(5)
        ->and(CampaignInboundNoticeFilter::query()->count())->toBe(2)
        ->and($recipient->fresh()->status)->toBe('sent');
});

it('mostra avisos uteis na tela geral de Respostas e tambem na aba da campanha', function (): void {
    $recipient = ecUsefulNoticeRecipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecUsefulNoticeBatch(
        $recipient, 603, ['subject' => 'Comunicado de ferias', 'auto_submitted' => 'auto-replied'],
    ));
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($user)->get(route('campaigns.replies'))->assertOk()
        ->assertSee('Retornos úteis');
    $this->actingAs($user)->get(route('campaigns.index', [
        'campanha' => $recipient->email_campaign_id, 'aba' => 'replies',
    ]))->assertOk()->assertSee('Retornos automáticos e para revisão')
        ->assertSee('Comunicado de ferias');
    Livewire::actingAs($user)->test('pages::campaigns.replies')
        ->call('chooseKind', 'notices')
        ->assertSee('Retornos automáticos úteis')
        ->assertSee('Comunicado de ferias');
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡replies.blade.php'));
    expect($view)->toContain("chooseKind('notices')")
        ->toContain("@include('campaigns.inbound-notices-global')");
});
