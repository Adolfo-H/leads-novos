<?php

use App\Models\Company;
use App\Services\HubSpotCompanyContactSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends every unique company email and phone to HubSpot', function () {
    config([
        'services.hubspot.access_token' => 'test-token',

        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '94949494',

            'corporate_name' => 'EMPRESA TODOS CONTATOS',
        ]);

    /*
     * MATRIZ:
     * e-mail + dois telefones.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '94949494000110',

            'order_number' => '0001',

            'check_digits' => '10',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'email' => 'matriz@todos-contatos.test',

            'phone_1' => '41911111111',

            'phone_2' => '41922222222',
        ]);

    /*
     * FILIAL:
     * outro e-mail + dois telefones.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '94949494000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'email' => 'filial@todos-contatos.test',

            'phone_1' => '11933333333',

            'phone_2' => '11944444444',
        ]);

    /*
     * FILIAL SEM E-MAIL:
     *
     * Os telefones também precisam virar
     * contato cadastral no HubSpot.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '94949494000390',

            'order_number' => '0003',

            'check_digits' => '90',

            'type' => 'branch',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'email' => null,

            'phone_1' => '67955555555',

            'phone_2' => '67966666666',
        ]);

    /*
     * Mesmo e-mail da matriz com telefone
     * adicional.
     *
     * Não deve duplicar o e-mail, mas também
     * não deve perder o telefone extra.
     */
    $company
        ->establishments()
        ->create([
            'cnpj' => '94949494000470',

            'order_number' => '0004',

            'check_digits' => '70',

            'type' => 'branch',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'email' => 'matriz@todos-contatos.test',

            'phone_1' => '31977777777',

            'phone_2' => null,
        ]);

    $created = [];

    Http::fake(
        function (
            Request $request
        ) use (
            &$created
        ) {
            $url =
                $request->url();

            /*
             * Nenhum contato já existe.
             */
            if (
                $request->method()
                    === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/contacts/search'
                )
            ) {
                return Http::response([
                    'results' => [],
                ]);
            }

            /*
             * Registra os Contacts criados
             * para validarmos os canais.
             */
            if (
                $request->method()
                    === 'POST'
                && str_ends_with(
                    $url,
                    '/crm/v3/objects/contacts'
                )
            ) {
                $properties =
                    data_get(
                        $request->data(),
                        'properties',
                        []
                    );

                $created[] =
                    is_array(
                        $properties
                    )
                        ? $properties
                        : [];

                return Http::response(
                    [
                        'id' => 'contact-'
                            .count(
                                $created
                            ),
                    ],
                    201
                );
            }

            /*
             * Company / Deal / Task associations.
             */
            if (
                $request->method()
                    === 'PUT'
                && str_contains(
                    $url,
                    '/associations/default/'
                )
            ) {
                return Http::response(
                    [],
                    200
                );
            }

            return Http::response(
                [
                    'message' => 'Unexpected request: '
                        .$request->method()
                        .' '
                        .$url,
                ],
                500
            );
        }
    );

    $contacts =
        app(
            HubSpotCompanyContactSyncService::class
        )->sync(
            company: $company->fresh(),

            hubSpotCompanyId: 'company-all',

            hubSpotDealId: 'deal-all',

            hubSpotTaskId: 'task-all',

            ownerId: 'owner-all',
        );

    /*
     * Esperado:
     *
     * 1. matriz com email
     * 2. filial com email
     * 3. filial sem email
     * 4. telefone extra sem campo livre
     */
    expect(
        $contacts
    )->toHaveCount(
        4
    );

    $emails = [];

    $phones = [];

    foreach (
        $created as $properties
    ) {
        $email =
            data_get(
                $properties,
                'email'
            );

        if (
            is_string(
                $email
            )
            && $email !== ''
        ) {
            $emails[] =
                $email;
        }

        foreach (
            [
                'phone',
                'mobilephone',
            ] as $property
        ) {
            $value =
                data_get(
                    $properties,
                    $property
                );

            if (
                is_string(
                    $value
                )
                && $value !== ''
            ) {
                $phones[] =
                    $value;
            }
        }
    }

    sort(
        $emails
    );

    sort(
        $phones
    );

    expect(
        $emails
    )->toBe([
        'filial@todos-contatos.test',
        'matriz@todos-contatos.test',
    ]);

    expect(
        $phones
    )->toBe([
        '11933333333',
        '11944444444',
        '31977777777',
        '41911111111',
        '41922222222',
        '67955555555',
        '67966666666',
    ]);

    /*
     * Cada Contact precisa estar associado
     * à empresa, negócio e tarefa.
     *
     * 4 contatos x 3 associações = 12.
     */
    $associations = 0;

    Http::assertSent(
        function (
            Request $request
        ) use (
            &$associations
        ): bool {
            if (
                $request->method()
                    === 'PUT'
                && str_contains(
                    $request->url(),
                    '/associations/default/'
                )
            ) {
                $associations++;
            }

            return true;
        }
    );

    expect(
        $associations
    )->toBe(
        12
    );
});
