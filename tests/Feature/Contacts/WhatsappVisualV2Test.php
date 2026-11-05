<?php

use App\Models\User;

it('displays the new light visual and quick filters without changing WhatsApp rules', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($manager)
        ->get('/contatos/verificacao-whatsapp')
        ->assertOk()
        ->assertSee('data-ec-whatsapp-design="v2"', false)
        ->assertSee('Todos os números em uma só central') // EC_WA_V7_EXPECTATIONS
        ->assertSee('A verificar')
        ->assertSee('Números e contatos')
        ->assertSee('Filtrar lista de telefones');
});

it('continues restricting the verification registry to managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get('/contatos/verificacao-whatsapp')->assertForbidden();
});
