<?php

use App\Models\EmailCampaignSuppression;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('preserva contatos selecionados ao paginar pesquisar e trocar origem', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    for ($index = 1; $index <= 13; $index++) {
        HubSpotContact::query()->create([
            'hubspot_id' => '9300'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'first_name' => 'Contato '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'email' => 'contato'.$index.'@empresa.teste',
        ]);
    }

    $component = Livewire::actingAs($manager)
        ->test('pages::contacts.index')
        ->call('toggleCurrentPage')
        ->assertSet('checkedContactKeys', fn (array $keys): bool => count($keys) === 12)
        ->call('changeContactsPage', 2)
        ->call('toggleCurrentPage')
        ->assertSet('checkedContactKeys', fn (array $keys): bool => count($keys) === 13)
        ->set('searchTerm', 'Contato 13')
        ->set('peopleOrigin', 'hubspot')
        ->assertSet('checkedContactKeys', fn (array $keys): bool => count($keys) === 13)
        ->assertSet('checkedContactEmails', fn (array $emails): bool => count($emails) === 13)
        ->assertSee('13 contato(s) selecionado(s)');

    expect(substr_count($component->html(), 'name="campaign_contacts_v92[]"'))->toBe(13)
        ->and(substr_count($component->html(), 'name="campaign_emails_v92['))->toBe(13);

    $component->call('clearChecked')
        ->assertSet('checkedContactKeys', [])
        ->assertSet('checkedContactEmails', [])
        ->assertDontSee('13 contato(s) selecionado(s)');
});

it('resolve uma selecao em lote sem consultas por destinatario', function (): void {
    config()->set('campaign_mail.max_recipients', 100);
    $keys = [];
    for ($index = 1; $index <= 20; $index++) {
        $id = '9310'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        HubSpotContact::query()->create([
            'hubspot_id' => $id,
            'first_name' => 'Pessoa '.$index,
            'email' => 'pessoa'.$index.'@empresa.teste',
        ]);
        $keys[] = 'hubspot:'.$id;
    }
    EmailCampaignSuppression::query()->create([
        'normalized_email' => 'pessoa7@empresa.teste',
        'reason' => 'unsubscribe',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolved = app(CampaignAudience::class)->resolve($keys);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($resolved)->toHaveCount(20)
        ->and($resolved[0]['source_key'])->toBe($keys[0])
        ->and($resolved[6]['suppressed'])->toBeTrue()
        ->and($resolved[7]['suppressed'])->toBeFalse()
        ->and($queryCount)->toBe(2);
});
