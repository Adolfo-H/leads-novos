<?php

use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function makeHubSpotSyncCandidate(
    string $cnpjRoot,
    string $name,
    int $score,
    bool $alreadySynced = false,
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $cnpjRoot,
            'corporate_name' => $name,
        ]);

    $company
        ->sdrScore()
        ->create([
            'score' => $score,
            'priority' => 'high',
            'label' => 'Alta prioridade',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'metadata' => [],
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',
            'status' => 'not_found',
            'checked_at' => now(),
        ]);

    if ($alreadySynced) {
        $company
            ->hubSpotLead()
            ->create([
                'synced_at' => now(),
            ]);
    }

    return $company;
}

it('does not let already synced leads hide lower ranked eligible leads', function () {
    config([
        'services.hubspot.lead_min_score' => 60,
    ]);

    /*
     * Os 100 primeiros possuem score maior,
     * mas já foram sincronizados.
     */
    foreach (range(1, 100) as $index) {
        makeHubSpotSyncCandidate(
            cnpjRoot: str_pad(
                (string) $index,
                8,
                '0',
                STR_PAD_LEFT
            ),
            name: 'EMPRESA JA SINCRONIZADA '.$index,
            score: 100,
            alreadySynced: true,
        );
    }

    /*
     * Este é o primeiro lead realmente
     * disponível para sincronização.
     */
    makeHubSpotSyncCandidate(
        cnpjRoot: '99999999',
        name: 'CANDIDATO ELEGIVEL FORA DO TOP 100',
        score: 60,
    );

    $exitCode =
        Artisan::call(
            'hubspot:leads-sync',
            [
                '--limit' => 1,
            ]
        );

    expect(
        $exitCode
    )->toBe(0);

    expect(
        Artisan::output()
    )->toContain(
        'CANDIDATO ELEGIVEL FORA DO TOP 100'
    );
});

it('returns failure when a hubspot synchronization fails', function () {
    config([
        'services.hubspot.lead_min_score' => 60,
        'services.hubspot.lead_sync_enabled' => true,
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    makeHubSpotSyncCandidate(
        cnpjRoot: '88888888',
        name: 'EMPRESA COM FALHA DE SINCRONIZACAO',
        score: 90,
    );

    /*
     * Simulamos indisponibilidade do HubSpot.
     */
    Http::fake([
        'https://api.hubapi.com/*' => Http::response(
            [],
            500
        ),
    ]);

    $exitCode =
        Artisan::call(
            'hubspot:leads-sync',
            [
                '--limit' => 1,
                '--execute' => true,
            ]
        );

    expect(
        $exitCode
    )->toBe(1);
});
