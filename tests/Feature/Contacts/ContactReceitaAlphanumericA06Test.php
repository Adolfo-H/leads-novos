<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Services\Contacts\ContactReceitaDirectory;
use App\Support\Cnpj;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{matrix:Establishment,branch:Establishment,decoy:Establishment} */
function a06ReceitaCompanies(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '12ABC345',
        'corporate_name' => 'Empresa Alfanumerica A06',
    ]);

    $matrix = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '12ABC34501DE35',
        'order_number' => '01DE',
        'check_digits' => '35',
        'type' => 'matrix',
        'email' => 'fiscal-alpha@example.test',
        'phone_1' => '(45) 3333-1111',
    ]);

    $branchBase = '12ABC3450002';
    $branch = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => $branchBase.Cnpj::calculateCheckDigits($branchBase),
        'order_number' => '0002',
        'check_digits' => Cnpj::calculateCheckDigits($branchBase),
        'type' => 'branch',
        'phone_2' => '(45) 3333-2222',
    ]);

    // Na implementação antiga, remover as letras gerava a busca incorreta "123450135".
    $numeric = Company::query()->create([
        'cnpj_root' => '99123450',
        'corporate_name' => 'Empresa Numerica A06',
    ]);
    $decoy = Establishment::query()->create([
        'company_id' => $numeric->id,
        'cnpj' => '99123450135001',
        'order_number' => '1350',
        'check_digits' => '01',
        'type' => 'matrix',
        'email' => 'decoy-a06@example.test',
    ]);

    return compact('matrix', 'branch', 'decoy');
}

it('finds exact alphanumeric CNPJ with and without punctuation, preserving letters', function (): void {
    $fixture = a06ReceitaCompanies();
    $directory = app(ContactReceitaDirectory::class);

    foreach (['12.ABC.345/01DE-35', '12ABC34501DE35', '12abc34501de35'] as $value) {
        $result = $directory->search($value);
        expect($result->total())->toBe(1)
            ->and(collect($result->items())->pluck('cnpj')->all())->toBe([$fixture['matrix']->cnpj]);
    }
});

it('finds alphanumeric CNPJ root and preserves matrix branch and channel filters', function (): void {
    a06ReceitaCompanies();
    $directory = app(ContactReceitaDirectory::class);

    expect($directory->search('12ABC345')->total())->toBe(2)
        ->and($directory->search('12.ABC.345')->total())->toBe(2)
        ->and($directory->search('12ABC345', 'matrix')->total())->toBe(1)
        ->and($directory->search('12ABC345', 'branch')->total())->toBe(1)
        ->and($directory->search('12ABC345', 'all', 'email')->total())->toBe(1)
        ->and($directory->search('12ABC345', 'all', 'phone')->total())->toBe(2);
});

it('does not confuse an alphanumeric CNPJ with digits-only searches', function (): void {
    $fixture = a06ReceitaCompanies();
    $directory = app(ContactReceitaDirectory::class);

    expect(collect($directory->search('12.ABC.345/01DE-35')->items())->pluck('cnpj')->all())
        ->not->toContain($fixture['decoy']->cnpj);

    expect(collect($directory->search('123450135')->items())->pluck('cnpj')->all())
        ->toBe([$fixture['decoy']->cnpj]);

    expect(collect($directory->search('4533331111')->items())->pluck('cnpj')->all())
        ->toBe([$fixture['matrix']->cnpj]);

    expect($directory->search('Empresa Alfanumerica')->total())->toBe(2)
        ->and($directory->search('A')->total())->toBe(0);
});
