<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use Illuminate\Support\Facades\DB;

it(
    'rejects a fiscal identity inherited from a related HubSpot company',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '11223344',

                    'corporate_name' => 'Empresa Fiscal Segura',
                ]);

        expect(
            fn () => HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'matched_cnpj_root' => '11223344',

                    'matched_company_name' => 'Empresa Errada',

                    'match_source' => 'hubspot_related_explicit_cnpj',

                    'hubspot_id' => 'unsafe-related-company',

                    'name' => 'Empresa Errada',
                ])
        )->toThrow(
            DomainException::class
        );
    }
);

it(
    'ignores a legacy unsafe fiscal link',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '55667788',

                    'corporate_name' => 'Empresa Local Correta',
                ]);

        /*
         * Simula dado histórico que existia
         * antes da proteção no Model.
         */
        DB::table(
            'hubspot_companies'
        )->insert([
            'company_id' => $company->id,

            'matched_cnpj_root' => '55667788',

            'matched_company_name' => 'Empresa Estranha',

            'match_source' => 'hubspot_related_explicit_cnpj',

            'hubspot_id' => 'legacy-unsafe',

            'name' => 'Empresa Estranha',

            'created_at' => now(),

            'updated_at' => now(),
        ]);

        expect(
            $company
                ->fresh()
                ->hubSpotCompanies
                ->count()
        )->toBe(
            0
        );
    }
);

it(
    'quarantines legacy unsafe fiscal links without deleting the HubSpot record',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '22334455',

                    'corporate_name' => 'Empresa Local Antiga',
                ]);

        DB::table(
            'hubspot_companies'
        )->insert([
            'company_id' => $company->id,

            'matched_cnpj_root' => '22334455',

            'matched_company_name' => 'Outra Empresa',

            'match_source' => 'hubspot_related_explicit_cnpj',

            'hubspot_id' => 'legacy-quarantine',

            'name' => 'Outra Empresa',

            'raw_properties' => json_encode([
                'original' => true,
            ]),

            'created_at' => now(),

            'updated_at' => now(),
        ]);

        $this
            ->artisan(
                'hubspot:repair-unsafe-fiscal-links',
                [
                    '--apply' => true,
                ]
            )
            ->assertSuccessful();

        $record =
            HubSpotCompany::query()
                ->where(
                    'hubspot_id',
                    'legacy-quarantine'
                )
                ->firstOrFail();

        expect(
            $record->company_id
        )->toBeNull();

        expect(
            $record->matched_cnpj_root
        )->toBeNull();

        expect(
            $record->match_source
        )->toBeNull();

        expect(
            data_get(
                $record->raw_properties,
                '_prospector_fiscal_quarantine.match_source'
            )
        )->toBe(
            'hubspot_related_explicit_cnpj'
        );
    }
);

it(
    'keeps manually confirmed fiscal links available',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '88776655',

                    'corporate_name' => 'Empresa Confirmada',
                ]);

        HubSpotCompany::query()
            ->create([
                'company_id' => $company->id,

                'matched_cnpj_root' => '88776655',

                'matched_company_name' => 'Empresa Confirmada',

                'match_source' => 'manual_manager',

                'hubspot_id' => 'manual-safe',

                'name' => 'Empresa Confirmada',
            ]);

        expect(
            $company
                ->fresh()
                ->hubSpotCompanies
                ->count()
        )->toBe(
            1
        );
    }
);

it(
    'rejects a fiscal identity based only on a unique domain',
    function (): void {
        $company =
            Company::query()
                ->create([
                    'cnpj_root' => '99887766',

                    'corporate_name' => 'Empresa Domain Teste',
                ]);

        expect(
            fn () => HubSpotCompany::query()
                ->create([
                    'company_id' => $company->id,

                    'matched_cnpj_root' => '99887766',

                    'matched_company_name' => 'Empresa Domain Teste',

                    'match_source' => 'existing_company_unique_domain',

                    'hubspot_id' => 'unsafe-domain-company',

                    'name' => 'Outro Nome HubSpot',

                    'domain' => 'example.com',
                ])
        )->toThrow(
            DomainException::class
        );
    }
);
