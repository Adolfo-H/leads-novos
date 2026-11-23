<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignInboundNoticeFilter;
use App\Services\Campaigns\CampaignInboundReplyReview;
use App\Services\Campaigns\CampaignInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ecV22Recipient(): EmailCampaignRecipient
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Prospecção teste 2026',
        'subject' => 'Vendas para tradings: como vocês comprovam a exportação?',
        'body' => 'Teste sem envio',
        'status' => 'completed',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.test',
    ]);

    return $campaign->recipients()->create([
        'email' => 'wrosantos@goiasa.example',
        'normalized_email' => 'wrosantos@goiasa.example',
        'message_id' => 'ec-v22-'.$campaign->id.'@example.test',
        'status' => 'sent',
        'sent_at' => now('UTC')->subMinutes(90),
        'attempted_at' => now('UTC')->subMinutes(90),
    ]);
}

/** @param array<string, mixed> $changes */
function ecV22Batch(int $uid, array $changes = []): array
{
    return [
        'uidvalidity' => '92021',
        'last_uid' => $uid,
        'initialized' => false,
        'messages' => [array_replace([
            'uid' => $uid,
            'from_email' => 'wrosantos@goiasa.example',
            'subject' => 'Vendas para tradings: como vocês comprovam a exportação?',
            'in_reply_to' => '',
            'references' => '',
            'auto_submitted' => '',
            'received_at' => now('UTC')->toIso8601String(),
        ], $changes)],
    ];
}

function ecV22Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
});

it('permite ao gestor confirmar resposta humana sem referencia e move para comerciais sem SMTP', function (): void {
    $recipient = ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(711));
    $notice = DB::table('email_campaign_inbound_notices')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->notice_type)->toBe('reply_needs_review')
        ->and($notice->link_method)->toBe('sender_only')
        ->and(EmailCampaignReply::query()->count())->toBe(0);

    $user = ecV22Manager();
    Livewire::actingAs($user)->test('pages::campaigns.replies')
        ->call('chooseKind', 'notices')
        ->assertSee('Retornos que merecem atenção')
        ->assertSee('Confirmar como resposta humana')
        ->call('requestNoticeReview', $notice->id)
        ->assertSee('Você confirmou que esta mensagem foi escrita por uma pessoa?')
        ->call('confirmNoticeHuman')
        ->assertSet('kind', 'commercial')
        ->assertSet('search', 'wrosantos@goiasa.example');

    $reply = EmailCampaignReply::query()->sole();
    $promoted = DB::table('email_campaign_inbound_notices')->first();
    expect($reply->email_campaign_recipient_id)->toBe($recipient->id)
        ->and($reply->imap_uid)->toBe(711)
        ->and($promoted->promoted_by_user_id)->toBe($user->id)
        ->and($promoted->promoted_reply_id)->toBe($reply->id)
        ->and($promoted->promoted_to_reply_at)->not->toBeNull()
        ->and($recipient->fresh()->replied_at)->not->toBeNull()
        ->and(CampaignInboundNoticeFilter::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('nao permite confirmar duas vezes a mesma mensagem', function (): void {
    ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(712));
    $id = (int) DB::table('email_campaign_inbound_notices')->value('id');
    $service = app(CampaignInboundReplyReview::class);
    $manager = ecV22Manager();
    expect($service->confirm($id, $manager->id))->toBeTrue()
        ->and($service->confirm($id, $manager->id))->toBeFalse()
        ->and(EmailCampaignReply::query()->count())->toBe(1);
});

it('recusa mover aviso automatico de ferias para a categoria humana', function (): void {
    ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(713, [
        'subject' => 'Resposta automática de férias',
        'auto_submitted' => 'auto-replied',
    ]));
    $id = (int) DB::table('email_campaign_inbound_notices')->value('id');
    expect($id)->toBeGreaterThan(0)
        ->and(app(CampaignInboundReplyReview::class)->confirm($id, ecV22Manager()->id))->toBeFalse()
        ->and(EmailCampaignReply::query()->count())->toBe(0);
});

it('nao promove aviso cujo remetente difere do destinatario', function (): void {
    $recipient = ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(714, [
        'from_email' => 'outra.pessoa@example.test',
        'in_reply_to' => '<'.$recipient->message_id.'>',
        'subject' => 'Alteração de e-mail',
    ]));
    $id = (int) DB::table('email_campaign_inbound_notices')->value('id');
    expect($id)->toBeGreaterThan(0)
        ->and(app(CampaignInboundReplyReview::class)->confirm($id, ecV22Manager()->id))->toBeFalse()
        ->and($recipient->fresh()->replied_at)->toBeNull();
});

it('bloqueia a confirmacao de resposta a vendedores pelo lado do servidor', function (): void {
    ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(715));
    $id = (int) DB::table('email_campaign_inbound_notices')->value('id');
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    Livewire::actingAs($seller)->test('pages::campaigns.replies')
        ->call('requestNoticeReview', $id)->assertForbidden();
    expect(EmailCampaignReply::query()->count())->toBe(0);
});

it('exige janela de 72 horas para confirmacao sem identificador de mensagem', function (): void {
    $recipient = ecV22Recipient();
    app(CampaignInbox::class)->import('comercial@example.test', ecV22Batch(716));
    $id = (int) DB::table('email_campaign_inbound_notices')->value('id');
    DB::table('email_campaign_inbound_notices')->where('id', $id)
        ->update(['received_at' => now('UTC')->addDays(5)]);
    expect(app(CampaignInboundReplyReview::class)->confirm($id, ecV22Manager()->id))->toBeFalse()
        ->and($recipient->fresh()->replied_at)->toBeNull();
});

it('usa cartoes legiveis em vez de tabelas antigas nas duas telas', function (): void {
    $global = file_get_contents(resource_path('views/campaigns/inbound-notices-global.blade.php'));
    $campaign = file_get_contents(resource_path('views/campaigns/inbound-notices.blade.php'));
    $style = file_get_contents(resource_path('views/campaigns/inbound-notices-style.blade.php'));
    expect($global)->toContain('ec-v22-item', 'Confirmar como resposta humana')
        ->not->toContain('ecm2-table')
        ->and($campaign)->toContain('ec-v22-item', 'Revisar na caixa geral')
        ->not->toContain('ecm2-table')
        ->and($style)->toContain('.dark .ec-v22', '--ecv-text', '@media (max-width:700px)');
});
