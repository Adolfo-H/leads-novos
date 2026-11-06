<?php

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('groups a followup under its original campaign without sending mail', function (): void {
    Mail::fake();
    Queue::fake();

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $root = EmailCampaign::query()->create([
        'name' => 'Campanha comercial original',
        'subject' => 'Primeiro contato comercial',
        'body' => 'Primeira mensagem para apresentação de serviços.',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.test',
        'status' => 'completed',
        'created_by' => $manager->id,
        'completed_at' => now('UTC'),
    ]);
    $round = EmailCampaign::query()->create([
        'name' => '[Rodada 2] Retomada comercial',
        'subject' => 'Retomando nosso contato',
        'body' => 'Mensagem de continuidade comercial da ExportControl.',
        'from_address' => 'comercial@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'comercial@example.test',
        'status' => 'completed',
        'created_by' => $manager->id,
        'followup_root_campaign_id' => $root->id,
        'followup_round' => 2,
        'completed_at' => now('UTC'),
    ]);

    expect($root->followupRounds()->pluck('id')->all())->toBe([$round->id]);
    expect(EmailCampaign::query()->whereNull('followup_root_campaign_id')->count())->toBe(1);

    $page = $this->actingAs($manager)->get(route('campaigns.index'));
    $page->assertOk()
        ->assertSee('Minhas campanhas')
        ->assertSee('Campanha comercial original')
        ->assertSee('Histórico e programação da campanha')
        ->assertSee('2 rodadas')
        ->assertSee('data-ec80-campaign-root="'.$root->id.'"', false)
        ->assertDontSee('data-ec80-campaign-root="'.$round->id.'"', false);

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('places my campaigns before future rounds and retains the original campaign record', function (): void {
    $home = file_get_contents(resource_path('views/campaigns/figma-home-v68.blade.php'));

    expect($home)->not->toBeFalse();
    expect(strpos((string) $home, 'class="ec68-card ec68-campaigns"'))
        ->toBeLessThan(strpos((string) $home, "@include('campaigns.followup-schedule-home-v4')"));

    $upcoming = file_get_contents(resource_path('views/campaigns/followup-schedule-home-v4.blade.php'));
    expect($upcoming)->toContain('remaining_followups');
});
