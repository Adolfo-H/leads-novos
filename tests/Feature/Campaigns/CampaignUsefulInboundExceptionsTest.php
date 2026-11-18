<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ecV21Recipient(string $address = 'governanca@frisa.example'): EmailCampaignRecipient
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha V21 de homologacao',
        'subject' => 'Vendas para tradings: como vocês comprovam a exportação?',
        'body' => 'Nao ha SMTP de verdade neste teste.',
        'status' => 'completed',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.test',
    ]);

    return $campaign->recipients()->create([
        'email' => $address,
        'normalized_email' => $address,
        'status' => 'sent',
        'message_id' => 'ec-v21-'.$campaign->id.'@example.test',
        'sent_at' => now('UTC')->subMinutes(30),
        'attempted_at' => now('UTC')->subMinutes(30),
    ]);
}

/** @param array<string, mixed> $changes */
function ecV21Batch(int $uid, array $changes): array
{
    return [
        'uidvalidity' => '81729',
        'last_uid' => $uid,
        'initialized' => false,
        'messages' => [array_replace([
            'uid' => $uid,
            'from_email' => 'governanca@frisa.example',
            'subject' => 'Vendas para tradings: como vocês comprovam a exportação?',
            'in_reply_to' => '',
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

it('mostra possivel aviso institucional com o mesmo assunto e o mesmo remetente mesmo sem Re', function (): void {
    $recipient = ecV21Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(801, [
        'auto_submitted' => 'auto-generated',
    ]));
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->notice_type)->toBe('reply_needs_review')
        ->and($notice->link_method)->toBe('sender_only')
        ->and((bool) $notice->requires_review)->toBeTrue()
        ->and((int) $notice->email_campaign_id)->toBe((int) $recipient->email_campaign_id)
        ->and(EmailCampaignReply::query()->count())->toBe(0)
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('guarda aviso novo email com assunto original e remetente diferente sem atribuir destinatario', function (): void {
    $recipient = ecV21Recipient();
    $batch = ecV21Batch(802, [
        'from_email' => 'wanderson@example.test',
        'subject' => 'ATENÇÃO - NOVO E-MAIL Re: Vendas para tradings: como vocês comprovam a exportação?',
    ]);
    app(CampaignInbox::class)->import('comercial@example.test', $batch);
    app(CampaignInbox::class)->import('comercial@example.test', $batch);

    $unlinked = DB::table('email_campaign_unlinked_notices')->first();
    expect($unlinked)->not->toBeNull()
        ->and(DB::table('email_campaign_unlinked_notices')->count())->toBe(1)
        ->and((int) $unlinked->candidate_campaign_id)->toBe((int) $recipient->email_campaign_id)
        ->and($unlinked->notice_type)->toBe('address_change')
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(0)
        ->and(EmailCampaignReply::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('nao associa uma mudanca de endereco a campanha errada se houver dois assuntos iguais', function (): void {
    ecV21Recipient();
    ecV21Recipient('outro@frisa.example');
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(803, [
        'from_email' => 'outroemail@example.test',
        'subject' => 'ATENÇÃO - NOVO E-MAIL Re: Vendas para tradings: como vocês comprovam a exportação?',
    ]));
    expect(DB::table('email_campaign_unlinked_notices')->count())->toBe(1)
        ->and(DB::table('email_campaign_unlinked_notices')->first()->candidate_campaign_id)->toBeNull();
});

it('nao registra avisos tecnicos ou mensagens pessoais com assuntos comuns', function (): void {
    ecV21Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(804, [
        'auto_submitted' => 'auto-generated', 'subject' => 'Mail delivery failed - mailbox full',
    ]));
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(805, [
        'from_email' => 'desconhecido@example.test',
        'subject' => 'Comunicado genérico',
        'auto_submitted' => '',
    ]));
    expect(DB::table('email_campaign_unlinked_notices')->count())->toBe(0)
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(0)
        ->and(EmailCampaignReply::query()->count())->toBe(0);
});

it('continua tratando uma resposta humana com Re e Message-ID iguais como humana', function (): void {
    $recipient = ecV21Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(806, [
        'subject' => 'Re: Vendas para tradings: como vocês comprovam a exportação?',
        'in_reply_to' => '<'.$recipient->message_id.'>',
    ]));
    expect(EmailCampaignReply::query()->count())->toBe(1)
        ->and(DB::table('email_campaign_inbound_notices')->count())->toBe(0)
        ->and(DB::table('email_campaign_unlinked_notices')->count())->toBe(0);
});

it('oferece os dois casos na interface de retornos e nunca inicia envios', function (): void {
    $recipient = ecV21Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(807, [
        'auto_submitted' => 'auto-generated',
    ]));
    app(CampaignInbox::class)->import('comercial@example.test', ecV21Batch(808, [
        'from_email' => 'novoendereco@example.test',
        'subject' => 'ATENÇÃO - NOVO E-MAIL Re: Vendas para tradings: como vocês comprovam a exportação?',
    ]));
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    Livewire::actingAs($manager)->test('pages::campaigns.replies')
        ->call('chooseKind', 'notices')
        ->assertSee('Avisos de e-mail alterado')
        ->assertSee('ATENÇÃO - NOVO E-MAIL')
        ->assertSee('Vendas para tradings');
    $this->actingAs($manager)->get(route('campaigns.index', [
        'campanha' => $recipient->email_campaign_id, 'aba' => 'replies',
    ]))->assertOk()
        ->assertSee('E-mails alterados — associação não confirmada');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('reexamina avisos mesmo se a janela nao tiver nova resposta humana exata', function (): void {
    $command = file_get_contents(app_path('Console/Commands/RecoverCampaignReplies.php'));
    expect($command)->toContain('EC_INBOUND_V21_BACKFILL')
        ->toContain('avisos sem destinatário confirmado')
        ->not->toContain('Nenhuma resposta comercial correspondeu com segurança. Nada foi gravado');
});
