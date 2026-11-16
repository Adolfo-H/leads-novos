<?php

use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignContactTransfer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function transferManagerV92(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('transfere via POST apenas o e-mail marcado e abre o assistente sem envio', function (): void {
    Mail::fake();
    config()->set('campaign_mail.max_recipients', 100);
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    $manager = transferManagerV92();
    HubSpotContact::query()->create(['hubspot_id' => '921001', 'first_name' => 'Ana', 'email' => 'ana@cooperativa.teste']);
    HubSpotContact::query()->create(['hubspot_id' => '921002', 'first_name' => 'Bia', 'email' => 'bia@cooperativa.teste']);
    $token = (string) Str::uuid();
    $snapshots = ['hubspot:921001' => 'ana@cooperativa.teste'];

    $this->actingAs($manager)
        ->withSession(['campaign_contact_transfer_v92_'.$token => $snapshots])
        ->post(route('campaigns.contacts-transfer-v92'), [
            'campaign_transfer_token' => $token,
            'campaign_contacts_v92' => ['hubspot:921001'],
            'campaign_emails_v92' => $snapshots,
        ])
        ->assertRedirect(route('campaigns.wizard-v93', ['wizard_id' => strtolower($token)]));

    expect(session('campaign_transfer_pending_v92_'.strtolower($token)))
        ->toBe(['hubspot:921001' => 'ana@cooperativa.teste']);
    $this->actingAs($manager)->get(route('campaigns.wizard-v93', ['wizard_id' => strtolower($token)]))
        ->assertOk()
        ->assertSee('Selecionar contatos')
        ->assertSee('Destinatários selecionados');
    expect(session('campaigns_wizard_v93_'.strtolower($token))['expected'])
        ->toBe(['hubspot:921001' => 'ana@cooperativa.teste']);
    Mail::assertNothingSent();
});

it('bloqueia e-mail que mudou no HubSpot sem substituir destinatario', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    $manager = transferManagerV92();
    HubSpotContact::query()->create(['hubspot_id' => '921011', 'first_name' => 'Ana', 'email' => 'atual@empresa.teste']);
    $token = (string) Str::uuid();
    $snapshots = ['hubspot:921011' => 'antigo@empresa.teste'];
    $this->actingAs($manager)
        ->withSession(['campaign_contact_transfer_v92_'.$token => $snapshots])
        ->post(route('campaigns.contacts-transfer-v92'), [
            'campaign_transfer_token' => $token,
            'campaign_contacts_v92' => ['hubspot:921011'],
            'campaign_emails_v92' => $snapshots,
        ])->assertSessionHasErrors('recipients');
    expect(session('campaign_transfer_pending_v92_'.strtolower($token)))->toBeNull();
});

it('nao transfere contatos repetidos ou sem email valido', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    $manager = transferManagerV92();
    HubSpotContact::query()->create(['hubspot_id' => '921021', 'first_name' => 'João', 'email' => 'joao@empresa.teste']);
    $token = (string) Str::uuid();
    $snapshots = ['hubspot:921021' => 'joao@empresa.teste'];
    $this->actingAs($manager)
        ->withSession(['campaign_contact_transfer_v92_'.$token => $snapshots])
        ->post(route('campaigns.contacts-transfer-v92'), [
            'campaign_transfer_token' => $token,
            'campaign_contacts_v92' => ['hubspot:921021', 'hubspot:921021'],
            'campaign_emails_v92' => $snapshots,
        ])->assertSessionHasErrors('recipients');
});

it('nao permite ao vendedor iniciar campanha a partir do POST nativo', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $token = (string) Str::uuid();
    $this->actingAs($seller)->post(route('campaigns.contacts-transfer-v92'), [
        'campaign_transfer_token' => $token,
        'campaign_contacts_v92' => ['hubspot:921030'],
        'campaign_emails_v92' => ['hubspot:921030' => 'teste@empresa.teste'],
    ])->assertForbidden();
});

it('rejeita uma selecao sem snapshot de servidor', function (): void {
    $manager = transferManagerV92();
    $token = (string) Str::uuid();
    $this->actingAs($manager)->post(route('campaigns.contacts-transfer-v92'), [
        'campaign_transfer_token' => $token,
        'campaign_contacts_v92' => ['hubspot:921031'],
        'campaign_emails_v92' => ['hubspot:921031' => 'teste@empresa.teste'],
    ])->assertSessionHasErrors('recipients');
    expect(session('campaign_transfer_pending_v92_'.strtolower($token)))->toBeNull();
});

it('protege snapshot original antes de salvar o rascunho', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    HubSpotContact::query()->create(['hubspot_id' => '921041', 'first_name' => 'João', 'email' => 'correto@empresa.teste']);
    $transfer = app(CampaignContactTransfer::class);
    $transfer->guard(['hubspot:921041'], ['hubspot:921041' => 'correto@empresa.teste']);
    expect(fn () => $transfer->guard(['hubspot:921041'], ['hubspot:921041' => 'trocado@empresa.teste']))
        ->toThrow(ValidationException::class);
});
