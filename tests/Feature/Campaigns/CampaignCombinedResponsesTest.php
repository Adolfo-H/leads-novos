<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use App\Services\Campaigns\CampaignResponseFeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ecV23NewCampaign(string $name): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => $name,
        'subject' => 'Vendas para tradings: como voces comprovam a exportacao?',
        'body' => 'Mensagem ficticia para validar o painel, sem enviar emails.',
        'status' => 'completed',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl Teste',
        'reply_to' => 'comercial@example.test',
    ]);
}

function ecV23Recipient(EmailCampaign $campaign, string $email = 'vendas@example.test'): EmailCampaignRecipient
{
    return $campaign->recipients()->create([
        'email' => $email,
        'normalized_email' => $email,
        'message_id' => 'v23-'.$campaign->id.'@example.test',
        'status' => 'sent',
        'attempted_at' => now('UTC')->subHours(2),
        'sent_at' => now('UTC')->subHours(2),
    ]);
}

function ecV23Human(EmailCampaignRecipient $recipient, int $uid, int $minutesAgo = 10): void
{
    EmailCampaignReply::query()->create([
        'email_campaign_id' => $recipient->email_campaign_id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'comercial@example.test',
        'uid_validity' => '7700',
        'imap_uid' => $uid,
        'from_email' => $recipient->normalized_email,
        'subject' => 'Re: Vendas para tradings',
        'received_at' => now('UTC')->subMinutes($minutesAgo),
    ]);
}

