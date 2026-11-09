<?php

use App\Models\HubSpotContact;
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

function ecV83Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

it('removes one email from only the managers prospect list and permits restoring it', function (): void {
    $user = ecV83Manager();
    $other = ecV83Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '8300001', 'first_name' => 'Contato A', 'email' => 'um-v83@example.org',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '8300002', 'first_name' => 'Contato B', 'email' => 'dois-v83@example.org',
    ]);

    $audience = app(CampaignProspectingAudienceV80::class);
    $filters = ['origin' => 'hubspot', 'days' => 90];
    expect($audience->query($filters, (int) $user->id)->count())->toBe(2);

    $this->actingAs($user)->post(route('campaigns.prospecting.exclude'), [
        ...$filters, 'email' => 'UM-v83@example.org', 'page' => 1,
    ])->assertRedirect();

    expect(DB::table('email_campaign_prospecting_exclusions_v83')
        ->where('user_id', $user->id)->where('normalized_email', 'um-v83@example.org')->exists())->toBeTrue()
        ->and($audience->query($filters, (int) $user->id)->pluck('normalized_email')->all())
        ->toBe(['dois-v83@example.org'])
        ->and($audience->query($filters, (int) $other->id)->count())->toBe(2);

    $this->actingAs($user)->get(route('campaigns.prospecting.index', ['analyze' => '1', ...$filters]))
        ->assertOk()->assertDontSee('aria-label="Retirar um-v83@example.org', false)
        ->assertSee('dois-v83@example.org');

    $this->actingAs($user)->post(route('campaigns.prospecting.restore'), [
        ...$filters, 'email' => 'um-v83@example.org', 'page' => 1,
    ])->assertRedirect();
    expect($audience->query($filters, (int) $user->id)->count())->toBe(2);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not let a manager exclude another unrelated email from a filtered audience', function (): void {
    $user = ecV83Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '8300101', 'first_name' => 'Empresa', 'email' => 'lead-v83@example.org',
    ]);
    $this->actingAs($user)->post(route('campaigns.prospecting.exclude'), [
        'origin' => 'hubspot', 'email' => 'nao-existe-v83@example.org',
    ])->assertSessionHasErrors(['email']);

    expect(DB::table('email_campaign_prospecting_exclusions_v83')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders the remove action, preserves status contrast and protects WhatsApp pairing when offline', function (): void {
    $view = file_get_contents(resource_path('views/campaigns/prospecting-v80.blade.php'));
    $campaigns = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $pairing = file_get_contents(resource_path('views/pages/contacts/⚡whatsapp.blade.php'));
    expect($view)->not->toBeFalse()->toContain("route('campaigns.prospecting.exclude')")
        ->toContain("route('campaigns.prospecting.restore')")
        ->and($campaigns)->not->toBeFalse()->toContain('EC_CAMPAIGN_STATUS_CONTRAST_V83')
        ->and($pairing)->not->toBeFalse()->toContain('EC_WA_CONNECTOR_GUARD_V83');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
