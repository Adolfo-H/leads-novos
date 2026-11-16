<?php

use App\Services\HubSpotWebhookAssociationResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);
    Http::preventStrayRequests();
});

/** @return list<string> */
function associationPaginationIds(): array
{
    $method = new ReflectionMethod(HubSpotWebhookAssociationResolver::class, 'associationIds');

    return $method->invoke(app(HubSpotWebhookAssociationResolver::class), 'tasks', 'test-task', 'companies');
}

it('rejects malformed association data instead of replacing known links with an empty snapshot', function (mixed $payload): void {
    Http::fake(['*' => Http::response($payload)]);

    expect(fn () => associationPaginationIds())->toThrow(RuntimeException::class);
})->with([
    'missing results' => [[]],
    'invalid results' => [['results' => 'invalid']],
    'invalid item' => [['results' => [false]]],
    'missing identifier' => [['results' => [[]]]],
    'boolean identifier' => [['results' => [['toObjectId' => true]]]],
    'invalid paging' => [['results' => [], 'paging' => 'invalid']],
    'missing cursor' => [['results' => [], 'paging' => ['next' => []]]],
]);

it('rejects a repeated association cursor without returning partial identifiers', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['results' => [['toObjectId' => '1']], 'paging' => ['next' => ['after' => 'loop']]])
        ->push(['results' => [['toObjectId' => '2']], 'paging' => ['next' => ['after' => 'loop']]])]);

    expect(fn () => associationPaginationIds())->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
});

it('keeps first-page missing-object compatibility', function (): void {
    Http::fake(['*' => Http::response([], 404)]);

    expect(associationPaginationIds())->toBe([]);
    Http::assertSentCount(1);
});

it('rejects a missing later association page instead of returning partial identifiers', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['results' => [['toObjectId' => '1']], 'paging' => ['next' => ['after' => 'next']]])
        ->push([], 404)]);

    expect(fn () => associationPaginationIds())->toThrow(RuntimeException::class);
    Http::assertSentCount(2);
});

it('supports cursor zero and removes duplicate association identifiers', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['results' => [['toObjectId' => '1']], 'paging' => ['next' => ['after' => 0]]])
        ->push(['results' => [['toObjectId' => '1'], ['toObjectId' => '2']]])]);

    expect(associationPaginationIds())->toBe(['1', '2']);
    Http::assertSentCount(2);
    Http::assertSent(static function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['after'] ?? null) === '0';
    });
});

it('propagates HTTP and connection failures instead of treating them as absence', function (): void {
    Http::fake(['*' => Http::response([], 429)]);
    expect(fn () => associationPaginationIds())->toThrow(RuntimeException::class);

    Http::fake(static function (): never {
        throw new ConnectionException('Simulated offline connection');
    });
    expect(fn () => associationPaginationIds())->toThrow(RuntimeException::class);
});
