<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignReply;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function ecV62Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function ecV62Reply(string $email, string $subject): EmailCampaignReply
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'UX V6.2 — Teste',
        'subject' => 'Assunto para teste',
        'body' => 'Mensagem fictícia sem disparos reais.',
        'status' => 'draft',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);
    $recipient = $campaign->recipients()->create([
        'email' => $email,
        'normalized_email' => $email,
        'status' => 'sent',
    ]);

    return EmailCampaignReply::query()->create([
        'email_campaign_id' => $campaign->id,
        'email_campaign_recipient_id' => $recipient->id,
        'mailbox' => 'teste@example.invalid',
        'uid_validity' => '8765',
        'imap_uid' => EmailCampaignReply::query()->count() + 100,
        'from_email' => $email,
        'subject' => $subject,
        'received_at' => now('UTC'),
    ]);
}

it('shows unified UX tabs only to an authenticated manager', function (): void {
    foreach (['campaigns.index', 'campaigns.replies', 'campaigns.queue', 'campaigns.settings'] as $route) {
        $this->get(route($route))->assertRedirect();
    }

    $this->actingAs(ecV62Manager());
    foreach (['campaigns.replies', 'campaigns.queue', 'campaigns.settings'] as $route) {
        $this->get(route($route))->assertOk()
            // SIMPLE_CAMPAIGN_NAV_TEST_2026: navegação com três itens.
            ->assertSee('Respostas')
            ->assertSee('Configurações gerais');
    }
});

it('filters only already-tracked commercial replies without querying unrelated messages', function (): void {
    $user = ecV62Manager();
    ecV62Reply('interesse@cliente.example', 'Re: Quero uma demonstracao');
    ecV62Reply('outro@empresa.example', 'Re: Outro assunto');

    Mail::fake();
    Queue::fake();

    Livewire::actingAs($user)->test('pages::campaigns.replies')
        ->assertSee('interesse@cliente.example')
        ->assertSee('outro@empresa.example')
        ->set('search', 'interesse@cliente')
        ->assertSee('interesse@cliente.example')
        ->assertDontSee('outro@empresa.example')
        ->set('search', 'semresultado')
        ->assertSee('Nenhuma conversa encontrada')
        ->assertDontSee('interesse@cliente.example');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps commercial sending disabled during pure UX navigation', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    $this->actingAs(ecV62Manager());

    $this->get(route('campaigns.queue'))->assertOk()->assertSee('Envios comerciais bloqueados');
    $this->get(route('campaigns.settings'))->assertOk()->assertSee('Envios comerciais bloqueados');
    expect(config('campaign_mail.live_enabled'))->toBeFalse();
});
