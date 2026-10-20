<?php

use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Pagination\LengthAwarePaginator;

it('recupera pagina de contatos usando apenas JSON simples', function (): void {
    $pageJson = json_encode([
        'total' => 16,
        'current_page' => 1,
        'per_page' => 12,
        'rows' => [
            [
                'source_key' => 'hubspot:123',
                'person_name' => 'Contato Teste',
                'phone_statuses' => ['phone' => 'yes'],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $method = new ReflectionMethod(ContactPeopleDirectory::class, 'restoreCachedPage');
    $result = $method->invoke(app(ContactPeopleDirectory::class), $pageJson, 1, 12);

    expect($result)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result->total())->toBe(16)
        ->and($result->lastPage())->toBe(2)
        ->and($result->items()[0]->source_key)->toBe('hubspot:123')
        ->and($result->items()[0]->phone_statuses)->toBe(['phone' => 'yes']);
});

it('rejeita payload quebrado sem devolver classes incompletas', function (): void {
    $method = new ReflectionMethod(ContactPeopleDirectory::class, 'restoreCachedPage');
    $directory = app(ContactPeopleDirectory::class);

    $incomplete = unserialize('O:22:"ClasseQueNaoExisteHoje":0:{}');

    expect($method->invoke($directory, $incomplete, 1, 12))->toBeNull()
        ->and($method->invoke($directory, '{json-quebrado', 1, 12))->toBeNull()
        ->and($method->invoke($directory, '[]', 1, 12))->toBeNull();
});
