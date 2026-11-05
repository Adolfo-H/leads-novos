<?php

use App\Models\HubSpotCompany;
use App\Models\User;
use App\Services\Contacts\WhatsappExternalCompanyDiscoveryV14;
use App\Services\Contacts\WhatsappTavilySearchV16;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('stores indexed Tavily WhatsApp links for manual review without confirming accounts or creating people', function () {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA-V163-TAVILY-TEST',
        'name' => 'Empresa Exemplo V163',
        'domain' => 'empresa-v163.example.org',
    ]);

    // Explicitly select Tavily even if an operator also configured Brave.
    config()->set('whatsapp_public_search.tavily_token', 'test-key-v163');
    config()->set('whatsapp_public_search.brave_token', 'unused-brave-key');
    Http::preventStrayRequests();
    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'results' => [[
                'title' => 'Atendimento da empresa',
                'url' => 'https://empresa-v163.example.org/contato',
                'content' => 'Converse pelo WhatsApp https://wa.me/5545999993030',
            ]],
        ], 200),
    ]);

    $runId = app(WhatsappExternalCompanyDiscoveryV14::class)->queue($manager, (int) $company->id);

    expect($runId)->toBeGreaterThan(0);
    expect(DB::table('whatsapp_public_external_company_runs')->where('id', $runId)
        ->value('status'))->toBe('completed');
    expect(DB::table('whatsapp_public_external_company_findings')
        ->where('hubspot_company_id', $company->id)
        ->where('phone_e164', '5545999993030')
        ->where('provider', 'tavily_index')
        ->where('review_status', 'pending')
        ->count())->toBe(1);

    // Indexed references never masquerade as active WhatsApp accounts or create personal contacts.
    expect(DB::table('whatsapp_public_site_evidences')->count())->toBe(0);
    expect(DB::table('commercial_contacts')->count())->toBe(0);
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
    Http::assertSentCount(2);
});

it('does not store a telephone mentioned without an explicit public WhatsApp link', function () {
    config()->set('whatsapp_public_search.tavily_token', 'test-key-v163');
    Http::preventStrayRequests();
    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'results' => [[
                'title' => 'Telefone comercial',
                'url' => 'https://empresa-v163.example.org/contato',
                'content' => 'Ligue para 45 99999-3030. Atendimento de segunda a sexta.',
            ]],
        ], 200),
    ]);
    $out = app(WhatsappTavilySearchV16::class)
        ->search('Empresa Exemplo V163', 'empresa-v163.example.org');

    expect($out['success'])->toBeTrue();
    expect($out['items'])->toBe([]);
});
