<?php

declare(strict_types=1);

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('installs the M05 header independently and keeps the existing V68 header untouched', function (): void {
    $index = (string) file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));
    $new = (string) file_get_contents(resource_path('views/campaigns/figma-detail-header-v761.blade.php'));
    $old = (string) file_get_contents(resource_path('views/campaigns/figma-detail-header-v68.blade.php'));
    $css = (string) file_get_contents(resource_path('css/campaigns-detail-v761.css'));
    $app = (string) file_get_contents(resource_path('css/app.css'));

    expect($index)->toContain("@include('campaigns.figma-detail-header-v761')")
        ->not->toContain("@include('campaigns.figma-detail-header-v68')")
        ->toContain('requestSendConfirmation')
        ->toContain('class="ec76-breakdown"')
        ->and($new)->toContain('CAMPANHAS_DETAIL_FIGMA_V761')
        ->toContain('data-ec76-detail="M05-V761"')
        ->toContain('Andamento dos envios')
        ->toContain('Ver informações da campanha')
        ->toContain('ec68-detail-actions')
        ->toContain('wire:click')
        ->and($old)->toContain('ec68-detail-actions')
        ->and($css)->toContain('.ec76-metrics')
        ->and($app)->toContain("@import './campaigns-detail-v761.css';");
});

it('shows the M05 details without sending mail or scheduling jobs', function (): void {
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    Mail::fake();
    Queue::fake();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha visual V761',
        'subject' => 'Assunto de acompanhamento',
        'body' => 'Conteúdo de teste sem disparar qualquer e-mail comercial.',
        'status' => 'running',
        'from_address' => 'visual@example.invalid',
        'from_name' => 'Teste de campanha',
        'reply_to' => 'visual@example.invalid',
    ]);

    Livewire::actingAs($manager)
        ->test('pages::campaigns.index')
        ->call('openCampaign', $campaign->id)
        ->assertSet('screen', 'detail')
        ->assertSee('data-ec76-detail="M05-V761"', false)
        ->assertSee('Campanha visual V761')
        ->assertSee('Andamento dos envios')
        ->assertSee('Destinatários')
        ->assertSee('Respostas');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
