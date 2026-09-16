<?php

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;

it('disables public registration by default', function () {
    $this
        ->get('/register')
        ->assertNotFound();
});

it('requires email verification on protected pages', function () {
    $user =
        User::factory()
            ->unverified()
            ->create();

    expect(
        $user
    )->toBeInstanceOf(
        MustVerifyEmail::class
    );

    $this
        ->actingAs(
            $user
        )
        ->get('/dashboard')
        ->assertRedirect(
            route(
                'verification.notice',
                absolute: false
            )
        );
});

it('allows verified users to access protected pages', function () {
    $user =
        User::factory()
            ->create();

    $this
        ->actingAs(
            $user
        )
        ->get('/dashboard')
        ->assertOk();
});
