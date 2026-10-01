<?php

use App\Models\User;
use App\Services\HubSpotOwnerResolverService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

it('resolves the HubSpot owner from the Prospector user email', function () {
    config([
        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.access_token' => 'test-token',
    ]);

    Http::fake([
        'https://api.hubapi.com/crm/v3/owners*' => Http::response([
            'results' => [
                [
                    'id' => '987654',

                    /*
                         * Este NÃO deve ser salvo.
                         */
                    'userId' => 123456,

                    'email' => 'vendedor@exportcontrol.com.br',

                    'archived' => false,
                ],
            ],
        ]),
    ]);

    $user =
        User::factory()->create([
            'email' => 'vendedor@exportcontrol.com.br',

            'hubspot_owner_id' => null,
        ]);

    $ownerId =
        app(
            HubSpotOwnerResolverService::class
        )->resolve(
            $user
        );

    expect(
        $ownerId
    )->toBe(
        '987654'
    );

    expect(
        $user
            ->refresh()
            ->hubspot_owner_id
    )->toBe(
        '987654'
    );

    Http::assertSent(
        function ($request): bool {
            return
                $request->method() === 'GET'
                && str_starts_with(
                    $request->url(),
                    'https://api.hubapi.com/crm/v3/owners'
                )
                && (
                    $request->data()['email']
                    ?? null
                ) ===
                    'vendedor@exportcontrol.com.br';
        }
    );
});

it('reuses the stored HubSpot owner without making another request', function () {
    Http::fake();

    $user =
        User::factory()->create([
            'hubspot_owner_id' => '777777',
        ]);

    $ownerId =
        app(
            HubSpotOwnerResolverService::class
        )->resolve(
            $user
        );

    expect(
        $ownerId
    )->toBe(
        '777777'
    );

    Http::assertNothingSent();
});

it('can refresh a stored HubSpot owner using the user email', function () {
    config([
        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.access_token' => 'test-token',
    ]);

    Http::fake([
        'https://api.hubapi.com/crm/v3/owners*' => Http::response([
            'results' => [
                [
                    'id' => '222222',

                    'email' => 'novo@exportcontrol.com.br',

                    'archived' => false,
                ],
            ],
        ]),
    ]);

    $user =
        User::factory()->create([
            'email' => 'novo@exportcontrol.com.br',

            'hubspot_owner_id' => '111111',
        ]);

    $ownerId =
        app(
            HubSpotOwnerResolverService::class
        )->resolve(
            $user,
            refresh: true,
        );

    expect(
        $ownerId
    )->toBe(
        '222222'
    );

    expect(
        $user
            ->refresh()
            ->hubspot_owner_id
    )->toBe(
        '222222'
    );
});

it('refuses to guess a HubSpot owner when the email is not found', function () {
    config([
        'services.hubspot.base_url' => 'https://api.hubapi.com',

        'services.hubspot.access_token' => 'test-token',
    ]);

    Http::fake([
        'https://api.hubapi.com/crm/v3/owners*' => Http::response([
            'results' => [],
        ]),
    ]);

    $user =
        User::factory()->create([
            'email' => 'inexistente@exportcontrol.com.br',

            'hubspot_owner_id' => null,
        ]);

    expect(
        fn () => app(
            HubSpotOwnerResolverService::class
        )->resolve(
            $user
        )
    )->toThrow(
        RuntimeException::class,
        'Nenhum responsável ativo do HubSpot'
    );
});
