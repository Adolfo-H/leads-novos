<?php

use App\Models\User;

test('redireciona visitantes para o login', function (): void {
    $this->get(route('home'))
        ->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk();
});

test('redireciona usuarios autenticados para o dashboard', function (): void {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
