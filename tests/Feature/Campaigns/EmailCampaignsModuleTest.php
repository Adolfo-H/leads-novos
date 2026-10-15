<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Mail\ProspectorCampaignMessage;
use App\Models\CommercialContact;
use App\Models\EmailCampaignSuppression;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignManager;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

function emailCampaignManager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function emailCampaignSeller(): User
{
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    return $seller;
}

function emailCampaignConfig(): void
{
    config()->set('campaign_mail.from_address', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.reply_to', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.from_name', 'Adolfo | ExportControl');
    config()->set('campaign_mail.test_to', 'adolfo@exportcontrol.com.br');
    config()->set('campaign_mail.max_recipients', 100);
    config()->set('app.url', 'https://prospector.exportcontrol.com.br');
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
}

it('shows dark campaigns workspace only to authenticated commercial managers', function (): void {
    emailCampaignConfig();
    $this->get(route('campaigns.index'))->assertRedirect();
    $this->actingAs(emailCampaignSeller())->get(route('campaigns.index'))->assertForbidden();

    $response = $this->actingAs(emailCampaignManager())->get(route('campaigns.index'));
    $response->assertOk()->assertSee('Campanhas de E-mail')
        ->assertSee('Modo seguro')->assertSee('Nova campanha');
});

it('lists genuine HubSpot and manual email contacts without inventing Receita people', function (): void {
    emailCampaignConfig();
    HubSpotContact::query()->create([
        'hubspot_id' => '800001', 'first_name' => 'Ana', 'last_name' => 'Comercial',
        'email' => ' ANA@EMPRESA.COM.BR ', 'company_name' => 'Empresa informada pelo CRM',
    ]);
    CommercialContact::query()->create([
        'name' => 'Marcos', 'email' => 'marcos@empresa.com.br', 'created_by' => emailCampaignManager()->id,
    ]);

    $list = app(CampaignAudience::class)->paginate('', 'all');
    expect($list->total())->toBe(2);
    $values = app(CampaignAudience::class)->resolve(['hubspot:800001', 'manual:1', 'hubspot:999999']);
    expect($values)->toHaveCount(2);
    expect($values[0]['normalized_email'])->toBe('ana@empresa.com.br');
    expect($values[0]['company_name'])->toBe('Empresa informada pelo CRM');
});

it('creates a draft with unique addresses, excluding suppression without sending mail', function (): void {
    emailCampaignConfig();
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800002', 'first_name' => 'João', 'email' => 'joao@empresa.com.br']);
    CommercialContact::query()->create(['name' => 'Joao repetido', 'email' => 'JOAO@empresa.com.br', 'created_by' => $user->id]);
    CommercialContact::query()->create(['name' => 'Maria', 'email' => 'maria@empresa.com.br', 'created_by' => $user->id]);
    EmailCampaignSuppression::query()->create(['normalized_email' => 'maria@empresa.com.br', 'reason' => 'unsubscribe']);
    Mail::fake();

    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Cooperativas do PR', 'subject' => 'Olá, {{primeiro_nome}}',
        'body' => 'Este é um texto comercial de apresentação da ExportControl.',
    ], ['hubspot:800002', 'manual:1', 'manual:2']);

    expect($campaign->status)->toBe('draft');
    expect($campaign->recipients()->count())->toBe(2);
    expect($campaign->recipients()->where('status', 'pending')->count())->toBe(1);
    expect($campaign->recipients()->where('status', 'suppressed')->count())->toBe(1);
    Mail::assertNothingSent();
});

it('never starts a live campaign while feature flag is disabled', function (): void {
    emailCampaignConfig();
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800003', 'first_name' => 'Leo', 'email' => 'leo@empresa.com.br']);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Teste seguro', 'subject' => 'Olá', 'body' => 'Este é um e-mail de apresentação comercial.',
    ], ['hubspot:800003']);

    expect(fn () => app(CampaignManager::class)->start($user, $campaign, true))
        ->toThrow(ValidationException::class);
    expect($campaign->fresh()->status)->toBe('draft');
});

it('can test SMTP only to configured own mailbox without sending to selected contacts', function (): void {
    emailCampaignConfig();
    config()->set('campaign_mail.enabled', true);
    $user = emailCampaignManager();
    RateLimiter::clear('campaigns-test:'.$user->id);
    Mail::fake();

    app(CampaignManager::class)->sendTest($user, 'Olá {{nome}}', 'Mensagem teste para {{empresa}} com mais de dez caracteres.');
    Mail::assertSent(ProspectorCampaignMessage::class, function (ProspectorCampaignMessage $message): bool {
        return $message->hasTo('adolfo@exportcontrol.com.br')
            && str_starts_with($message->mailSubject, '[TESTE] ')
            && $message->unsubscribeUrl === null;
    });
});

