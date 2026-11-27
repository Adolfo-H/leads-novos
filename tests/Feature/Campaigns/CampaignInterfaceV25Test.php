<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignTimingPreview;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('estimates the existing per campaign cadence without multiplying global quotas', function (): void {
    $policy = ['hour' => 100, 'tick' => 2, 'day' => 24000];
    expect(CampaignTimingPreview::estimate(2, 8, $policy))->toBe(['hour' => 1, 'tick' => 1, 'day' => 24000, 'minutes' => 61])
        ->and(CampaignTimingPreview::estimate(10, 1, $policy))->toBe(['hour' => 10, 'tick' => 1, 'day' => 24000, 'minutes' => 10]);
    expect(CampaignTimingPreview::estimate(1000, 1, ['hour' => 100, 'tick' => 2, 'day' => 300]))->toBe([
        'hour' => 100, 'tick' => 2, 'day' => 300, 'minutes' => 4370,
    ]);
    expect(CampaignTimingPreview::label(1501))->toBe('1 dia 1 h 1 min');
});

it('uses precisely the five supported personalization markers and honest fallback values', function (): void {
    $view = view('campaigns.message-variables-v25')->render();
    $renderer = app(CampaignContent::class);
    $recipient = new EmailCampaignRecipient(['contact_name' => 'Ana Silva', 'company_name' => 'Cerealista Exemplo', 'job_title' => 'Gerente fiscal']);
    $empty = new EmailCampaignRecipient;
    $source = '{{saudacao}}|{{primeiro_nome}}|{{nome}}|{{empresa}}|{{cargo}}';
    foreach (explode('|', $source) as $marker) {
        expect($view)->toContain($marker);
    }
    expect($renderer->render($source, $recipient))->toBe('Olá, Ana!|Ana|Ana Silva|Cerealista Exemplo|Gerente fiscal')
        ->and($renderer->render($source, $empty))->toBe('Olá!|Olá|Contato|sua empresa|profissional');
});

it('shows a read only estimate in wizard rhythm and review and preserves the selected duration', function (): void {
    Mail::fake();
    Queue::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.per_hour', 100);
    config()->set('campaign_mail.per_tick', 2);
    config()->set('campaign_mail.per_day', 24000);
    config()->set('campaign_mail.smtp_approved_per_hour', 100);
    config()->set('campaign_mail.smtp_approved_per_tick', 2);
    config()->set('campaign_mail.smtp_approved_per_day', 24000);
    $wizardId = '93050000-0000-4000-8000-000000000025';
    $key = 'campaigns_wizard_v93_'.$wizardId;
    $state = ['expected' => ['hubspot:1' => 'ana@example.invalid', 'hubspot:2' => 'bia@example.invalid'], 'name' => 'Previsão V25', 'subject' => 'Assunto', 'body' => 'Mensagem fictícia para validação.', 'hours' => '8', 'model' => 'initial'];
    $this->actingAs($user)->withSession([$key => $state])->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'mensagem']))
        ->assertOk()->assertSee('Personalize sua mensagem')->assertSee('{{saudacao}}');
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'ritmo']))
        ->assertOk()->assertSee('Tempo estimado após iniciar')->assertSee('1 h 1 min')->assertSee('Limites globais vigentes')->assertSee('Envio desabilitado ou bloqueado');
    $this->actingAs($user)->get(route('campaigns.wizard-v93', ['wizard_id' => $wizardId, 'passo' => 'revisao']))
        ->assertOk()->assertSee('Tempo estimado após iniciar')->assertSee('1 h 1 min');
    expect(session($key)['hours'])->toBe('8')->and(EmailCampaign::query()->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('keeps cancellation inside a deliberate protected form with a safe default action', function (): void {
    Mail::fake();
    Queue::fake();
    $campaign = new EmailCampaign(['name' => 'Rascunho <seguro>', 'status' => 'draft']);
    $campaign->id = 42;
    $html = view('campaigns.cancel-confirmation-v25', ['selected' => $campaign])->render();
    expect($html)->toContain('<dialog')->toContain('Confirmar cancelamento')->toContain('Rascunho &lt;seguro&gt;')
        ->toContain(route('campaigns.ops-v94.cancel', ['campaign' => 42]))->toContain('type="button" autofocus')
        ->not->toContain('window.confirm');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
