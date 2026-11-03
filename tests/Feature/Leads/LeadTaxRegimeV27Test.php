<?php

use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

function a27Company(string $root, string $name, ?bool $simples, ?bool $mei): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
        'simples_option' => $simples,
        'mei_option' => $mei,
        'tax_source_period' => '2026-10',
    ]);
    $company->sdrScore()->create([
        'score' => 80, 'priority' => 'high', 'label' => 'Alta',
        'is_eligible' => true, 'is_provisional' => false,
        'factors' => [], 'version' => 'v27', 'metadata' => [], 'calculated_at' => now(),
    ]);

    return $company;
}

it('identifica MEI, optante Simples, nao optante e desconhecido no filtro de Leads', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    a27Company('98727101', 'A27 Optante MEI', true, true);
    a27Company('98727102', 'A27 Optante Simples', true, false);
    a27Company('98727103', 'A27 Nao Optante', false, false);
    a27Company('98727104', 'A27 Regime Desconhecido', null, null);

    $component = Livewire::actingAs($user)->test('pages::leads.index');
    $component->set('taxRegime', 'mei')
        ->assertSee('A27 Optante MEI')->assertDontSee('A27 Optante Simples')
        ->assertDontSee('A27 Nao Optante');
    $component->set('taxRegime', 'simples')
        ->assertSee('A27 Optante Simples')->assertDontSee('A27 Optante MEI');
    $component->set('taxRegime', 'nao_optante')
        ->assertSee('A27 Nao Optante')->assertDontSee('A27 Optante Simples');
    $component->set('taxRegime', 'desconhecido')
        ->assertSee('A27 Regime Desconhecido')->assertDontSee('A27 Nao Optante');
});
