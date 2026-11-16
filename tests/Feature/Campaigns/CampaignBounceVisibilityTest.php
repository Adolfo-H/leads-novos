<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignBounce;
use App\Models\User;
use App\Services\Campaigns\CampaignBounceReason;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('traduz códigos DSN para motivos legíveis sem decidir supressão', function (): void {
    expect(CampaignBounceReason::label('5.1.1'))->toBe('Endereço de e-mail inexistente')
        ->and(CampaignBounceReason::label('4.2.2'))->toBe('Caixa de e-mail cheia')
        ->and(CampaignBounceReason::label('5.2.2'))->toBe('Caixa de e-mail cheia')
        ->and(CampaignBounceReason::label('5.1.3'))->toBe('Formato de endereço inválido')
        ->and(CampaignBounceReason::label('5.7.1'))->toBe('Bloqueado pelo servidor destinatário')
        ->and(CampaignBounceReason::label('4.7.1'))->toBe('Bloqueio ou limite temporário do servidor')
        ->and(CampaignBounceReason::label('5.0.0'))->toBe('Mensagem devolvida pelo servidor');
});

it('mostra devoluções de destinatários comerciais dentro da campanha sem enviar mensagens', function (): void {
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha com devolução',
        'subject' => 'Apresentação',
        'body' => 'Este é um conteúdo de teste para não enviar mensagem.',
        'status' => 'completed',
        'from_address' => 'teste@example.org',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.org',
    ]);
    $recipient = $campaign->recipients()->create([
        'email' => 'cheia@example.org',
        'normalized_email' => 'cheia@example.org',
        'contact_name' => 'Contato de teste',
        'status' => 'sent',
        'attempted_at' => now('UTC'),
        'sent_at' => now('UTC'),
        'message_id' => 'test-a29@example.org',
    ]);
    EmailCampaignBounce::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'inbox@example.org',
        'uid_validity' => '100',
        'imap_uid' => 7,
        'final_recipient' => 'cheia@example.org',
        'status_code' => '4.2.2',
        'action' => 'delayed',
        'classification' => 'temporary',
        'diagnostic' => 'smtp; 452 mailbox full',
        'received_at' => now('UTC'),
    ]);
    // Teste piloto: registrado, mas nunca contado como falha de destinatário comercial.
    EmailCampaignBounce::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => null,
        'mailbox' => 'inbox@example.org',
        'uid_validity' => '100',
        'imap_uid' => 8,
        'final_recipient' => 'piloto@example.org',
        'status_code' => '5.1.1',
        'action' => 'failed',
        'classification' => 'invalid',
        'diagnostic' => 'smtp; test',
        'received_at' => now('UTC'),
    ]);

    $page = Livewire::actingAs($manager)
        ->test('pages::campaigns.index')
        ->call('openCampaign', $campaign->id)
        ->assertSee('1 devolução')
        ->assertSee('Ver devoluções')
        ->set('detailTab', 'recipients')
        ->assertSee('Caixa de e-mail cheia')
        ->set('detailTab', 'bounces')
        ->assertSee('cheia@example.org')
        ->assertSee('4.2.2')
        ->assertSee('452 mailbox full');

    expect($page->get('selectedCampaignId'))->toBe($campaign->id);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
