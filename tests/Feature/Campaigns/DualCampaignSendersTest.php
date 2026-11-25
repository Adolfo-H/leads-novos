<?php

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignInbox;
use App\Services\Campaigns\CampaignSendPolicy;
use App\Services\Campaigns\DualCampaignSenders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function ecDedicatedCampaignV2(string $status = 'draft'): EmailCampaign
{
    return EmailCampaign::query()->create([
        'name' => 'Teste isolado de remetentes',
        'subject' => 'Teste sem SMTP',
        'body' => 'Nenhuma mensagem real.',
        'status' => $status,
        'from_address' => 'legacy@example.invalid',
        'from_name' => 'Teste',
        'reply_to' => 'legacy@example.invalid',
    ]);
}

function ecDedicatedRecipientV2(EmailCampaign $campaign, string $email): EmailCampaignRecipient
{
    return $campaign->recipients()->create([
        'email' => $email, 'normalized_email' => $email, 'status' => 'pending',
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.imap_enabled', true);
    foreach (DualCampaignSenders::KEYS as $key) {
        $address = $key.'@exportcontrol.com.br';
        config()->set('campaign_sender_profiles.'.$key, [
            'address' => $address, 'smtp_host' => 'mail.exportcontrol.com.br',
            'smtp_port' => 465, 'smtp_security' => 'tls', 'smtp_username' => $address,
            'smtp_password' => 'FAKE_TEST_ONLY', 'imap_host' => 'mail.exportcontrol.com.br',
            'imap_port' => 993, 'imap_username' => $address, 'imap_password' => 'FAKE_TEST_ONLY',
        ]);
        // Use the same persistence path as a successful IMAP synchronization.
        // No real mailbox is accessed; this is a synthetic, valid response batch.
        app(CampaignInbox::class)->import($address, [
            'uidvalidity' => '123456', 'last_uid' => 1,
            'initialized' => true, 'messages' => [],
        ]);
    }
});

it('starts disabled and does not modify live flag or the current SMTP policy', function (): void {
    $flag = config('campaign_mail.live_enabled');
    $policy = app(CampaignSendPolicy::class)->effective();
    expect(DualCampaignSenders::state())->toBe(['enabled' => false, 'blocked' => false]);
    DualCampaignSenders::authorize('TESTE — autorização simulada');
    expect(DualCampaignSenders::state()['enabled'])->toBeTrue()
        ->and(config('campaign_mail.live_enabled'))->toBe($flag)
        ->and(app(CampaignSendPolicy::class)->effective())->toBe($policy);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('persists one fixed sender per recipient without changing legacy sends', function (): void {
    DualCampaignSenders::authorize('TESTE — autorização simulada');
    $c = ecDedicatedCampaignV2();
    $one = ecDedicatedRecipientV2($c, 'one@example.invalid');
    $two = ecDedicatedRecipientV2($c, 'two@example.invalid');
    $three = ecDedicatedRecipientV2($c, 'three@example.invalid');
    DB::transaction(static fn () => DualCampaignSenders::prepare($c));
    expect($c->fresh()->getAttribute('sender_mode'))->toBe('dual')
        ->and($one->fresh()->getAttribute('sender_profile'))->toBe('publicidade')
        ->and($two->fresh()->getAttribute('sender_profile'))->toBe('propaganda')
        ->and($three->fresh()->getAttribute('sender_profile'))->toBe('publicidade');
    $legacy = ecDedicatedCampaignV2('paused');
    $legacyRecipient = ecDedicatedRecipientV2($legacy, 'legacy@example.invalid');
    expect(DualCampaignSenders::canSend($legacyRecipient, $legacy))->toBeFalse();
    expect(DualCampaignSenders::canSend($one->fresh(), $c->fresh()))->toBeTrue();
    Mail::assertNothingSent();
});

it('rejects incomplete configuration and stale IMAP instead of switching accounts', function (): void {
    config()->set('campaign_sender_profiles.propaganda.smtp_username', 'wrong@example.invalid');
    expect(DualCampaignSenders::profile('propaganda'))->toBeNull()
        ->and(DualCampaignSenders::ready())->toBeFalse();
    expect(fn () => DualCampaignSenders::authorize('TESTE'))->toThrow(RuntimeException::class);
    Mail::assertNothingSent();
});

it('does not authorize migration of a running legacy campaign', function (): void {
    ecDedicatedCampaignV2('running');
    expect(DualCampaignSenders::ready())->toBeTrue();
    expect(fn () => DualCampaignSenders::authorize('TESTE'))->toThrow(RuntimeException::class, 'Conclua ou pause as campanhas antigas e aguarde as tentativas em andamento. Nenhuma delas será transferida.');
    expect(DualCampaignSenders::state()['enabled'])->toBeFalse();
});

it('rejects a new campaign containing recipients still pending in a paused legacy campaign', function (): void {
    $legacy = ecDedicatedCampaignV2('paused');
    ecDedicatedRecipientV2($legacy, 'same@example.invalid');
    DualCampaignSenders::authorize('TESTE');
    $draft = ecDedicatedCampaignV2();
    $recipient = ecDedicatedRecipientV2($draft, 'same@example.invalid');
    expect(fn () => DB::transaction(static fn () => DualCampaignSenders::prepare($draft)))->toThrow(ValidationException::class);
    expect($recipient->fresh()->getAttribute('sender_profile'))->toBeNull();
});

it('persists a global hold after an uncertain SMTP result without changing configuration', function (): void {
    DualCampaignSenders::authorize('TESTE');
    $c = ecDedicatedCampaignV2();
    $one = ecDedicatedRecipientV2($c, 'one@example.invalid');
    $two = ecDedicatedRecipientV2($c, 'two@example.invalid');
    DB::transaction(static fn () => DualCampaignSenders::prepare($c));
    DualCampaignSenders::halt();
    expect(DualCampaignSenders::state())->toBe(['enabled' => true, 'blocked' => true])
        ->and(DualCampaignSenders::canSend($one->fresh(), $c->fresh()))->toBeFalse()
        ->and(DualCampaignSenders::canSend($two->fresh(), $c->fresh()))->toBeFalse();
    expect(fn () => DualCampaignSenders::authorize('TESTE'))->toThrow(RuntimeException::class);
    Mail::assertNothingSent();
});

it('uses the assigned account for both From and Reply-To', function (): void {
    $message = new ProspectorCampaignMessage('Teste', 'Corpo fictício', null, null, false, 'propaganda@exportcontrol.com.br');
    $envelope = $message->envelope();
    expect($envelope->from->address)->toBe('propaganda@exportcontrol.com.br')
        ->and($envelope->replyTo[0]->address)->toBe('propaganda@exportcontrol.com.br');
});

it('persists an explicitly zoned dedicated cursor and rejects stale or future timestamps', function (): void {
    expect(DualCampaignSenders::ready())->toBeTrue();
    $query = DB::table('email_campaign_inbox_cursors')->where('mailbox', 'publicidade@exportcontrol.com.br');
    $raw = $query->value('last_synced_at');
    expect(is_string($raw))->toBeTrue();
    expect((string) $raw)->toMatch('/(?:Z|[+-]\d{2}(?::?\d{2})?)$/iD');
    $query->update(['last_synced_at' => now('UTC')->subMinutes(16)->toIso8601String()]);
    expect(DualCampaignSenders::ready())->toBeFalse();
    $query->update(['last_synced_at' => now('UTC')->addHour()->toIso8601String()]);
    expect(DualCampaignSenders::ready())->toBeFalse();
    app(CampaignInbox::class)->import('publicidade@exportcontrol.com.br', [
        'uidvalidity' => '123456', 'last_uid' => 2, 'initialized' => false, 'messages' => [],
    ]);
    expect(DualCampaignSenders::ready())->toBeTrue();
    Mail::assertNothingSent();
});

it('keeps the existing single scheduled IMAP command and does not require live sending for synchronization', function (): void {
    $console = (string) file_get_contents(base_path('routes/console.php'));
    expect(substr_count($console, "'campaigns:sync-replies'"))->toBe(1)
        ->and($console)->toContain("Schedule::command('campaigns:sync-replies')->everyFiveMinutes()->onOneServer()->withoutOverlapping()");
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.imap_enabled', false);
    $this->artisan('campaigns:sync-replies')->expectsOutput('IMAP desabilitado. Habilite CAMPAIGN_IMAP_ENABLED após os testes.')->assertExitCode(0);
    Mail::assertNothingSent();
});
