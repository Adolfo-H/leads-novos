<?php

use App\Models\CommercialContact;
use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

function campaignWizardV93Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

it('abre o assistente sem Livewire e filtra a origem por URL GET', function (): void {
    $user = campaignWizardV93Manager();
    $wizardId = '93b30000-0000-4000-8000-000000000001';
    HubSpotContact::query()->create(['hubspot_id' => '931010', 'first_name' => 'Ana', 'email' => 'ana@hubspot.teste']);
    CommercialContact::query()->create(['name' => 'Marcos', 'email' => 'marcos@manual.teste', 'created_by' => $user->id]);

    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1, 'origem' => 'hubspot']))
        ->assertOk()->assertSee('ana@hubspot.teste')->assertDontSee('marcos@manual.teste');
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'origem' => 'manual']))
        ->assertOk()->assertSee('marcos@manual.teste')->assertDontSee('ana@hubspot.teste');
});

it('faz busca real via GET sem depender de eventos Livewire', function (): void {
    $user = campaignWizardV93Manager();
    $wizardId = '93b30000-0000-4000-8000-000000000002';
    HubSpotContact::query()->create(['hubspot_id' => '931021', 'first_name' => 'Ana', 'email' => 'ana@agraria.teste']);
    HubSpotContact::query()->create(['hubspot_id' => '931022', 'first_name' => 'Luiza', 'email' => 'luiza@cooperativa.teste']);

    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1, 'busca' => 'agraria']))
        ->assertOk()->assertSee('ana@agraria.teste')->assertDontSee('luiza@cooperativa.teste');
});

it('seleciona um e-mail exato, avança pelas etapas e salva APENAS rascunho', function (): void {
    Mail::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.max_recipients', 100);
    $user = campaignWizardV93Manager();
    $wizardId = '93b30000-0000-4000-8000-000000000003';
    HubSpotContact::query()->create(['hubspot_id' => '931030', 'first_name' => 'Ana', 'email' => 'ana@empresa.teste']);
    HubSpotContact::query()->create(['hubspot_id' => '931031', 'first_name' => 'Bia', 'email' => 'bia@empresa.teste']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1]))->assertOk();

    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $wizardId, 'action' => 'continue', 'origem' => 'all',
        'visible' => ['hubspot:931030', 'hubspot:931031'],
        'keys' => ['hubspot:931030'],
        'expected' => ['hubspot:931030' => 'ana@empresa.teste', 'hubspot:931031' => 'bia@empresa.teste'],
    ])->assertRedirect(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']));

    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']))
        ->assertOk()->assertSee('Escreva a mensagem');

    $this->actingAs($user)->post(route('campaigns.wizard-v93.message'), [
        'wizard_id' => $wizardId,
        'name' => 'Teste V93', 'subject' => 'Apresentação da ExportControl',
        'body' => 'Olá, gostaria de apresentar nossas soluções de exportação.',
    ])->assertRedirect(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'ritmo']));
    $this->actingAs($user)->post(route('campaigns.wizard-v93.pace'), ['wizard_id' => $wizardId, 'hours' => 8])
        ->assertRedirect(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'revisao']));
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'revisao']))
        ->assertOk()->assertSee('Revise antes de salvar');
    $this->actingAs($user)->post(route('campaigns.wizard-v93.save'), ['wizard_id' => $wizardId])->assertRedirect();

    $campaign = EmailCampaign::query()->where('name', 'Teste V93')->firstOrFail();
    expect($campaign->status)->toBe('draft');
    expect($campaign->recipients()->count())->toBe(1);
    expect($campaign->recipients()->firstOrFail()->normalized_email)->toBe('ana@empresa.teste');
    Mail::assertNothingSent();
});

