<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function contactsFigmaV66Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows the C01 layout with real statistics, filters and access to Receita', function (): void {
    $this->actingAs(contactsFigmaV66Manager())
        ->get(route('contacts.index'))
        ->assertOk()
        ->assertSee('data-ec-contacts-figma="C01-C05-V66"', false)
        ->assertSee('Pessoas e canais de contato')
        ->assertSee('Contatos no diretório')
        ->assertSee('Com e-mail nesta página')
        ->assertSee('WhatsApp confirmado')
        ->assertSee('Pendentes de verificação')
        ->assertSee('Pesquisar e refinar')
        ->assertSee('Diretório de contatos')
        ->assertDontSee('Canais da Receita')
        ->assertSee('Novo contato');
});

it('preserves the historical v3 selectors, bulk actions and the source distinction', function (): void {
    $source = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/contacts-figma-v66.css'));
    $global = file_get_contents(resource_path('css/app.css'));

    expect($source)
        ->toContain('data-contacts-design="v3-reference"')
        ->toContain('wire:init="loadTotals"')
        ->toContain('ecc3-shell')
        ->toContain('ecc3-inspector')
        ->toContain('wire:click="createCampaignFromSelected"')
        ->toContain('wire:click="exportSelectedCsv"')
        ->toContain('wire:submit="saveContact"')
        ->toContain('wire:click="setWhatsappFromWorkspace(')
        ->toContain('data-ec-contacts-figma="C01-C05-V66"')
        ->toContain('c66-inspector-backdrop')
        ->toContain('wire:model.live="peopleOrigin"')
        ->toContain('wire:model.live="whatsappFilter"')
        ->and($css)->toContain('ecc66-page')->toContain('ecc66-receita')
        ->and($global)->toContain("@import './contacts-figma-v66.css';");
});

it('only opens the C03 contact drawer when a record or company was selected', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '77998844',
        'corporate_name' => 'Grupo Contatos Figma V66',
        'source' => 'manual',
    ]);
    $company->establishments()->create([
        'cnpj' => '77998844000101',
        'order_number' => '0001',
        'check_digits' => '01',
        'type' => 'matrix',
        'registration_status' => 'ATIVA',
        'state' => 'PR',
        'municipality_name' => 'Toledo',
    ]);

    Livewire::actingAs(contactsFigmaV66Manager())
        ->test('pages::contacts.index')
        ->assertSet('inspectorOpen', false)
        ->call('selectCompany', 'local', $company->id)
        ->assertSet('inspectorOpen', true)
        ->assertSee('Detalhes do contato');
});

it('shows the C05 Receita view while keeping corporate channels separate from people', function (): void {
    $this->actingAs(contactsFigmaV66Manager())
        ->get(route('contacts.receita'))
        ->assertOk()
        ->assertSee('data-ec-contacts-figma="C05-V66"', false)
        ->assertSee('Canais cadastrais da Receita')
        ->assertSee('Informação cadastral')
        ->assertSee('Contatos pessoais');
});
