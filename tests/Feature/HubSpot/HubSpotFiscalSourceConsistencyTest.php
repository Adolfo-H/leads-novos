<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use Illuminate\Support\Facades\DB;

it('keeps PHP and SQL fiscal link policies consistent for historical match sources', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '96160001',
        'corporate_name' => 'Empresa Segura V16',
    ]);

    // Grava diretamente para representar legados anteriores ao bloqueio
    // dos eventos saving() do modelo; nenhuma API externa e chamada.
    $examples = [
        ['manual_manager', false],
        ['prospector_created', false],
        [null, false],
        ['  MANUAL_VERIFIED  ', false],
        ['hubspot-related-old-import', false],
        ['hubspotXrelatedZlegacy', false],
        ['hubspot_related_explicit_cnpj', true],
        ['existing_company_unique_domain', true],
        ['hubspot_related_old_import', true],
        ['  HUBSPOT_RELATED_another_source  ', true],
        ['legacy_inherited_cnpj', true],
        ['Old_PROPAGATED_CNPJ_marker', true],
    ];

    $expectedTrusted = [];
    $expectedUnsafe = [];

    foreach ($examples as $index => [$source, $unsafe]) {
        $hubspotId = 'v16-fiscal-'.($index + 1);

        DB::table('hubspot_companies')->insert([
            'hubspot_id' => $hubspotId,
            'company_id' => $company->id,
            'match_source' => $source,
            'name' => 'Teste fiscal '.($index + 1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(HubSpotCompany::isUnsafeFiscalMatchSource($source))
            ->toBe($unsafe);

        $record = HubSpotCompany::query()
            ->where('hubspot_id', $hubspotId)
            ->firstOrFail();

        expect($record->hasTrustedFiscalLink())->toBe(! $unsafe);

        if ($unsafe) {
            $expectedUnsafe[] = $hubspotId;
        } else {
            $expectedTrusted[] = $hubspotId;
        }
    }

    $trusted = HubSpotCompany::query()
        ->trustedFiscalLink()
        ->where('company_id', $company->id)
        ->pluck('hubspot_id')
        ->sort()
        ->values()
        ->all();

    $unsafe = HubSpotCompany::query()
        ->unsafeFiscalLink()
        ->where('company_id', $company->id)
        ->pluck('hubspot_id')
        ->sort()
        ->values()
        ->all();

    $fromCompany = $company->hubSpotCompanies()
        ->pluck('hubspot_id')
        ->sort()
        ->values()
        ->all();

    sort($expectedTrusted);
    sort($expectedUnsafe);

    expect($trusted)->toBe($expectedTrusted)
        ->and($unsafe)->toBe($expectedUnsafe)
        ->and($fromCompany)->toBe($expectedTrusted);
});

it('includes all unsafe historical source patterns in repair preview without changing data', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '96160002',
        'corporate_name' => 'Empresa Preview V16',
    ]);

    foreach (['hubspot_related_legacy', 'Old_inherited_cnpj_link', 'manual_manager'] as $i => $source) {
        DB::table('hubspot_companies')->insert([
            'hubspot_id' => 'preview-v16-'.$i,
            'company_id' => $company->id,
            'matched_cnpj_root' => $company->cnpj_root,
            'match_source' => $source,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->artisan('hubspot:repair-unsafe-fiscal-links')
        ->expectsOutputToContain('Vínculos fiscais inseguros encontrados: 2')
        ->assertSuccessful();

    expect(
        DB::table('hubspot_companies')
            ->where('company_id', $company->id)
            ->count()
    )->toBe(3);
});

it('rejects future unsafe fiscal links even when the source is a new related prefix', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '96160003',
        'corporate_name' => 'Empresa Escrita V16',
    ]);

    expect(fn () => HubSpotCompany::query()->create([
        'hubspot_id' => 'v16-new-unsafe',
        'company_id' => $company->id,
        'match_source' => 'hubspot_related_inferred_2026',
    ]))->toThrow(DomainException::class);
});
