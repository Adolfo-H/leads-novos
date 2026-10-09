<?php

namespace App\Services;

/** All drilldown IDs are computed using the same HubSpot owner source as the chart. */
final class DashboardStageDrilldownService
{
    /** @return list<int> */
    public function companyIdsForStage(int $userId, string $selectedStage): array
    {
        return app(DashboardOwnedDealsV23Service::class)->companyIdsForStage($userId, $selectedStage);
    }
}
