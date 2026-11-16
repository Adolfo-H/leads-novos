<?php

use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
});

function ecV81Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows only step one before analysis and never sends email on page load', function (): void {
    $this->actingAs(ecV81Manager())
        ->get(route('campaigns.prospecting.index'))
        ->assertOk()
        ->assertSee('1. Encontrar empresas para prospectar')
        ->assertDontSee('2. Revisar o resultado da análise')
        ->assertDontSee('3. Preparar nova campanha')
        ->assertDontSee('name="subject"', false)
        ->assertDontSee('ExportControl · Campanhas');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('displays results followed by the campaign draft form only after analysis with eligible email', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '8100011',
        'first_name' => 'Prospecção',
        'last_name' => 'Visual',
        'email' => 'prospeccao-visual-v81@example.org',
    ]);

    $this->actingAs(ecV81Manager())
        ->get(route('campaigns.prospecting.index', ['analyze' => '1', 'origin' => 'hubspot']))
        ->assertOk()
        ->assertSee('1. Encontrar empresas para prospectar')
        ->assertSee('2. Revisar o resultado da análise')
        ->assertSee('3. Preparar nova campanha')
        ->assertSee('prospeccao-visual-v81@example.org')
        ->assertSee('name="subject"', false);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('hides the draft form when analysis has zero eligible recipients', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '8100012',
        'first_name' => 'Cliente',
        'email' => 'cliente-v81@example.org',
        'lifecycle_stage' => 'customer',
    ]);

    $this->actingAs(ecV81Manager())
        ->get(route('campaigns.prospecting.index', ['analyze' => '1', 'origin' => 'hubspot']))
        ->assertOk()
        ->assertSee('2. Revisar o resultado da análise')
        ->assertDontSee('3. Preparar nova campanha')
        ->assertDontSee('name="subject"', false);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not display ExportControl above Campanhas in the top bar of the campaigns module', function (): void {
    $source = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));

    expect($source)->not->toBeFalse()
        ->toContain("'access.requests.*', 'contacts.whatsapp', 'campaigns.*'))");
});
