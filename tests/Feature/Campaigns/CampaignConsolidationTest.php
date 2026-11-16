<?php

use App\Models\EmailCampaign;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignManager;
use Illuminate\Support\Facades\Mail;

function campaignV94Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('redireciona o antigo nova=1 para o unico assistente em vez de abrir duas telas', function (): void {
    $user = campaignV94Manager();

    $this->actingAs($user)->get(route('campaigns.index', ['nova' => 1]))
        ->assertRedirect(route('campaigns.wizard-v93', [
            'reiniciar' => '1', 'modelo' => 'initial', 'origem' => 'all',
        ]));

    $this->actingAs($user)->followingRedirects()
        ->get(route('campaigns.wizard-v93', ['reiniciar' => 1]))
        ->assertOk()->assertSee('Escolha os contatos');
});

it('preserva a selecao de email ao mudar a origem por POST sem disparar SMTP', function (): void {
    Mail::fake();
    $user = campaignV94Manager();
    $wizardId = '94c40000-0000-4000-8000-000000000001';
    config()->set('campaign_mail.enabled', false);
    HubSpotContact::query()->create([
        'hubspot_id' => '941001', 'first_name' => 'Ana', 'email' => 'ana94@exportcontrol.teste',
    ]);

    $this->actingAs($user)->get(route('campaigns.wizard-v93', [
        'wizard_id' => $wizardId, 'reiniciar' => 1,
    ]))->assertOk();
    $this->actingAs($user)->post(route('campaigns.wizard-v93.selection'), [
        'wizard_id' => $wizardId,
        'visible' => ['hubspot:941001'],
        'keys' => ['hubspot:941001'],
        'expected' => ['hubspot:941001' => 'ana94@exportcontrol.teste'],
        'origem' => 'all', 'busca' => '', 'next_origin' => 'manual',
    ])->assertRedirect(route('campaigns.wizard-v93', [
        'wizard_id' => $wizardId, 'origem' => 'manual', 'busca' => '', 'pagina' => 1,
    ]));

    expect(session('campaigns_wizard_v93_'.$wizardId)['expected'])
        ->toBe(['hubspot:941001' => 'ana94@exportcontrol.teste']);
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'origem' => 'hubspot']))
        ->assertOk()->assertSee('ana94@exportcontrol.teste')->assertSee('data-v94-selection-count');
    Mail::assertNothingSent();
});

it('edita o mesmo rascunho sem gerar campanha nova e sem enviar email', function (): void {
    Mail::fake();
    config()->set('campaign_mail.enabled', false);
    $user = campaignV94Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '941002', 'first_name' => 'Bia', 'email' => 'bia94@exportcontrol.teste',
    ]);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Rascunho antes V94',
        'subject' => 'Assunto da campanha V94',
        'body' => 'Conteúdo de teste da campanha que não deve ser enviado automaticamente.',
    ], ['hubspot:941002']);

    $this->actingAs($user)->get(route('campaigns.wizard-v93', [
        'wizard_id' => '94c40000-0000-4000-8000-000000000002',
        'reiniciar' => 1, 'editar' => $campaign->id,
    ]))->assertOk()->assertSee('Editar rascunho')->assertSee('bia94@exportcontrol.teste');
    $this->actingAs($user)->post(route('campaigns.wizard-v93.message'), [
        'wizard_id' => '94c40000-0000-4000-8000-000000000002',
        'name' => 'Rascunho depois V94', 'subject' => 'Assunto atualizado da V94',
        'body' => 'Mensagem atualizada sem enviar nenhum email ao destinatário da campanha.',
    ])->assertRedirect(route('campaigns.wizard-v93', [
        'wizard_id' => '94c40000-0000-4000-8000-000000000002', 'passo' => 'ritmo',
    ]));
    $this->actingAs($user)->post(route('campaigns.wizard-v93.save'), [
        'wizard_id' => '94c40000-0000-4000-8000-000000000002',
    ])->assertRedirect();

    expect(EmailCampaign::query()->count())->toBe(1);
    expect($campaign->fresh()->name)->toBe('Rascunho depois V94');
    expect($campaign->recipients()->count())->toBe(1);
    Mail::assertNothingSent();
});

it('permite cancelar um rascunho por POST autorizado e impede cancelamento por GET', function (): void {
    Mail::fake();
    config()->set('campaign_mail.enabled', false);
    $user = campaignV94Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '941003', 'first_name' => 'Celia', 'email' => 'celia94@exportcontrol.teste',
    ]);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Para cancelar V94', 'subject' => 'Assunto de teste',
        'body' => 'Texto de teste para o fluxo de cancelamento do rascunho via HTTP.',
    ], ['hubspot:941003']);
    $this->actingAs($user)->get(route('campaigns.ops-v94.cancel', ['campaign' => $campaign->id]))
        ->assertStatus(405);
    expect($campaign->fresh()->status)->toBe('draft');

    $this->actingAs($user)->post(route('campaigns.ops-v94.cancel', ['campaign' => $campaign->id]))
        ->assertRedirect(route('campaigns.index', ['campanha' => $campaign->id]));
    expect($campaign->fresh()->status)->toBe('canceled');
    Mail::assertNothingSent();
});

it('informa transicoes invalidas e mantem cancelamento repetido idempotente', function (): void {
    Mail::fake();
    config()->set('campaign_mail.enabled', false);
    $user = campaignV94Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '941030', 'first_name' => 'Elisa', 'email' => 'elisa94@exportcontrol.teste',
    ]);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Estados V94', 'subject' => 'Assunto de teste',
        'body' => 'Texto de teste para transições válidas e inválidas sem envio.',
    ], ['hubspot:941030']);

    $this->actingAs($user)->post(route('campaigns.ops-v94.pause', ['campaign' => $campaign->id]))
        ->assertSessionHasErrors('campaign');
    expect($campaign->fresh()->status)->toBe('draft');

    $this->post(route('campaigns.ops-v94.resume', ['campaign' => $campaign->id]))
        ->assertSessionHasErrors('campaign');
    expect($campaign->fresh()->status)->toBe('draft');

    $this->post(route('campaigns.ops-v94.cancel', ['campaign' => $campaign->id]))
        ->assertRedirect(route('campaigns.index', ['campanha' => $campaign->id]));
    expect($campaign->fresh()->status)->toBe('canceled');

    $this->post(route('campaigns.ops-v94.cancel', ['campaign' => $campaign->id]))
        ->assertRedirect(route('campaigns.index', ['campanha' => $campaign->id]));
    expect($campaign->fresh()->status)->toBe('canceled');
    Mail::assertNothingSent();
});

it('nao inicia envio sem confirmacao legal e nem com o SMTP desativado', function (): void {
    Mail::fake();
    $user = campaignV94Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '941004', 'first_name' => 'Diego', 'email' => 'diego94@exportcontrol.teste',
    ]);
    $campaign = app(CampaignManager::class)->saveDraft($user, [
        'name' => 'Sem envio V94', 'subject' => 'Assunto de teste',
        'body' => 'Corpo de teste para validar que o envio real não dispara sozinho.',
    ], ['hubspot:941004']);
    $this->actingAs($user)->post(route('campaigns.ops-v94.start', ['campaign' => $campaign->id]))
        ->assertSessionHasErrors('lawful_basis');
    expect($campaign->fresh()->status)->toBe('draft');

    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    $this->actingAs($user)->post(route('campaigns.ops-v94.start', ['campaign' => $campaign->id]), [
        'lawful_basis' => '1',
    ])->assertSessionHasErrors();
    expect($campaign->fresh()->status)->toBe('draft');
    Mail::assertNothingSent();
});
