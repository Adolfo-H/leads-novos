<?php

use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
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

it('normalizes filter values and refuses unexpected origins or intervals', function (): void {
    $filters = CampaignProspectingAudienceV80::filters([
        'days' => '365', 'origin' => 'receita', 'mode' => 'dormant',
        'company' => '  Soja  ',
    ]);
    expect($filters['days'])->toBe(365)
        ->and($filters['origin'])->toBe('receita')
        ->and($filters['mode'])->toBe('dormant')
        ->and($filters['company'])->toBe('Soja');

    $invalid = CampaignProspectingAudienceV80::filters([
        'days' => '999', 'origin' => 'invalid', 'mode' => 'nope', 'company' => ['bad'],
    ]);
    expect($invalid['days'])->toBe(90)
        ->and($invalid['origin'])->toBe('all')
        ->and($invalid['mode'])->toBe('all')
        ->and($invalid['company'])->toBe('');
});

it('excludes customers, recent contacts, active deals and opt-outs from prospects', function (): void {
    $lead = HubSpotContact::query()->create([
        'hubspot_id' => '8000001', 'first_name' => 'Lead', 'email' => 'lead80@example.org',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '8000002', 'first_name' => 'Cliente', 'email' => 'cliente80@example.org',
        'lifecycle_stage' => 'customer',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '8000003', 'first_name' => 'Recente', 'email' => 'recente80@example.org',
        'last_activity_at' => now('UTC')->subDays(7),
    ]);
    $negotiating = HubSpotContact::query()->create([
        'hubspot_id' => '8000004', 'first_name' => 'Negociando', 'email' => 'negocio80@example.org',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '8000088', 'name' => 'Negócio aberto', 'is_closed' => false,
        'is_closed_won' => false,
    ]);
    $deal->contacts()->attach($negotiating->id);
    HubSpotContact::query()->create([
        'hubspot_id' => '8000005', 'first_name' => 'Descadastrado', 'email' => 'optout80@example.org',
    ]);
    DB::table('email_campaign_suppressions')->insert([
        'normalized_email' => 'optout80@example.org', 'reason' => 'unsubscribe',
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    $service = app(CampaignProspectingAudienceV80::class);
    $rows = $service->query(['origin' => 'hubspot', 'days' => 90])->get();
    expect($rows->pluck('normalized_email')->all())->toBe(['lead80@example.org'])
        ->and($rows->first()->source_key)->toBe('hubspot:'.$lead->hubspot_id);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('only allows a manager to access the new tab and never sends on page load', function (): void {
    $this->get(route('campaigns.prospecting.index'))->assertRedirect();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('campaigns.prospecting.index'))->assertForbidden();
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($manager)->get(route('campaigns.prospecting.index'))->assertOk()
        ->assertSee('Prospecção inteligente');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
