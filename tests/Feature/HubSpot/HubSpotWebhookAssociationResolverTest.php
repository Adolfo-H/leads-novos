<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\HubSpotWebhookAssociationResolver;

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
