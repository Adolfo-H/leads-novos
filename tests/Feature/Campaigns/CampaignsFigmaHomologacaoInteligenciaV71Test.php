<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function v71CampaignManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows the M11 light homologation layout without starting a campaign', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(v71CampaignManager())
        ->get(route('campaigns.production.index'))
        ->assertOk()
        ->assertSee('data-campaign-figma="M11-V71"', false)
        ->assertSee('Homologação de envios')
        ->assertSee('Capacidade e autorização')
        ->assertSee('Revisão operacional')
        ->assertSee('URL pública HTTPS')
        ->assertSee('Salvar evidências');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('shows the M12 light intelligence layout with all original workflows', function (): void {
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $this->actingAs(v71CampaignManager())
        ->get(route('campaigns.intelligence.index'))
        ->assertOk()
        ->assertSee('data-campaign-figma="M12-V71"', false)
        ->assertSee('Inteligência comercial')
        ->assertSee('Regras do segmento')
        ->assertSee('Prévia do segmento')
        ->assertSee('Criar campanha em rascunho')
        ->assertSee('Planejamento de follow-up')
        ->assertSee('Planos de follow-up')
        ->assertSee('Atendimento individual');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps explicit forms and the scoped light theme', function (): void {
    $style = file_get_contents(resource_path('views/campaigns/figma-style-v71.blade.php'));
    $production = file_get_contents(resource_path('views/campaigns/figma-production-v71.blade.php'));
    $intelligence = file_get_contents(resource_path('views/campaigns/figma-intelligence-v71.blade.php'));
    $controller = file_get_contents(app_path('Http/Controllers/CampaignProductionReadinessController.php'));

    expect($style)->toContain('background: #fff !important')
        ->toContain('.ec-sidebar-world-v7')
        ->and($production)->toContain('campaigns.production.save')
        ->toContain('smtp_per_day')
        ->toContain('public_tested')
        ->and($intelligence)->toContain('campaigns.intelligence.segments.save')
        ->toContain('campaigns.intelligence.draft')
        ->toContain('campaigns.intelligence.sequence')
        ->toContain('campaigns.intelligence.prepare')
        ->toContain('campaigns.intelligence.reply')
        ->and($controller)->toContain('smtp_approved_per_hour')
        ->toContain('smtp_approved_per_day');
});
