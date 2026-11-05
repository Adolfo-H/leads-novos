<?php

use App\Models\User;

it('removes the obsolete general website batch section but keeps company-specific search and phone registry', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($manager)->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Pesquisar WhatsApp de uma empresa específica')
        ->assertSee('Buscar empresa por nome ou domínio')
        ->assertSee('Lista completa de números')
        ->assertDontSee('Sites por lote')
        ->assertDontSee('wire:click="startPublicSites"', false)
        ->assertDontSee('wire:click="savePublicSitesSchedule"', false);
});
