<?php

use App\Services\Contacts\BrazilPhoneNumber;

it('classifies Brazilian mobile numbers without assuming WhatsApp registration', function () {
    expect(BrazilPhoneNumber::inspect('(45) 99999-1010'))->toMatchArray([
        'key' => '5545999991010', 'e164' => '5545999991010', 'kind' => 'mobile',
    ]);
    expect(BrazilPhoneNumber::inspect('+55 (45) 99999-1010')['key'])->toBe('5545999991010');
    expect(BrazilPhoneNumber::display('5545999991010', '45999991010'))->toBe('+55 (45) 99999-1010');
});

it('keeps plausible landlines and unfixable numbers separate', function () {
    expect(BrazilPhoneNumber::inspect('45 3234 5555')['kind'])->toBe('landline');
    expect(BrazilPhoneNumber::inspect('45 9999 8888')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('099 45 99999-1010')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('99999-1010')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect('0800 123 4567')['kind'])->toBe('review');
    expect(BrazilPhoneNumber::inspect(''))->toBeNull();
});
