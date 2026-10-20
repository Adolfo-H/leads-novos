<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\HubSpotContact;
use App\Models\User;
use Livewire\Livewire;

function campaignWorkspaceV2Manager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

it('displays the new operational campaigns workspace with existing shared layout', function (): void {
    config()->set('campaign_mail.live_enabled', false);

    $this->actingAs(campaignWorkspaceV2Manager())
        ->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Minhas campanhas')
        ->assertSee('Começar campanha')
        ->assertSee('Configuração de envio')
        ->assertSee('Modo seguro');

    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/campaigns-workspace-v2.css'));

    expect($view)->toContain('ecm2-shell')
        ->toContain('ecm2-context')
        ->toContain('wire:click="newCampaign"')
        ->toContain('wire:click="openCampaign(')
        ->and($css)->toContain('.ecm2-shell');
});

it('opens the newest existing campaign as soon as manager enters the module', function (): void {
    $manager = campaignWorkspaceV2Manager();
    $old = EmailCampaign::query()->create([
        'name' => 'Campanha antiga', 'subject' => 'Assunto antigo',
        'body' => 'Conteúdo completo da campanha antiga.', 'status' => 'draft',
        'from_address' => 'adolfo@exportcontrol.com.br', 'from_name' => 'Adolfo | ExportControl',
        'reply_to' => 'adolfo@exportcontrol.com.br', 'created_by' => $manager->id,
    ]);
    $latest = EmailCampaign::query()->create([
        'name' => 'Campanha mais recente', 'subject' => 'Assunto novo',
        'body' => 'Conteúdo completo da campanha recente.', 'status' => 'draft',
        'from_address' => 'adolfo@exportcontrol.com.br', 'from_name' => 'Adolfo | ExportControl',
        'reply_to' => 'adolfo@exportcontrol.com.br', 'created_by' => $manager->id,
    ]);
    EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $latest->id,
        'email' => 'teste@empresa.com.br',
        'normalized_email' => 'teste@empresa.com.br',
        'status' => 'pending',
    ]);

    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->assertSet('screen', 'overview')
        ->assertSet('selectedCampaignId', $latest->id)
        ->assertSet('detailTab', 'overview')
        ->assertSee('Campanha mais recente')
        // EC_SIMPLE_CAMPAIGNS_WORKSPACE_TEST: detalhes só após escolha explícita.
        ->call('openCampaign', $latest->id)
        ->assertSee('Ações rápidas')
        ->assertSee('Na fila')
        ->call('openCampaign', $old->id)
        ->assertSet('selectedCampaignId', $old->id);
});

it('guides creation through recipients message and review without sending email', function (): void {
    $manager = campaignWorkspaceV2Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '99220001', 'first_name' => 'Marina',
        'last_name' => 'Exportação', 'email' => 'marina@empresa.com.br',
    ]);

    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->call('newCampaign')
        ->assertSet('screen', 'composer')
        ->assertSet('composerStep', 'audience')
        ->call('goToComposerStep', 'message')
        ->assertSet('composerStep', 'audience')
        ->assertHasErrors(['recipients'])
        ->set('audienceKeys', ['hubspot:99220001'])
        ->set('name', 'Nova prospecção comercial')
        ->call('goToComposerStep', 'message')
        ->assertSet('composerStep', 'message')
        ->set('subject', 'Uma apresentação da ExportControl')
        ->set('body', 'Olá, {{primeiro_nome}}! Podemos conversar sobre suas operações de exportação?')
        ->call('goToComposerStep', 'review')
        ->assertSet('composerStep', 'review')
        ->assertSee('Salvar rascunho');

    expect(EmailCampaign::query()->count())->toBe(0);
});

it('shows only true stored SMTP acceptance counts and never fabricates delivered opens', function (): void {
    $manager = campaignWorkspaceV2Manager();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha revisão SMTP', 'subject' => 'Assunto',
        'body' => 'Mensagem comercial completa.', 'status' => 'paused',
        'from_address' => 'adolfo@exportcontrol.com.br', 'from_name' => 'Adolfo',
        'reply_to' => 'adolfo@exportcontrol.com.br', 'created_by' => $manager->id,
    ]);
    foreach (['sent', 'pending', 'uncertain'] as $index => $status) {
        EmailCampaignRecipient::query()->create([
            'email_campaign_id' => $campaign->id,
            'email' => 'contato'.$index.'@empresa.com.br',
            'normalized_email' => 'contato'.$index.'@empresa.com.br',
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    // EC_SIMPLE_UX_DETAILS_EXPLICIT_OPEN_V1: os números completos são
    // exibidos ao escolher uma campanha, não na visão inicial.
    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->assertSet('screen', 'overview')
        ->call('openCampaign', $campaign->id)
        ->assertSet('screen', 'detail')
        ->assertSee('1 de 3 destinatário(s)')
        ->assertSee('33%')
        ->assertSee('Mensagens aceitas pelo servidor não significam entrega')
        ->assertSee('Não confirmados');
});

it('keeps the genuine API safety lock when live campaigns are disabled', function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    $manager = campaignWorkspaceV2Manager();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Rascunho protegido', 'subject' => 'Apresentação',
        'body' => 'Mensagem comercial completa.', 'status' => 'draft',
        'from_address' => 'adolfo@exportcontrol.com.br', 'from_name' => 'Adolfo',
        'reply_to' => 'adolfo@exportcontrol.com.br', 'created_by' => $manager->id,
    ]);
    EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'outra@empresa.com.br', 'normalized_email' => 'outra@empresa.com.br',
        'status' => 'pending',
    ]);

    $this->actingAs($manager)->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Modo seguro');

    // O aviso detalhado e o botão de início ficam somente no rascunho aberto.
    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->assertSet('screen', 'overview')
        ->call('openCampaign', $campaign->id)
        ->assertSet('screen', 'detail')
        ->assertSee('Envio real bloqueado')
        ->assertDontSee('Envios reais habilitados');

    expect($campaign->fresh()->status)->toBe('draft');
});
