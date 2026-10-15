<?php

use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignImapHealth;
use App\Services\Campaigns\CampaignInbox;

function imapMonitorConfig(): void
{
    config()->set('campaign_mail.imap_enabled', true);
    config()->set('campaign_mail.imap_host', 'mail.example.test');
    config()->set('campaign_mail.imap_username', 'box@example.test');
    config()->set('campaign_mail.imap_password', 'senha-ficticia-nao-real');
    config()->set('campaign_mail.imap_stale_minutes', 15);
    config()->set('campaign_mail.live_enabled', false);
}

it('identifies an uninitialized IMAP cursor without connecting to the mailbox', function (): void {
    imapMonitorConfig();
    $health = app(CampaignImapHealth::class)->snapshot();

    expect($health['state'])->toBe('not_initialized');
    expect($health['tracked_replies'])->toBe(0);
    expect($health['campaign_replies'])->toBe(0);

    $this->artisan('campaigns:imap-status', ['--check' => true])
        ->expectsOutput('Estado IMAP: Aguardando primeira sincronização')
        ->assertExitCode(1);
});

it('reports a healthy cursor and identifies an overdue cursor', function (): void {
    imapMonitorConfig();
    $cursor = EmailCampaignInboxCursor::query()->create([
        'mailbox' => 'box@example.test',
        'uid_validity' => '123456',
        'last_uid' => 42,
        'last_synced_at' => now()->subMinutes(4),
    ]);

    $health = app(CampaignImapHealth::class)->snapshot();
    expect($health['state'])->toBe('healthy');
    expect($health['last_uid'])->toBe(42);

    $this->artisan('campaigns:imap-status', ['--check' => true])
        ->expectsOutput('Estado IMAP: Sincronização recente')
        ->assertExitCode(0);

    $cursor->update(['last_synced_at' => now()->subMinutes(30)]);
    expect(app(CampaignImapHealth::class)->snapshot()['state'])->toBe('stale');
    $this->artisan('campaigns:imap-status', ['--check' => true])
        ->expectsOutput('Estado IMAP: Sincronização atrasada')
        ->assertExitCode(1);
});

it('never treats IMAP enabled as proof of live campaign sending', function (): void {
    imapMonitorConfig();
    config()->set('campaign_mail.imap_enabled', false);
    config()->set('campaign_mail.live_enabled', false);

    expect(app(CampaignImapHealth::class)->snapshot()['state'])->toBe('disabled');
    $this->artisan('campaigns:imap-status')->assertExitCode(0);
    expect((bool) config('campaign_mail.live_enabled'))->toBeFalse();
});

it('recognizes the same tracked reply once without raising commercial reply metrics', function (): void {
    imapMonitorConfig();
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $campaign = EmailCampaign::query()->create([
        'name' => 'Diagnóstico IMAP',
        'subject' => 'Mensagem de teste',
        'body' => 'Corpo da mensagem de diagnóstico.',
        'status' => 'draft',
        'from_address' => 'box@example.test',
        'from_name' => 'ExportControl',
        'reply_to' => 'box@example.test',
        'created_by' => $manager->id,
    ]);

    $test = EmailCampaignTestMessage::query()->create([
        'email_campaign_id' => $campaign->id,
        'test_to' => 'second@example.test',
        'message_id' => 'test-123@example.test',
        'status' => 'smtp_accepted',
        'attempted_at' => now(),
        'sent_at' => now(),
        'created_by' => $manager->id,
    ]);

    $batch = [
        'uidvalidity' => '123456',
        'last_uid' => 21,
        'initialized' => false,
        'messages' => [[
            'uid' => 21,
            'from_email' => 'second@example.test',
            'in_reply_to' => '<test-123@example.test>',
            'references' => '',
            'auto_submitted' => '',
            'subject' => 'Re: Mensagem de teste',
            'received_at' => now()->toIso8601String(),
        ]],
    ];

    expect(app(CampaignInbox::class)->import('box@example.test', $batch))->toBe(1);
    expect(app(CampaignInbox::class)->import('box@example.test', $batch))->toBe(0);
    expect($test->fresh()->replied_at)->not->toBeNull();
    expect(EmailCampaignReply::query()->count())->toBe(0);
    expect(app(CampaignImapHealth::class)->snapshot()['tracked_replies'])->toBe(1);
});

it('shows monitoring only inside the existing manager-only campaigns page', function (): void {
    imapMonitorConfig();
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($seller)->get(route('campaigns.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('campaigns.index'))
        ->assertOk()
        ->assertSee('Última sincronização IMAP');
});

it('schedules inbox synchronization once without requiring live campaign sending', function (): void {
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)->toContain("Schedule::command('campaigns:sync-replies')")
        ->toContain('->everyFiveMinutes()')
        ->toContain('->withoutOverlapping()');
    expect(substr_count($console, "'campaigns:sync-replies'"))->toBe(1);
});
