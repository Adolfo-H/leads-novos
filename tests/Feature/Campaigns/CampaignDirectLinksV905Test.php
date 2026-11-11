<?php

use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('opens a campaign with a normal HTTP link when Livewire JavaScript is unavailable', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Diagnóstico links V90.5',
        'subject' => 'Consulta sem envio de mensagens',
        'body' => 'Mensagem de teste que não será enviada.',
        'status' => 'completed',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);
    Mail::fake();
    Queue::fake();
    $this->actingAs($user)
        ->get(route('campaigns.index', ['campanha' => $campaign->id]))
        ->assertOk()
        ->assertSee('Diagnóstico links V90.5')
        ->assertSee('data-ec76-detail="M05-V761"', false)
        ->assertSee('Voltar às campanhas');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('renders browser links for the campaign table without SMTP changes', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $campaign = EmailCampaign::query()->create([
        'name' => 'Campanha listada V90.5',
        'subject' => 'Consulta',
        'body' => 'Esta mensagem não será enviada.',
        'status' => 'completed',
        'from_address' => 'teste@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'teste@example.invalid',
    ]);
    $this->actingAs($user)
        ->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee(route('campaigns.index', ['campanha' => $campaign->id]));
});
