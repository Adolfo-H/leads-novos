<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignPacing;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecIndependentCampaign(string $status = 'running'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Teste independente de ritmo',
        'subject' => 'Assunto de teste',
        'body' => 'Nenhuma mensagem real deve ser enviada nos testes.',
        'status' => $status,
        'from_address' => 'sender@example.invalid',
        'from_name' => 'Somente teste',
        'reply_to' => 'sender@example.invalid',
    ]);
}

function ecIndependentRecipient(EmailCampaign $campaign, string $status, int $suffix): EmailCampaignRecipient
{
    return $campaign->recipients()->create([
        'email' => 'camp'.$campaign->id.'-'.$suffix.'@example.invalid',
        'normalized_email' => 'camp'.$campaign->id.'-'.$suffix.'@example.invalid',
        'status' => $status,
    ]);
}

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.per_hour', 1000);
    config()->set('campaign_mail.per_day', 2000);
    config()->set('campaign_mail.per_tick', 17);
    config()->set('campaign_mail.smtp_approved_per_hour', 1000);
    config()->set('campaign_mail.smtp_approved_per_day', 2000);
    config()->set('campaign_mail.smtp_approved_per_tick', 17);
    Mail::fake();
    Queue::fake();
});

it('não bloqueia nova campanha de 1/h por tentativa recente de outra campanha', function (): void {
    $old = ecIndependentCampaign('completed');
    $alreadySent = ecIndependentRecipient($old, 'sent', 1);
    $alreadySent->update(['attempted_at' => now('UTC')->subMinutes(5), 'attempts' => 1]);

    $new = ecIndependentCampaign();
    $pending = ecIndependentRecipient($new, 'pending', 1);
    app(CampaignPacing::class)->save($new, 8);
    expect(app(CampaignPacing::class)->limits((int) $new->id))
        ->toBe(['hour' => 1, 'tick' => 1]);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($pending->fresh()->status)->toBe('reserved')
        ->and($alreadySent->fresh()->status)->toBe('sent');
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('pula campanha antiga que atingiu a propria cota sem bloquear a seguinte', function (): void {
    $first = ecIndependentCampaign();
    $sent = ecIndependentRecipient($first, 'sent', 1);
    $sent->update(['attempted_at' => now('UTC')->subMinutes(8), 'attempts' => 1]);
    $blocked = ecIndependentRecipient($first, 'pending', 2);
    app(CampaignPacing::class)->save($first, 8);

    $second = ecIndependentCampaign();
    $allowed = ecIndependentRecipient($second, 'pending', 3);
    app(CampaignPacing::class)->save($second, 8);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($blocked->fresh()->status)->toBe('pending')
        ->and($allowed->fresh()->status)->toBe('reserved');
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('mantém um teto global horario que realmente impede excedentes', function (): void {
    config()->set('campaign_mail.per_hour', 1);
    config()->set('campaign_mail.smtp_approved_per_hour', 1);
    $old = ecIndependentCampaign('completed');
    $sent = ecIndependentRecipient($old, 'sent', 1);
    $sent->update(['attempted_at' => now('UTC')->subMinutes(5), 'attempts' => 1]);
    $new = ecIndependentCampaign();
    $pending = ecIndependentRecipient($new, 'pending', 2);
    app(CampaignPacing::class)->save($new, 8);

    expect(app(CampaignDispatcher::class)->tick())->toBe(0)
        ->and($pending->fresh()->status)->toBe('pending');
    Queue::assertNothingPushed();
    Mail::assertNothingSent();
});

it('limita o total por minuto mesmo quando ha varias campanhas elegiveis', function (): void {
    config()->set('campaign_mail.per_tick', 1);
    config()->set('campaign_mail.smtp_approved_per_tick', 1);
    $first = ecIndependentCampaign();
    $one = ecIndependentRecipient($first, 'pending', 1);
    app(CampaignPacing::class)->save($first, 8);
    $second = ecIndependentCampaign();
    $two = ecIndependentRecipient($second, 'pending', 2);
    app(CampaignPacing::class)->save($second, 8);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($one->fresh()->status)->toBe('reserved')
        ->and($two->fresh()->status)->toBe('pending');
    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});
