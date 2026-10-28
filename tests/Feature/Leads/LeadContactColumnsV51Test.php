<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\HubSpotContactExportV50Service;

it('exports a contact name immediately before its associated phone and email information', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'v51-company-main',
        'name' => 'Empresa dos Contatos V51',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v51-deal-main',
        'stage_label' => 'Oportunidade Futura',
    ]);
    $deal->companies()->attach($company->id, ['is_primary' => true]);

    $leticia = HubSpotContact::query()->create([
        'hubspot_id' => 'v51-leticia',
        'first_name' => 'Letícia',
        'last_name' => 'Silva',
        'phone' => '(44) 3333-4444',
        'mobile_phone' => '(44) 98888-1111',
        'email' => 'leticia@exemplo.test',
    ]);
    $marcio = HubSpotContact::query()->create([
        'hubspot_id' => 'v51-marcio',
        'first_name' => 'Márcio',
        'last_name' => 'Oliveira',
        'phone' => '',
        'mobile_phone' => '(44) 97777-2222',
        'email' => 'marcio@exemplo.test',
    ]);
    $company->contacts()->attach($leticia->id);
    $deal->contacts()->attach([$leticia->id, $marcio->id]);

    $service = app(HubSpotContactExportV50Service::class);
    $people = $service->forHubSpotDeal($deal, $company);

    expect($people['names'])->toBe('Letícia Silva | Márcio Oliveira')
        ->and($people['namedPhones'])->toContain('Letícia Silva: (44) 3333-4444 / (44) 98888-1111')
        ->and($people['namedPhones'])->toContain('Márcio Oliveira: (44) 97777-2222')
        ->and($people['namedEmails'])->toContain('Letícia Silva: leticia@exemplo.test', 'Márcio Oliveira: marcio@exemplo.test')
        ->and(substr_count($people['names'], 'Letícia Silva'))->toBe(1)
        ->and($service->withAssociatedContacts('4499999999', $people['namedPhones']))
        ->toContain('Empresa: 4499999999', 'Letícia Silva:')
        ->and($service->withAssociatedContacts('', $people['namedEmails']))
        ->toBe($people['namedEmails']);
});

it('never invents names or changes fiscal phone and email if HubSpot has no associated contacts', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'v51-company-no-contact',
        'name' => 'Sem contato V51',
    ]);
    $service = app(HubSpotContactExportV50Service::class);
    $people = $service->forHubSpotCompany($company);

    expect($people['names'])->toBe('')
        ->and($people['namedPhones'])->toBe('')
        ->and($people['namedEmails'])->toBe('')
        ->and($service->withAssociatedContacts('(45) 3300-1234', $people['namedPhones']))->toBe('(45) 3300-1234')
        ->and($service->withAssociatedContacts('contato@empresa.test', $people['namedEmails']))->toBe('contato@empresa.test');
});
