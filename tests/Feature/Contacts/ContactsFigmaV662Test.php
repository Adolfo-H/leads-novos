<?php

declare(strict_types=1);

use App\Models\User;

it('removes the remaining dedicated Receita wording without losing the contacts workflow', function (): void {
    $source = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    expect($source)->not->toBeFalse();

    expect((string) $source)
        ->toContain('data-ec-contacts-v662="no-receita-panel-copy"')
        ->not->toContain('Canais da Receita')
        ->toContain('wire:click="toggleCurrentPage"')
        ->toContain('wire:click="createCampaignFromSelected"')
        ->toContain('wire:submit="saveContact"')
        ->toContain('c661-select-all')
        ->toContain('integration-icon-v661');
});

it('renders the Contacts C01 page without the removed wording', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)->get(route('contacts.index'))
        ->assertSuccessful()
        ->assertSee('Pessoas e canais de contato')
        ->assertDontSee('Canais da Receita')
        ->assertSee('Novo contato');
});
