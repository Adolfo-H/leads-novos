<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function ecCampaignV72Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows only the three primary sections in campaign navigation', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(ecCampaignV72Manager());

    foreach (['campaigns.index', 'campaigns.replies', 'campaigns.settings'] as $route) {
        $this->get(route($route))
            ->assertSuccessful()
            ->assertSee('data-ec72-core-nav="true"', false)
            ->assertSee('Campanhas')
            ->assertSee('Respostas')
            ->assertSee('Configurações gerais')
            ->assertDontSee('Ferramentas avançadas')
            ->assertDontSee('Fila de envios')
            ->assertDontSee('Mais opções');
    }

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('preserves campaign workflows and removes retired navigation entry points', function (): void {
    $nav = file_get_contents(resource_path('views/campaigns/module-nav.blade.php'));
    $index = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $settings = file_get_contents(resource_path('views/pages/campaigns/⚡settings.blade.php'));
    $replies = file_get_contents(resource_path('views/pages/campaigns/⚡replies.blade.php'));
    $css = file_get_contents(resource_path('css/campaigns-core-v72.css'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($nav)->not->toBeFalse()
        ->and($index)->not->toBeFalse()
        ->and($settings)->not->toBeFalse()
        ->and($replies)->not->toBeFalse()
        ->and($css)->not->toBeFalse()
        ->and($appCss)->not->toBeFalse();

    expect((string) $nav)
        ->toContain('EC_CAMPAIGNS_CORE_V72')
        ->not->toContain('ec69-more-options')
        ->not->toContain("route('campaigns.queue')")
        ->not->toContain("route('campaigns.advanced')")
        ->not->toContain("route('campaigns.production.index')")
        ->not->toContain("route('campaigns.intelligence.index')");

    expect((string) $settings)->not->toContain('ec22-settings-tools');
    expect((string) $replies)->not->toContain('ec69-internal-filters');
    expect((string) $index)->toContain('saveAndStart')->toContain('lawfulBasisConfirmed');
    expect((string) $css)->toContain('.ecm6-advanced')->toContain('display: none !important');
    expect(substr_count((string) $appCss, "@import './campaigns-core-v72.css';"))->toBe(1);
});