it('reserves one approved contact with daily limits and sends via the dedicated queue', function (): void {
    emailCampaignConfig();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.per_tick', 1);
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800004', 'first_name' => 'Paula', 'email' => 'paula@empresa.com.br']);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Primeira ação', 'subject' => 'Olá {{primeiro_nome}}',
        'body' => 'Mensagem personalizada para {{empresa}} sobre as soluções ExportControl.',
    ], ['hubspot:800004']);
    app(CampaignManager::class)->start($user, $campaign, true);
    Queue::fake();

    expect(app(CampaignDispatcher::class)->tick())->toBe(1);
    expect(app(CampaignDispatcher::class)->tick())->toBe(0);
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    expect($campaign->recipients()->first()->status)->toBe('reserved');
});

it('sends approved recipients with Message-ID and unsubscribe token, only once', function (): void {
    emailCampaignConfig();
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800005', 'first_name' => 'Bruna', 'email' => 'bruna@empresa.com.br']);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Campanha envio', 'subject' => 'Olá {{primeiro_nome}}',
        'body' => 'Prezada {{nome}}, podemos conversar sobre o controle de exportações?',
    ], ['hubspot:800005']);
    app(CampaignManager::class)->start($user, $campaign, true);
    Queue::fake();
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    Mail::fake();
    $job = new SendEmailCampaignRecipient((int) $recipient->id);
    $job->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));
    $job->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));

    expect($recipient->fresh()->status)->toBe('sent');
    expect($recipient->fresh()->message_id)->toContain('@exportcontrol.com.br');
    Mail::assertSent(ProspectorCampaignMessage::class, 1);
    Mail::assertSent(ProspectorCampaignMessage::class, fn (ProspectorCampaignMessage $m): bool => $m->hasTo('bruna@empresa.com.br') && $m->mailSubject === 'Olá Bruna' && $m->unsubscribeUrl !== null);
});

it('provides a public confirmation page and suppresses address only after POST', function (): void {
    emailCampaignConfig();
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800006', 'first_name' => 'Lia', 'email' => 'lia@empresa.com.br']);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Descadastro', 'subject' => 'Apresentação', 'body' => 'Mensagem de apresentação comercial ExportControl.',
    ], ['hubspot:800006']);
    $recipient = $campaign->recipients()->firstOrFail();
    $token = app(UnsubscribeToken::class)->forRecipient($recipient);
    $this->get(route('campaigns.unsubscribe', ['token' => $token]))->assertOk();
    expect(EmailCampaignSuppression::query()->count())->toBe(0);
    $this->post(route('campaigns.unsubscribe.store', ['token' => $token]))->assertRedirect();
    expect(EmailCampaignSuppression::query()->where('normalized_email', 'lia@empresa.com.br')->exists())->toBeTrue();
    expect($recipient->fresh()->status)->toBe('suppressed');
    $this->get(route('campaigns.unsubscribe', ['token' => $token.'x']))->assertNotFound();
});

it('matches IMAP replies only by original Message-ID and the same sender address', function (): void {
    emailCampaignConfig();
    $user = emailCampaignManager();
    HubSpotContact::query()->create(['hubspot_id' => '800007', 'first_name' => 'Ana', 'email' => 'ana@empresa.com.br']);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Resposta', 'subject' => 'Uma apresentação', 'body' => 'Mensagem comercial de teste de correlação IMAP.',
    ], ['hubspot:800007']);
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['message_id' => 'abc123@exportcontrol.com.br', 'attempted_at' => now(), 'status' => 'sent']);
    $batch = [
        'uidvalidity' => '1234', 'last_uid' => 2085, 'initialized' => false,
        'messages' => [
            ['uid' => 2083, 'from_email' => 'outra@empresa.com.br', 'in_reply_to' => '<abc123@exportcontrol.com.br>', 'references' => '', 'subject' => 'Re:', 'received_at' => now()->toIso8601String()],
            ['uid' => 2084, 'from_email' => 'ana@empresa.com.br', 'in_reply_to' => '<abc123@exportcontrol.com.br>', 'references' => '', 'subject' => 'Re: Uma apresentação', 'received_at' => now()->toIso8601String()],
            ['uid' => 2085, 'from_email' => 'ana@empresa.com.br', 'in_reply_to' => '', 'references' => '', 'subject' => 'Assunto semelhante', 'received_at' => now()->toIso8601String()],
        ],
    ];

    expect(app(CampaignInbox::class)->import('adolfo@exportcontrol.com.br', $batch))->toBe(1);
    expect(app(CampaignInbox::class)->import('adolfo@exportcontrol.com.br', $batch))->toBe(0);
    expect($recipient->fresh()->replied_at)->not->toBeNull();
    expect($campaign->replies()->count())->toBe(1);
});

it('connects the Contacts selection button to actual campaigns instead of mailto BCC', function (): void {
    $code = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    expect($code)->toContain('createCampaignFromSelected')->not->toContain('mailto:?bcc=');
    expect($sidebar)->toContain('campaigns.index');
});
