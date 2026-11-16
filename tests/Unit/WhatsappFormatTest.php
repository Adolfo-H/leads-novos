<?php

use App\Services\Contacts\BrazilPhoneNumber;

it('normalizes separators, country code and unambiguous Brazilian dialing prefixes', function (): void {
    $forms = [
        '(45) 99999-1010',
        '+55 (45) 99999 1010',
        '45999991010',
        '0 45 99999 1010',
        '0 15 45 99999-1010',
        '00 55 (45) 99999-1010',
    ];

    foreach ($forms as $form) {
        $result = BrazilPhoneNumber::inspect($form);
        expect($result)->not()->toBeNull()
            ->and($result['kind'])->toBe('mobile')
            ->and($result['key'])->toBe('5545999991010');
    }
    expect(BrazilPhoneNumber::inspect('0 45 3234-5555')['kind'])->toBe('landline');
});

it('never invents an unknown DDD, a missing ninth digit or an unrecognized carrier code', function (): void {
    expect(BrazilPhoneNumber::inspect('99999-1010')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('(45) 9999-8888')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('0 99 45 99999-1010')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('0800 123 4567')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::hint('99999-1010')['title'])->toBe('Número sem DDD');
    expect(BrazilPhoneNumber::hint('45 9999 8888')['candidate'])->toBe('+55 (45) 99999-8888');
});
