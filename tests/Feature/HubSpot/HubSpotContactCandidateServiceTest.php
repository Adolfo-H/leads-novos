<?php

use App\Models\Company;
use App\Services\HubSpotContactCandidateService;

it('collects all unique emails and phones without losing extra channels', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '95959595',

            'corporate_name' => 'EMPRESA CANDIDATOS HUBSPOT',
        ]);

    /*
     * Matriz.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '95959595000110',

            'order_number' => '0001',

            'check_digits' => '10',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'email' => ' Comercial@Empresa.Test ',

            'phone_1' => '(45) 99999-0001',

            'phone_2' => '(45) 99999-0002',
        ]);

    /*
     * Mesmo e-mail com casing diferente.
     *
     * O e-mail não deve duplicar, mas o novo
     * telefone também não pode desaparecer.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '95959595000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '02',

            'email' => 'comercial@empresa.test',

            'phone_1' => '45999990003',
        ]);

    /*
     * Outra unidade com e-mail diferente.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '95959595000390',

            'order_number' => '0003',

            'check_digits' => '90',

            'type' => 'branch',

            'registration_status_code' => '02',

            'email' => 'exportacao@empresa.test',

            'phone_1' => '41988880001',
        ]);

    /*
     * Unidade somente com telefones.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '95959595000470',

            'order_number' => '0004',

            'check_digits' => '70',

            'type' => 'branch',

            'registration_status_code' => '02',

            'email' => null,

            'phone_1' => '11977770001',

            'phone_2' => '11977770002',
        ]);

    $contacts =
        app(
            HubSpotContactCandidateService::class
        )->collect(
            $company->fresh()
        );

    expect(
        $contacts
    )->toHaveCount(
        4
    );

    /*
     * Primeiro contato:
     * matriz + e-mail normalizado.
     */
    expect(
        $contacts[0]
    )->toMatchArray([
        'email' => 'comercial@empresa.test',

        'phone' => '(45) 99999-0001',

        'mobilephone' => '(45) 99999-0002',
    ]);

    /*
     * Segundo:
     * outro e-mail.
     */
    expect(
        $contacts[1]
    )->toMatchArray([
        'email' => 'exportacao@empresa.test',

        'phone' => '41988880001',
    ]);

    /*
     * Terceiro:
     * unidade sem e-mail.
     */
    expect(
        $contacts[2]
    )->toMatchArray([
        'email' => null,

        'phone' => '11977770001',

        'mobilephone' => '11977770002',
    ]);

    /*
     * O terceiro telefone do mesmo e-mail
     * não cabe em phone/mobilephone.
     *
     * Ele continua existindo como Contact
     * apenas com telefone.
     */
    expect(
        $contacts[3]
    )->toMatchArray([
        'email' => null,

        'phone' => '45999990003',

        'mobilephone' => null,
    ]);
});

it('deduplicates the same phone even when formatting differs', function () {
    $company =
        Company::query()->create([
            'cnpj_root' => '96969696',

            'corporate_name' => 'EMPRESA TELEFONE DUPLICADO',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '96969696000110',

            'order_number' => '0001',

            'check_digits' => '10',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'phone_1' => '(45) 99999-1111',

            'email' => null,
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '96969696000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '02',

            'phone_1' => '45999991111',

            'email' => null,
        ]);

    $contacts =
        app(
            HubSpotContactCandidateService::class
        )->collect(
            $company->fresh()
        );

    expect(
        $contacts
    )->toHaveCount(
        1
    );
});