it('bloqueia destinatario cujo e-mail mudou apos a selecao', function (): void {
    $user = campaignWizardV93Manager();
    $wizardId = '93b30000-0000-4000-8000-000000000004';
    HubSpotContact::query()->create(['hubspot_id' => '931041', 'first_name' => 'Ana', 'email' => 'novo@empresa.teste']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1]))->assertOk();
    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $wizardId, 'action' => 'continue', 'visible' => ['hubspot:931041'], 'keys' => ['hubspot:931041'],
        'expected' => ['hubspot:931041' => 'antigo@empresa.teste'],
    ])->assertSessionHasErrors('recipients');
    expect(EmailCampaign::query()->count())->toBe(0);
});

it('preserva busca, origem e pagina ao remover um contato selecionado', function (): void {
    $user = campaignWizardV93Manager();
    $wizardId = '93b30000-0000-4000-8000-000000000005';
    HubSpotContact::query()->create(['hubspot_id' => '931051', 'first_name' => 'Ana', 'email' => 'ana@empresa.teste']);
    HubSpotContact::query()->create(['hubspot_id' => '931052', 'first_name' => 'Bia', 'email' => 'bia@empresa.teste']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'reiniciar' => 1]))->assertOk();
    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $wizardId,
        'visible' => ['hubspot:931051', 'hubspot:931052'],
        'keys' => ['hubspot:931051', 'hubspot:931052'],
        'expected' => [
            'hubspot:931051' => 'ana@empresa.teste',
            'hubspot:931052' => 'bia@empresa.teste',
        ],
    ])->assertRedirect();

    $this->actingAs($user)->post(route('campaigns.wizard-v93.remove'), [
        'wizard_id' => $wizardId,
        'key' => 'hubspot:931052',
        'origem' => 'hubspot',
        'busca' => 'ana',
        'pagina' => 2,
    ])->assertRedirect(route('campaigns.wizard-v93', [
        'wizard_id' => $wizardId,
        'origem' => 'hubspot',
        'busca' => 'ana',
        'pagina' => 2,
    ]));

    expect(session('campaigns_wizard_v93_'.$wizardId)['expected'])
        ->toBe(['hubspot:931051' => 'ana@empresa.teste']);
});

it('isola seleções e conteúdo entre duas abas do assistente', function (): void {
    $user = campaignWizardV93Manager();
    $firstWizard = '93b30000-0000-4000-8000-000000000011';
    $secondWizard = '93b30000-0000-4000-8000-000000000012';
    HubSpotContact::query()->create(['hubspot_id' => '931051', 'first_name' => 'Ana', 'email' => 'ana@primeira.teste']);
    HubSpotContact::query()->create(['hubspot_id' => '931052', 'first_name' => 'Bia', 'email' => 'bia@segunda.teste']);

    foreach ([$firstWizard, $secondWizard] as $wizardId) {
        $this->actingAs($user)->get(route('campaigns.wizard-v93', [
            'wizard_id' => $wizardId, 'reiniciar' => 1,
        ]))->assertOk();
    }
    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $firstWizard, 'action' => 'add',
        'visible' => ['hubspot:931051'], 'keys' => ['hubspot:931051'],
        'expected' => ['hubspot:931051' => 'ana@primeira.teste'],
    ])->assertRedirect();
    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $secondWizard, 'action' => 'add',
        'visible' => ['hubspot:931052'], 'keys' => ['hubspot:931052'],
        'expected' => ['hubspot:931052' => 'bia@segunda.teste'],
    ])->assertRedirect();

    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $firstWizard]))
        ->assertOk()->assertSee('ana@primeira.teste');
    expect(session('campaigns_wizard_v93_'.$firstWizard)['expected'])
        ->toBe(['hubspot:931051' => 'ana@primeira.teste']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $secondWizard]))
        ->assertOk()->assertSee('bia@segunda.teste');
    expect(session('campaigns_wizard_v93_'.$secondWizard)['expected'])
        ->toBe(['hubspot:931052' => 'bia@segunda.teste']);
});

it('recusa acesso ao assistente de vendedor sem permissao de gestor', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('campaigns.wizard-v93'))->assertForbidden();
    $this->actingAs($seller)->post(route('campaigns.wizard-v93.save'))->assertForbidden();
});
