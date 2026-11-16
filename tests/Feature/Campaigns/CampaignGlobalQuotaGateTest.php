<?php

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignReservationReview;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function ecCampaignV4Fixture(string $campaignStatus = 'running'): EmailCampaign
{
    $campaign = EmailCampaign::query()->create([
        'name' => 'Homologação V4',
        'subject' => 'Teste de segurança',
        'body' => 'Nenhum destinatário de verdade recebe este e-mail.',
        'status' => $campaignStatus,
        'from_address' => 'test@example.invalid',
        'from_name' => 'Testes ExportControl',
        'reply_to' => 'test@example.invalid',
    ]);

    EmailCampaignRecipient::query()->create([
        'email_campaign_id' => $campaign->id,
        'email' => 'teste'.$campaign->id.'@example.invalid',
        'normalized_email' => 'teste'.$campaign->id.'@example.invalid',
        'status' => 'pending',
    ]);

    return $campaign;
}

beforeEach(function (): void {
    config()->set('campaign_mail.enabled', true);
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.from_address', 'test@example.invalid');
    config()->set('campaign_mail.per_hour', 25);
    config()->set('campaign_mail.per_day', 500);
    config()->set('campaign_mail.per_tick', 1);
    config()->set('campaign_mail.queue', 'campaigns');
    config()->set('app.url', 'https://prospector.exportcontrol.com.br');
    Mail::fake();
    Queue::fake();
});

it('creates a permanent global quota gate independent of campaign records', function (): void {
    expect(DB::table('email_campaign_dispatch_gates')->where('id', 1)->count())->toBe(1);

    $first = ecCampaignV4Fixture('draft');
    $second = ecCampaignV4Fixture('running');
    $first->delete();
    config()->set('campaign_mail.live_enabled', true);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and($second->recipients()->firstOrFail()->status)->toBe('reserved');

    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('shares one rolling hourly quota across campaigns while leaving email fake', function (): void {
    $first = ecCampaignV4Fixture();
    $second = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    config()->set('campaign_mail.per_hour', 1);

    expect(app(CampaignDispatcher::class)->tick())->toBe(1)
        ->and(app(CampaignDispatcher::class)->tick())->toBe(0)
        ->and($first->recipients()->where('status', 'reserved')->count()
            + $second->recipients()->where('status', 'reserved')->count())->toBe(1);

    Queue::assertPushed(SendEmailCampaignRecipient::class, 1);
    Mail::assertNothingSent();
});

it('does not release a newly reserved recipient or alter another campaign', function (): void {
    $first = ecCampaignV4Fixture();
    $other = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $first->recipients()->firstOrFail();

    expect(app(CampaignReservationReview::class)->release(
        (int) $recipient->id, 'Somente diagnóstico: sem tentativa concluída'
    ))->toBeFalse()
        ->and($recipient->fresh()->status)->toBe('reserved')
        ->and($other->recipients()->firstOrFail()->status)->toBe('pending')
        ->and(DB::table('email_campaign_reservation_reviews')->count())->toBe(0);

    Mail::assertNothingSent();
});

it('releases only old unstarted reservations and audits the operation', function (): void {
    $campaign = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['reserved_at' => now('UTC')->subMinutes(40)]);

    $review = app(CampaignReservationReview::class);
    expect($review->inspect((int) $recipient->id)['eligible'])->toBeTrue();
    expect($review->release((int) $recipient->id, 'Verificado worker Redis, nenhum envio iniciado'))->toBeTrue();

    expect($recipient->fresh()->status)->toBe('pending')
        ->and($recipient->fresh()->reservation_token)->toBeNull()
        ->and($recipient->fresh()->reserved_at)->toBeNull()
        ->and(DB::table('email_campaign_reservation_reviews')->count())->toBe(1)
        ->and($review->release((int) $recipient->id, 'Tentativa duplicada de recuperação humana'))->toBeFalse();
    Mail::assertNothingSent();
});

it('refuses to release anything that might have been handed to SMTP', function (): void {
    $campaign = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update([
        'reserved_at' => now('UTC')->subMinutes(40),
        'attempted_at' => now('UTC')->subMinutes(39),
        'attempts' => 1,
        'message_id' => 'teste-v4@example.invalid',
    ]);

    expect(app(CampaignReservationReview::class)->release(
        (int) $recipient->id, 'A tentativa foi iniciada, bloqueio obrigatório'
    ))->toBeFalse()
        ->and($recipient->fresh()->status)->toBe('reserved')
        ->and(DB::table('email_campaign_reservation_reviews')->count())->toBe(0);

    $recipient->update(['status' => 'uncertain']);
    expect(app(CampaignReservationReview::class)->release(
        (int) $recipient->id, 'Resultado incerto nunca é reenviado automaticamente'
    ))->toBeFalse();
    Mail::assertNothingSent();
});

it('invalidates the old Redis job after a manual release and new reservation', function (): void {
    $campaign = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $old = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);

    $recipient->update(['reserved_at' => now('UTC')->subMinutes(40)]);
    expect(app(CampaignReservationReview::class)->release(
        (int) $recipient->id, 'Auditoria do Redis: o job anterior não começou'
    ))->toBeTrue();
    expect(app(CampaignDispatcher::class)->tick())->toBe(1);

    $recipient->refresh();
    $new = new SendEmailCampaignRecipient((int) $recipient->id, (string) $recipient->reservation_token);

    $old->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));
    expect($recipient->fresh()->status)->toBe('reserved');
    Mail::assertNothingSent();

    $new->handle(app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class));
    expect($recipient->fresh()->status)->toBe('sent')
        ->and($recipient->fresh()->attempts)->toBe(1);
    Mail::assertSentCount(1);
});

