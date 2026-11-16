<?php

use App\Models\User;

it('organizes the WhatsApp registry into three clearly labeled areas', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Seus números, sem complicação')
        ->assertSee('Números e contatos')
        ->assertSee('Pesquisar empresa')
        ->assertSee('Verificações e histórico')
        ->assertSee('Lista completa de números')
        ->assertSee('Verificar números agora')
        ->assertSee('whatsapp-public-company-v11')
        ->assertSee('whatsapp-web-v6-recent');
});

it('keeps familiar filters, progress and consultation actions available', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $this->actingAs($user)->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Todos os números em uma só central')
        ->assertSee('Consultar minha base')
        ->assertSee('Mais filtros de pesquisa')
        ->assertSee('Consultados via Web')
        ->assertSee('Histórico recente de consultas')
        ->assertSee('Verificar agora com as 2 contas');
});
