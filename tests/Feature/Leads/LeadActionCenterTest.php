<?php

use App\Models\User;
use Livewire\Livewire;

it('renders the consolidated commercial shortcuts in Leads V15', function () {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)->test('pages::leads.index')
        ->assertSee('Atrasados / Vencem hoje')
        ->assertSee('Minha fila')
        ->assertSee('Novos para prospectar')
        ->assertSee('Pipeline HubSpot')
        ->assertDontSee('Mais atalhos');
});

it('uses the existing overdue filter from the action center', function () {
    $user =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::leads.index'
        )
        ->call(
            'applyFollowUpView',
            'overdue'
        )
        ->assertSet(
            'workStatus',
            'waiting'
        )
        ->assertSet(
            'followUp',
            'overdue'
        );
});