it('rejects a legacy job with no reservation token even if the database also lacks it', function (): void {
    $campaign = ecCampaignV4Fixture();
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['status' => 'reserved', 'reserved_at' => now('UTC'), 'reservation_token' => null]);
    config()->set('campaign_mail.live_enabled', true);

    (new SendEmailCampaignRecipient((int) $recipient->id))->handle(
        app(CampaignDispatcher::class), app(CampaignContent::class), app(UnsubscribeToken::class)
    );

    expect($recipient->fresh()->status)->toBe('reserved')
        ->and($recipient->fresh()->attempts)->toBe(0);
    Mail::assertNothingSent();
});

it('allows inspecting reservations without changing the database and requires explicit confirmation', function (): void {
    $campaign = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['reserved_at' => now('UTC')->subMinutes(40)]);

    $id = (int) $recipient->id;
    expect(Artisan::call('campaigns:review-reservation', ['recipient' => $id]))->toBe(0)
        ->and($recipient->fresh()->status)->toBe('reserved');
    expect(Artisan::call('campaigns:review-reservation', [
        'recipient' => $id,
        '--apply' => true,
        '--confirm' => '999999',
        '--reason' => 'Operação ainda não confirmada pelo operador',
    ]))->toBe(1)
        ->and($recipient->fresh()->status)->toBe('reserved');
    Mail::assertNothingSent();
});

it('refuses to release an old reservation from a canceled campaign', function (): void {
    $campaign = ecCampaignV4Fixture();
    config()->set('campaign_mail.live_enabled', true);
    app(CampaignDispatcher::class)->tick();
    $recipient = $campaign->recipients()->firstOrFail();
    $recipient->update(['reserved_at' => now('UTC')->subMinutes(40)]);
    $campaign->update(['status' => 'canceled']);

    expect(app(CampaignReservationReview::class)->release(
        (int) $recipient->id, 'Campanha cancelada, não pode recuperar reserva'
    ))->toBeFalse();
    Mail::assertNothingSent();
});

it('refuses the PostgreSQL lock probe when live campaigns are enabled', function (): void {
    config()->set('campaign_mail.live_enabled', true);
    expect(Artisan::call('campaigns:probe-quota-lock', ['--hold' => '0']))->toBe(1);
    Mail::assertNothingSent();
});
