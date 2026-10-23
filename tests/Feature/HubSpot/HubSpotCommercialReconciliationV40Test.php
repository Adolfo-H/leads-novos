<?php

use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use Illuminate\Support\Facades\Http;

it('fetches the real stage catalog and candidate deals in preview without mutating the mirror', function (): void {
    config([
        'services.hubspot.access_token' => 'v40-test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.hubapi.com/crm/v3/pipelines/deals' => Http::response([
            'results' => [[
                'id' => 'default',
                'label' => 'Pipeline de vendas',
                'stages' => [
                    ['id' => '122191633', 'label' => 'Reunião Agendada', 'displayOrder' => 3],
                    ['id' => 'contractsent', 'label' => 'Aceite da proposta', 'displayOrder' => 7],
                    ['id' => '14249606', 'label' => 'Contrato Assinado', 'displayOrder' => 8],
                ],
            ]],
        ], 200),
        'https://api.hubapi.com/crm/v3/objects/deals/search' => Http::response([
            'results' => [[
                'id' => 'v40-remote-deal',
                'properties' => ['dealstage' => '122191633', 'pipeline' => 'default'],
            ]],
            'total' => 1,
        ], 200),
    ]);

    $this->artisan('hubspot:reconcile-commercial-v40', [
        '--since' => '2026-10-16',
        '--types' => 'deals',
        '--max' => 100,
    ])->assertExitCode(0);

    expect(HubSpotPipelineStage::query()->count())->toBe(0)
        ->and(HubSpotDeal::query()->count())->toBe(0);
    Http::assertSentCount(2);
});
