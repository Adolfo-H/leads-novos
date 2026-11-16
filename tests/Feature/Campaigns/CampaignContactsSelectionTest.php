<?php

use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignContactsSelection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function ecV91Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('transfere somente os e-mails exatos dos contatos marcados, sem enviar e-mail', function (): void {
    $chosen = HubSpotContact::query()->create(['hubspot_id' => '9111001', 'first_name' => 'Escolhido', 'email' => 'escolhido@example.org']);
    HubSpotContact::query()->create(['hubspot_id' => '9111002', 'first_name' => 'Outro', 'email' => 'outro@example.org']);
    Mail::fake();
    Queue::fake();
    $manager = ecV91Manager();
    $response = $this->actingAs($manager)
        ->post(route('campaigns.from-contacts'), [
            'source_keys' => ['hubspot:'.$chosen->hubspot_id],
            'source_emails' => [
                'hubspot:'.$chosen->hubspot_id => 'escolhido@example.org',
                'hubspot:9111002' => 'outro@example.org',
            ],
        ])->assertRedirectContains('/campanhas/novo-v93?wizard_id=');
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    $wizardId = (string) ($query['wizard_id'] ?? '');
    expect($wizardId)->toMatch('/^[0-9a-f-]{36}$/i');
    $this->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId]))
        ->assertOk()
        ->assertSee('escolhido@example.org');
    expect(session('campaigns_wizard_v93_'.$wizardId)['expected'])
        ->toBe(['hubspot:9111001' => 'escolhido@example.org']);
    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('bloqueia uma transferência cujo e-mail foi trocado, sem colocar destinatários no rascunho', function (): void {
    HubSpotContact::query()->create(['hubspot_id' => '9111003', 'first_name' => 'Contato', 'email' => 'correto@example.org']);
    $this->actingAs(ecV91Manager())
        ->withSession(['campaign_mail_selected' => ['hubspot:9111004'], 'campaign_mail_expected_emails' => ['hubspot:9111004' => 'antigo@example.org']])
        ->post(route('campaigns.from-contacts'), [
            'source_keys' => ['hubspot:9111003'],
            'source_emails' => ['hubspot:9111003' => 'outro@example.org'],
        ])
        ->assertSessionHasErrors('recipients')
        ->assertSessionHas('campaign_mail_selected', ['hubspot:9111004'])
        ->assertSessionHas('campaign_mail_expected_emails', ['hubspot:9111004' => 'antigo@example.org']);
});

it('bloqueia números diferentes de contatos e e-mails duplicados em transferência de Contatos', function (): void {
    HubSpotContact::query()->create(['hubspot_id' => '9111004', 'email' => 'igual@example.org']);
    HubSpotContact::query()->create(['hubspot_id' => '9111005', 'email' => 'igual@example.org']);
    expect(fn () => app(CampaignContactsSelection::class)->validateExact(
        ['hubspot:9111004', 'hubspot:9111005'],
        ['hubspot:9111004' => 'igual@example.org', 'hubspot:9111005' => 'igual@example.org'],
    ))->toThrow(ValidationException::class);
});

it('abre Nova campanha sem depender de ação Livewire e não cria rascunho ao abrir', function (): void {
    Mail::fake();
    Queue::fake();
    $this->actingAs(ecV91Manager())
        ->followingRedirects()
        ->get(route('campaigns.index', ['nova' => 1]))
        ->assertOk()
        ->assertSee('Escolha os contatos');
    expect(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
