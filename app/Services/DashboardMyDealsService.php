<?php

namespace App\Services;

/** Backwards-compatible entry point for the Dashboard V6 service. */
final class DashboardMyDealsService
{
    /** @return array{total:int, stages:list<array{label:string,value:int,tone:string}>} */
    public function forUser(int $userId): array
    {
        return app(DashboardOwnedDealsV23Service::class)->forUser($userId);
    }
}
