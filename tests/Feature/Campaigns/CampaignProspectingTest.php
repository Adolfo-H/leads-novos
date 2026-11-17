<?php

use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Campaigns\CampaignProspectingAudience;
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
    $filters = CampaignProspectingAudience::filters([
        'days' => '365', 'origin' => 'receita', 'mode' => 'dormant',
        'company' => '  Soja  ',
    ]);
    expect($filters['days'])->toBe(365)
        ->and($filters['origin'])->toBe('receita')
        ->and($filters['mode'])->toBe('dormant')
        ->and($filters['company'])->toBe('Soja');

    $invalid = CampaignProspectingAudience::filters([
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
    $service = app(CampaignProspectingAudience::class);
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

// EC_PROSPECTING_DRAFT_UNCAPPED_V1_TEST: público >1.000 no rascunho; sem SMTP.
it('creates an intelligent draft with every eligible email beyond the old one-thousand cap', function (): void {
    config()->set('campaign_mail.max_recipients', 1000);
    config()->set('campaign_mail.smtp_approved_per_day', 24000);
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $now = now('UTC');
    $contacts = [];
    for ($index = 0; $index < 1001; $index++) {
        $contacts[] = [
            'hubspot_id' => (string) (8200000 + $index),
            'first_name' => 'Contato',
            'email' => 'camp-v80-'.$index.'@example.net',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($contacts, 500) as $batch) {
        DB::table('hubspot_contacts')->insert($batch);
    }

    $this->actingAs($manager)->get(route('campaigns.prospecting.index', [
        'analyze' => '1', 'origin' => 'hubspot', 'days' => '30',
    ]))->assertOk()->assertSee('1.001');

    $this->actingAs($manager)->post(route('campaigns.prospecting.draft'), [
        'name' => 'Prospecção de 1001 contatos',
        'subject' => 'Controle fiscal de exportações',
        'body' => 'Gostaria de entender como vocês acompanham as vendas para tradings.',
        'basis_reviewed' => '1',
        'pace_hours' => 24,
        'origin' => 'hubspot', 'days' => 30, 'mode' => 'all',
    ])->assertRedirect(route('campaigns.index'));

    $campaign = DB::table('email_campaigns')->orderByDesc('id')->first();
    expect($campaign)->not->toBeNull()
        ->and($campaign->status)->toBe('draft')
        ->and(DB::table('email_campaign_recipients')
            ->where('email_campaign_id', $campaign->id)->count())->toBe(1001);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not confuse daily SMTP capacity with the size of an intelligent draft', function (): void {
    config()->set('campaign_mail.max_recipients', 1);
    config()->set('campaign_mail.smtp_approved_per_day', 2);
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    foreach (['primeiro', 'segundo', 'terceiro'] as $index => $name) {
        HubSpotContact::query()->create([
            'hubspot_id' => (string) (8202000 + $index),
            'first_name' => ucfirst($name),
            'email' => $name.'@camp-v80.example.net',
        ]);
    }

    $this->actingAs($manager)->post(route('campaigns.prospecting.draft'), [
        'name' => 'Três contatos; capacidade de dois por dia',
        'subject' => 'Operações indiretas',
        'body' => 'Gostaria de saber quem acompanha as operações indiretas da empresa.',
        'basis_reviewed' => '1', 'origin' => 'hubspot', 'days' => 30,
    ])->assertRedirect(route('campaigns.index'));

    $campaign = DB::table('email_campaigns')->orderByDesc('id')->first();
    expect($campaign)->not->toBeNull()
        ->and(DB::table('email_campaign_recipients')
            ->where('email_campaign_id', $campaign->id)->count())->toBe(3);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