function ecV23Notice(EmailCampaignRecipient $recipient, int $uid, string $subject, string $kind, int $minutesAgo = 10, ?int $promoted = null): void
{
    DB::table('email_campaign_inbound_notices')->insert([
        'email_campaign_id' => $recipient->email_campaign_id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'comercial@example.test',
        'uid_validity' => '7700',
        'imap_uid' => $uid,
        'from_email' => $recipient->normalized_email,
        'subject' => $subject,
        'notice_type' => $kind,
        'link_method' => 'sender_only',
        'requires_review' => true,
        'received_at' => now('UTC')->subMinutes($minutesAgo),
        'promoted_to_reply_at' => $promoted !== null ? now('UTC') : null,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

function ecV23Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_enabled', false);
});

it('soma 1 resposta humana e 6 retornos uteis, mas ignora falhas tecnicas', function (): void {
    $recipient = ecV23Recipient(ecV23NewCampaign('V23 Contagem'));
    ecV23Human($recipient, 10, 4);
    foreach ([
        ['Comunicado de ferias', 'absence'],
        ['Alteracao de e-mail', 'address_change'],
        ['Recebemos sua mensagem', 'automatic'],
        ['Re: Vendas para tradings', 'reply_needs_review'],
        ['Automatic reply', 'auto_reply'],
        ['Redirecionamento de contato', 'routing'],
    ] as $index => [$subject, $kind]) {
        ecV23Notice($recipient, $index + 20, $subject, $kind, $index + 5);
    }
    ecV23Notice($recipient, 99, 'Mail delivery failed - mailbox full', 'automatic');

    $service = app(CampaignResponseFeed::class);
    expect($service->campaignTotals($recipient->email_campaign_id))
        ->toBe(['total' => 7, 'human' => 1, 'useful' => 6])
        ->and($service->globalTotals())->toBe(['total' => 7, 'human' => 1, 'useful' => 6])
        ->and(EmailCampaignReply::query()->count())->toBe(1)
        ->and($recipient->fresh()->replied_at)->toBeNull();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('ordena uma lista unica e pagina todas as respostas sem perder eventos', function (): void {
    $recipient = ecV23Recipient(ecV23NewCampaign('V23 Lista'));
    ecV23Human($recipient, 50, 40);
    ecV23Notice($recipient, 51, 'Comunicado de ferias', 'absence', 20);
    ecV23Notice($recipient, 52, 'E-mail alterado', 'address_change', 5);

    $service = app(CampaignResponseFeed::class);
    $first = $service->paginate($recipient->email_campaign_id, '', 1, 2);
    $second = $service->paginate($recipient->email_campaign_id, '', 2, 2);
    expect($first->total())->toBe(3)
        ->and($first->getCollection()->pluck('entry_kind')->all())->toBe(['notice', 'notice'])
        ->and($second->getCollection()->pluck('entry_kind')->all())->toBe(['human'])
        ->and($service->paginate(null, 'ferias')->total())->toBe(1);
});

it('nao conta duas vezes um uid IMAP presente em ambas as tabelas e ignora aviso promovido', function (): void {
    $recipient = ecV23Recipient(ecV23NewCampaign('V23 Sem duplicidade'));
    ecV23Human($recipient, 120);
    ecV23Notice($recipient, 120, 'Re: Vendas para tradings', 'reply_needs_review');
    ecV23Notice($recipient, 121, 'Comunicado de ferias', 'absence', 20, 1);

    $service = app(CampaignResponseFeed::class);
    expect($service->campaignTotals($recipient->email_campaign_id))
        ->toBe(['total' => 1, 'human' => 1, 'useful' => 0])
        ->and($service->paginate($recipient->email_campaign_id)->total())->toBe(1);
});

it('mostra os totais combinados na campanha e na pagina principal', function (): void {
    $campaign = ecV23NewCampaign('V23 Campanha principal');
    $recipient = ecV23Recipient($campaign);
    ecV23Human($recipient, 200);
    ecV23Notice($recipient, 201, 'Comunicado de ferias', 'absence');
    ecV23Notice($recipient, 202, 'Alteracao de e-mail', 'address_change');

    $user = ecV23Manager();
    $this->actingAs($user)->get(route('campaigns.index'))
        ->assertOk()->assertSee('1 comerciais · 2 retornos úteis');
    $this->actingAs($user)->get(route('campaigns.index', [
        'campanha' => $campaign->id, 'aba' => 'replies',
    ]))->assertOk()
        ->assertSee('data-ec-v23-unified-campaign="true"', false)
        ->assertSee('Todas as respostas')
        ->assertSee('Comunicado de ferias')
        ->assertSee('Re: Vendas para tradings')
        ->assertSee('Alteracao de e-mail');

    expect(app(CampaignResponseFeed::class)->campaignTotals($campaign->id)['total'])->toBe(3);
    Mail::assertNothingSent();
});

it('abre a caixa geral em todas e permite filtrar categorias anteriores', function (): void {
    $recipient = ecV23Recipient(ecV23NewCampaign('V23 Geral'));
    ecV23Human($recipient, 800);
    ecV23Notice($recipient, 801, 'Comunicado de ferias', 'absence');
    $manager = ecV23Manager();

    Livewire::actingAs($manager)->test('pages::campaigns.replies')
        ->assertSet('kind', 'all')
        ->assertSee('Todas as respostas')
        ->assertSee('Comunicado de ferias')
        ->assertSee('Re: Vendas para tradings')
        ->call('chooseKind', 'commercial')
        ->assertDontSee('Comunicado de ferias')
        ->call('chooseKind', 'notices')
        ->assertSee('Comunicado de ferias');
    Mail::assertNothingSent();
});

it('retorna os tres campos obrigatorios mesmo com campanha vazia ou com um unico tipo', function (): void {
    $empty = ecV23NewCampaign('V232 Vazia');
    $human = ecV23Recipient(ecV23NewCampaign('V232 Somente comercial'));
    ecV23Human($human, 1001);
    $useful = ecV23Recipient(ecV23NewCampaign('V232 Somente aviso'));
    ecV23Notice($useful, 1002, 'Comunicado de ferias', 'absence');

    $totals = app(CampaignResponseFeed::class)->totalsForCampaigns([
        (int) $empty->id, (int) $human->email_campaign_id,
        (int) $useful->email_campaign_id,
    ]);

    expect($totals[(int) $empty->id])->toBe(['total' => 0, 'human' => 0, 'useful' => 0])
        ->and($totals[(int) $human->email_campaign_id])->toBe(['total' => 1, 'human' => 1, 'useful' => 0])
        ->and($totals[(int) $useful->email_campaign_id])->toBe(['total' => 1, 'human' => 0, 'useful' => 1]);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
