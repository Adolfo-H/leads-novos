<?php

use App\Jobs\EnrichImportItem;
use App\Jobs\ResearchCompanyExports;

it('keeps redis retry after above the longest queued job timeout', function () {
    $retryAfter =
        (int) config(
            'queue.connections.redis.retry_after'
        );

    $longestJobTimeout =
        max(
            (new EnrichImportItem(1))->timeout,
            (new ResearchCompanyExports(1))->timeout,
        );

    expect(
        $retryAfter
    )->toBeGreaterThan(
        $longestJobTimeout
    );
});

it('keeps database retry after above the longest queued job timeout', function () {
    $retryAfter =
        (int) config(
            'queue.connections.database.retry_after'
        );

    $longestJobTimeout =
        max(
            (new EnrichImportItem(1))->timeout,
            (new ResearchCompanyExports(1))->timeout,
        );

    expect(
        $retryAfter
    )->toBeGreaterThan(
        $longestJobTimeout
    );
});
