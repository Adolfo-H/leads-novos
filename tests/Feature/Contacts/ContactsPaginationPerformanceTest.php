<?php

use App\Models\CommercialContact;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('preserva o total e a paginação completa do diretório sem filtro', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '97030001',
        'first_name' => 'Pessoa HubSpot',
    ]);
    CommercialContact::query()->create([
        'name' => 'Pessoa Manual',
    ]);

    $service = app(ContactPeopleDirectory::class);
    $page = $service->paginate();
    $workspacePage = $service->paginateForWorkspace();

    expect($page->total())->toBe(2)
        ->and($workspacePage->total())->toBe($page->total())
        ->and($page->lastPage())->toBe(1)
        ->and($workspacePage->count())->toBe(2)
        ->and($service->paginate('HubSpot')->total())->toBe(1);
});

it('mostra a lista antes dos totais e completa os números após wire:init', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($user)
        ->test('pages::contacts.index')
        ->assertSet('totalsReady', false)
        ->assertSee('Central de Contatos')
        ->call('loadTotals')
        ->assertSet('totalsReady', true)
        ->assertHasNoErrors();
});
