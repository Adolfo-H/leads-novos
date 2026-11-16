<?php

use App\Support\CampaignLocalTime;
use Carbon\CarbonImmutable;

it('converts an SMTP timestamp in UTC to the Sao Paulo display timezone', function (): void {
    expect(CampaignLocalTime::format('2026-11-09 14:24:00+00'))
        ->toBe('09/11/2026 11:24');
});

it('preserves an explicit local UTC offset instead of subtracting three hours twice', function (): void {
    expect(CampaignLocalTime::format('2026-11-09 11:24:00-03'))
        ->toBe('09/11/2026 11:24');
});

it('assumes UTC when a persisted timestamp has no offset', function (): void {
    expect(CampaignLocalTime::format('2026-11-09 14:24:00'))
        ->toBe('09/11/2026 11:24');
});

it('handles null, invalid timestamps and immutable date instances', function (): void {
    $date = CarbonImmutable::parse('2026-11-09T14:24:00Z');

    expect(CampaignLocalTime::format(null))->toBe('—')
        ->and(CampaignLocalTime::format(''))->toBe('—')
        ->and(CampaignLocalTime::format('data-invalida'))->toBe('—')
        ->and(CampaignLocalTime::format($date))->toBe('09/11/2026 11:24');
});

it('uses stored timestamps for the recipient delivery display without modifying mail jobs', function (): void {
    $view = file_get_contents(resource_path('views/pages/campaigns/⚡index.blade.php'));

    expect($view)->not->toBeFalse()
        ->toContain("CampaignLocalTime::format(\$recipient->getRawOriginal('sent_at'))")
        ->toContain("CampaignLocalTime::format(\$latestBounce->getRawOriginal('received_at')")
        ->not->toContain("\$recipient->sent_at?->format('d/m/Y H:i')");
});
