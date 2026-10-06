<?php

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\HubSpotRefreshRun;
use Illuminate\Support\Facades\Queue;

it('queues every company already linked to HubSpot on startup', function () {
    Queue::fake();

    $one =
        Company::query()->create([
            'cnpj_root' => '81111111',
            'corporate_name' => 'Startup One',
        ]);

    $two =
        Company::query()->create([
            'cnpj_root' => '82222222',
            'corporate_name' => 'Startup Two',
        ]);

    $withoutLocalDeal =
        Company::query()->create([
            'cnpj_root' => '83333333',
            'corporate_name' => 'Startup Without Local Deal',
        ]);

    $notLinked =
        Company::query()->create([
            'cnpj_root' => '84444444',
            'corporate_name' => 'Startup Not Linked',
        ]);

    $one->hubSpotLead()->create([
        'hubspot_company_id' => 'company-one',
        'hubspot_deal_id' => 'deal-one',
        'metadata' => [],
    ]);

    $two->hubSpotLead()->create([
        'hubspot_company_id' => 'company-two',
        'hubspot_deal_id' => 'deal-two',
        'metadata' => [],
    ]);

    /*
     * Precisa entrar também:
     *
     * o HubSpot pode ter negócio novo ainda
     * não projetado no banco local.
     */
    $withoutLocalDeal
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => 'company-three',
            'hubspot_deal_id' => null,
            'metadata' => [],
        ]);

    $notLinked
        ->hubSpotLead()
        ->create([
            'hubspot_company_id' => null,
            'hubspot_deal_id' => null,
            'metadata' => [],
        ]);

    $this
        ->artisan(
            'hubspot:startup-refresh',
            [
                '--limit' => 0,
            ]
        )
        ->assertSuccessful();

    $run =
        HubSpotRefreshRun::query()
            ->latest('id')
            ->firstOrFail();

    expect(
        $run->total
    )->toBe(3);

    expect(
        $run
            ->items()
            ->count()
    )->toBe(3);

    Queue::assertPushed(
        RefreshCompanyFromHubSpot::class,
        3
    );
});
