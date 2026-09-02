<?php

use App\Support\Cnpj;
use InvalidArgumentException;

it('normalizes a traditional cnpj', function () {
    expect(
        Cnpj::normalize(
            '11.222.333/0001-81'
        )
    )->toBe('11222333000181');
});

it('validates a traditional cnpj', function () {
    expect(
        Cnpj::isValid(
            '11.222.333/0001-81'
        )
    )->toBeTrue();
});

it('calculates traditional check digits', function () {
    expect(
        Cnpj::calculateCheckDigits(
            '112223330001'
        )
    )->toBe('81');
});

it('validates the official alphanumeric example', function () {
    expect(
        Cnpj::isValid(
            '12.ABC.345/01DE-35'
        )
    )->toBeTrue();
});

it('calculates alphanumeric check digits', function () {
    expect(
        Cnpj::calculateCheckDigits(
            '12ABC34501DE'
        )
    )->toBe('35');
});

it('identifies the root of an alphanumeric cnpj', function () {
    expect(
        Cnpj::root(
            '12.ABC.345/01DE-35'
        )
    )->toBe('12ABC345');
});

it('identifies establishment order', function () {
    expect(
        Cnpj::order(
            '12.ABC.345/01DE-35'
        )
    )->toBe('01DE');
});

it('formats an alphanumeric cnpj', function () {
    expect(
        Cnpj::format(
            '12ABC34501DE35'
        )
    )->toBe(
        '12.ABC.345/01DE-35'
    );
});

it('detects an alphanumeric cnpj', function () {
    expect(
        Cnpj::isAlphanumeric(
            '12.ABC.345/01DE-35'
        )
    )->toBeTrue();

    expect(
        Cnpj::isAlphanumeric(
            '11.222.333/0001-81'
        )
    )->toBeFalse();
});

it('rejects incorrect check digits', function () {
    expect(
        Cnpj::isValid(
            '11.222.333/0001-99'
        )
    )->toBeFalse();
});

it('throws when base has invalid length', function () {
    Cnpj::calculateCheckDigits(
        '123'
    );
})->throws(InvalidArgumentException::class);
