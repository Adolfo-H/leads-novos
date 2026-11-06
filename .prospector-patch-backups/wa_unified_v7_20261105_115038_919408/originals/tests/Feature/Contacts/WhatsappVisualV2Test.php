<?php

use App\Models\User;

it('displays the new light visual and quick filters without changing WhatsApp rules', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($manager)
        ->get('/contatos/verificacao-whatsapp')
        ->assertOk()
        ->assertSee('data-ec-whatsapp-design="v2"', false)
        ->assertSee('Atalhos de conferência')
        ->assertSee('Celulares pendentes')
        ->assertSee('Fila de conferência')
        ->assertSee('Filtrar lista de telefones');
});

it('continues restricting the verification registry to managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get('/contatos/verificacao-whatsapp')->assertForbidden();
});
