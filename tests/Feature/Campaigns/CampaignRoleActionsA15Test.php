<?php

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function a15CampaignUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $role])->save();

    return $user;
}

function a15CampaignRecord(User $creator): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Campanha A15 somente consulta',
        'subject' => 'Apresentação de teste',
        'body' => 'Mensagem fictícia, nenhum e-mail será enviado.',
        'status' => 'draft',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'ExportControl',
        'reply_to' => 'teste@example.invalid',
        'created_by' => $creator->id,
    ]);
}

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.pilot_enabled', false);
    Mail::fake();
    Queue::fake();
});

it('permite ao vendedor consultar campanhas sem exibir botoes de criacao ou envio', function (): void {
    $manager = a15CampaignUser(User::ROLE_MANAGER);
    $seller = a15CampaignUser(User::ROLE_SELLER);
    a15CampaignRecord($manager);

    $this->actingAs($seller)->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Campanha A15 somente consulta')
        ->assertSee('Acesso somente leitura')
        ->assertDontSee('Começar campanha')
        ->assertDontSee('Nova campanha')
        ->assertDontSee('Usar modelo');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('permite ao vendedor abrir detalhes mas nao gerenciar rascunhos', function (): void {
    $manager = a15CampaignUser(User::ROLE_MANAGER);
    $seller = a15CampaignUser(User::ROLE_SELLER);
    $campaign = a15CampaignRecord($manager);

    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('openCampaign', $campaign->id)
        ->assertSet('screen', 'detail')
        ->assertSee('Campanha A15 somente consulta')
        ->assertSee('Acesso somente leitura')
        ->assertDontSee('Editar rascunho')
        ->assertDontSee('Cancelar campanha')
        ->assertDontSee('Ações rápidas');

    expect($campaign->fresh()->status)->toBe('draft');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('bloqueia chamadas Livewire diretas do vendedor para acoes de escrita', function (): void {
    $manager = a15CampaignUser(User::ROLE_MANAGER);
    $seller = a15CampaignUser(User::ROLE_SELLER);
    $campaign = a15CampaignRecord($manager);

    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('newCampaign')->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('applyTemplate', 'initial')->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('editDraft', $campaign->id)->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('saveDraft')->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('startCampaign', $campaign->id)->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('pauseCampaign', $campaign->id)->assertForbidden();
    Livewire::actingAs($seller)->test('pages::campaigns.index')
        ->call('cancelCampaign', $campaign->id)->assertForbidden();

    expect($campaign->fresh()->status)->toBe('draft')
        ->and(EmailCampaign::query()->count())->toBe(1);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('mantem a criacao e a edicao de rascunhos disponiveis ao gestor', function (): void {
    $manager = a15CampaignUser(User::ROLE_MANAGER);
    $campaign = a15CampaignRecord($manager);

    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->assertSee('Nova campanha')
        ->call('newCampaign')
        ->assertSet('screen', 'composer')
        ->assertSee('Selecione os contatos');

    Livewire::actingAs($manager)->test('pages::campaigns.index')
        ->call('openCampaign', $campaign->id)
        ->assertSee('Editar rascunho')
        ->call('editDraft', $campaign->id)
        ->assertSet('screen', 'composer')
        ->assertSet('editingCampaignId', $campaign->id);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
