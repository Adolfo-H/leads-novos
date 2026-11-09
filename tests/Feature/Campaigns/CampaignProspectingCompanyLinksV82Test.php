<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\Campaigns\CampaignProspectingAudienceV80;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
});

function ecV82Contact(string $hubspotId): HubSpotContact
{
    return HubSpotContact::query()->create([
        'hubspot_id' => $hubspotId,
        'first_name' => 'Contato',
        'email' => 'contato'.$hubspotId.'@example.org',
        'company_name' => null,
    ]);
}

function ecV82Company(string $hubspotId, string $name, string $lifecycle = 'lead'): HubSpotCompany
{
    return HubSpotCompany::query()->create([
        'hubspot_id' => $hubspotId,
        'name' => $name,
        'lifecycle_stage' => $lifecycle,
    ]);
}

function ecV82LinkContact(HubSpotContact $contact, HubSpotCompany $company, bool $primary = false): void
{
    DB::table('hubspot_company_contact')->insert([
        'hubspot_contact_id' => $contact->id,
        'hubspot_company_id' => $company->id,
        'is_primary' => $primary,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

function ecV82LinkDeal(HubSpotContact $contact, HubSpotDeal $deal, HubSpotCompany $company, bool $primary = false): void
{
    DB::table('hubspot_deal_contact')->insert([
        'hubspot_deal_id' => $deal->id,
        'hubspot_contact_id' => $contact->id,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    DB::table('hubspot_company_deal')->insert([
        'hubspot_deal_id' => $deal->id,
        'hubspot_company_id' => $company->id,
        'is_primary' => $primary,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

it('resolves the company through the direct HubSpot contact association even when the text is blank', function (): void {
    $contact = ecV82Contact('8200001');
    $company = ecV82Company('8201001', 'Cooperativa Teste');
    ecV82LinkContact($contact, $company);

    $row = app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot'])
        ->where('eligible.normalized_email', 'contato8200001@example.org')->first();

    expect($row)->not->toBeNull()
        ->and($row->company_name)->toBe('Cooperativa Teste')
        ->and($row->company_link_status)->toBe('contact_company');
    Mail::assertNothingSent();
});

it('resolves the contact company from a unique closed and lost HubSpot deal', function (): void {
    $contact = ecV82Contact('8200002');
    $company = ecV82Company('8201002', 'Cerealista Teste');
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '8202002', 'is_closed' => true, 'is_closed_won' => false,
        'name' => 'Negócio encerrado sem venda',
    ]);
    ecV82LinkDeal($contact, $deal, $company);

    $row = app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot'])
        ->where('eligible.normalized_email', 'contato8200002@example.org')->first();

    expect($row)->not->toBeNull()
        ->and($row->company_name)->toBe('Cerealista Teste')
        ->and($row->company_link_status)->toBe('deal_company');
});

it('does not arbitrarily pick one of several direct companies without a unique primary', function (): void {
    $contact = ecV82Contact('8200003');
    ecV82LinkContact($contact, ecV82Company('8201003', 'Empresa A'));
    ecV82LinkContact($contact, ecV82Company('8201004', 'Empresa B'));

    expect(app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot'])
        ->where('eligible.normalized_email', 'contato8200003@example.org')->exists())->toBeFalse();
});

it('uses the explicitly primary company when a contact has multiple linked companies', function (): void {
    $contact = ecV82Contact('8200004');
    ecV82LinkContact($contact, ecV82Company('8201005', 'Empresa Secundária'));
    ecV82LinkContact($contact, ecV82Company('8201006', 'Empresa Principal'), true);

    $row = app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot'])
        ->where('eligible.normalized_email', 'contato8200004@example.org')->first();

    expect($row)->not->toBeNull()
        ->and($row->company_name)->toBe('Empresa Principal')
        ->and($row->company_link_status)->toBe('contact_company');
});

it('blocks a contact whose closed lost deal is linked to a HubSpot customer company', function (): void {
    $contact = ecV82Contact('8200005');
    $customer = ecV82Company('8201007', 'Cliente no CRM', 'customer');
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '8202005', 'is_closed' => true, 'is_closed_won' => false,
    ]);
    ecV82LinkDeal($contact, $deal, $customer);

    expect(app(CampaignProspectingAudienceV80::class)->query(['origin' => 'hubspot'])
        ->where('eligible.normalized_email', 'contato8200005@example.org')->exists())->toBeFalse();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('only presents company provenance without sending or modifying the HubSpot mirror', function (): void {
    $view = file_get_contents(resource_path('views/campaigns/prospecting-v80.blade.php'));
    expect($view)->not->toBeFalse()
        ->toContain('company_link_status')
        ->toContain('Empresa vinculada ao contato no HubSpot')
        ->toContain('Empresa vinculada ao negócio no HubSpot')
        ->toContain('Sem empresa vinculada no espelho');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
