<?php

use App\Models\Company;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('reports HubSpot sync and failed-job counts without exposing payloads', function () {
    $first = Company::factory()->create([
        'corporate_name' => 'Nome sintético que não deve aparecer',
    ]);
    $second = Company::factory()->create();
    $third = Company::factory()->create();

    $first->hubSpotLead()->create([
        'hubspot_company_id' => 'duplicate-company-id',
        'hubspot_deal_sync_status' => 'remote_result_uncertain',
        'metadata' => [],
    ]);
    $second->hubSpotLead()->create([
        'hubspot_company_id' => 'duplicate-company-id',
        'hubspot_deal_id' => 'deal-without-company',
        'hubspot_deal_sync_status' => 'completed',
        'metadata' => [],
    ]);
    $third->hubSpotLead()->create([
        'hubspot_deal_id' => 'deal-without-company-id',
        'hubspot_deal_sync_status' => 'completed',
        'metadata' => [],
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => 'synthetic-failed-job-12',
        'connection' => 'redis',
        'queue' => 'hubspot-webhooks',
        'payload' => '{"displayName":"SyntheticJob"}',
        'exception' => 'Illuminate\\Queue\\MaxAttemptsExceededException: synthetic private payload',
        'failed_at' => now(),
    ]);

    $exitCode = Artisan::call('hubspot:sync-audit', [
        '--format' => 'json',
    ]);
    $output = Artisan::output();
    $summary = json_decode(
        $output,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    expect($exitCode)->toBe(0)
        ->and($summary['leads_without_remote_deal_id'])->toBe(1)
        ->and($summary['deal_sync_states']['remote_result_uncertain'])->toBe(1)
        ->and($summary['remote_company_id_duplicate_groups'])->toBe(1)
        ->and($summary['deals_without_company_association_candidate'])->toBe(1)
        ->and($summary['failed_jobs_by_queue_and_exception']['categories'])
        ->toHaveKey('hubspot-webhooks|Illuminate\\Queue\\MaxAttemptsExceededException')
        ->and($output)->not->toContain('Nome sintético')
        ->and($output)->not->toContain('synthetic private payload');
});
