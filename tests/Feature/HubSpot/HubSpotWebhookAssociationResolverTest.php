<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\HubSpotWebhookAssociationResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it(
    'resolves HubSpot mirror company deal and contact to a Prospector company',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '12345678',

                    'corporate_name' => 'Empresa Espelho Webhook',
                ]);

        $hubSpotCompany =
            HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'hubspot_id' => 'hs-company-webhook',

                    'name' => 'Empresa HubSpot Webhook',
                ]);

        $deal =
            HubSpotDeal::query()
                ->create([
                    'hubspot_id' => 'hs-deal-webhook',

                    'name' => 'Negócio Webhook',
                ]);

        $contact =
            HubSpotContact::query()
                ->create([
                    'hubspot_id' => 'hs-contact-webhook',

                    'first_name' => 'Contato',

                    'last_name' => 'Webhook',
                ]);

        $hubSpotCompany
            ->deals()
            ->attach(
                $deal->id
            );

        $hubSpotCompany
            ->contacts()
            ->attach(
                $contact->id
            );

        $resolver =
            app(
                HubSpotWebhookAssociationResolver::class
            );

        expect(
            $resolver->companyIds(
                objectType: 'company',
                objectId: 'hs-company-webhook',
            )
        )->toBe([
            $company->id,
        ]);

        expect(
            $resolver->companyIds(
                objectType: 'deal',
                objectId: 'hs-deal-webhook',
            )
        )->toBe([
            $company->id,
        ]);

        expect(
            $resolver->companyIds(
                objectType: 'contact',
                objectId: 'hs-contact-webhook',
            )
        )->toBe([
            $company->id,
        ]);
    }
);

it(
    'follows all pages when resolving external HubSpot company associations',
    function (): void {
        config([
            'services.hubspot.access_token' => 'test-token',

            'services.hubspot.base_url' => 'https://api.hubapi.com',
        ]);

        $companyPages = [];
        $firstPage =
            array_map(
                static fn (
                    int $id
                ): array => [
                    'toObjectId' => (string) $id,
                ],
                range(300, 399)
            );

        Http::fake(function (Request $request) use (
            &$companyPages,
            $firstPage,
        ) {
            $url =
                $request->url();

            if (
                str_contains(
                    $url,
                    '/crm/v4/objects/tasks/task-500/associations/companies'
                )
            ) {
                parse_str(
                    (string) parse_url(
                        $url,
                        PHP_URL_QUERY
                    ),
                    $query
                );

                $after =
                    $query['after']
                    ?? null;

                $companyPages[] =
                    $after;

                if ($after === 'cursor-next') {
                    return Http::response([
                        'results' => [
                            [
                                'toObjectId' => '400',
                            ],
                        ],
                    ]);
                }

                return Http::response([
                    'results' => $firstPage,
                    'paging' => [
                        'next' => [
                            'after' => 'cursor-next',
                        ],
                    ],
                ]);
            }

            return Http::response([
                'results' => [],
            ]);
        });

        $companyIds =
            app(
                HubSpotWebhookAssociationResolver::class
            )->hubSpotCompanyIds(
                objectType: 'task',
                objectId: 'task-500',
            );

        expect($companyIds)
            ->toHaveCount(101)
            ->toContain('400')
            ->and($companyPages)
            ->toBe([
                null,
                'cursor-next',
            ]);
    }
);
