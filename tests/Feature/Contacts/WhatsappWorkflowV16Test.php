<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\User;
use App\Services\Contacts\WhatsappCompanyChannelLinkV16;
use App\Services\Contacts\WhatsappManualPhoneV16;
use App\Services\Contacts\WhatsappPublicCompanyChannels;
use App\Services\Contacts\WhatsappPublicContactsV14;
use App\Services\Contacts\WhatsappTavilySearchV16;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function wa16Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

it('finds explicit WhatsApp publication links through the free Tavily integration without confirming a user', function () {
    config()->set('whatsapp_public_search.tavily_token', 'test-key');
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'results' => [[
            'url' => 'https://empresa-teste.com.br/contatos',
            'title' => 'Atendimento empresa',
            'content' => 'WhatsApp https://wa.me/5545999991010',
        ]],
    ], 200)]);
    $out = app(WhatsappTavilySearchV16::class)->search('Empresa Teste', 'empresa-teste.com.br');
    expect($out['success'])->toBeTrue()
        ->and($out['items'])->toHaveCount(1)
        ->and($out['items'][0]['e164'])->toBe('5545999991010')
        ->and($out['items'][0]['kind'])->toBe('explicit_whatsapp_link');
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
});

it('allows the manager to link a discovered business phone to the correct fiscal company without creating a person', function () {
    $actor = wa16Manager();
    $fiscal = Company::query()->create(['cnpj_root' => '98765431', 'corporate_name' => 'AGROSUL DO PARANA']);
    $source = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA16-SOURCE', 'name' => 'Empresa com site', 'domain' => 'site-v16.com.br',
    ]);
    $when = now('UTC');
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => 'site-v16.com.br', 'e164' => '5555996073706',
        'source_url' => 'https://site-v16.com.br/contato', 'wa_url' => 'https://wa.me/5555996073706',
        'first_seen_at' => $when, 'last_seen_at' => $when,
        'created_at' => $when, 'updated_at' => $when,
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('site-v16.com.br');
    $channelId = DB::table('whatsapp_public_company_channels')->value('id');
    app(WhatsappCompanyChannelLinkV16::class)->assign($actor, $channelId, 'local:'.$fiscal->id);
    expect(DB::table('whatsapp_company_channel_links_v16')->where('channel_id', $channelId)
        ->where('target_id', $fiscal->id)->where('target_type', 'local')->exists())->toBeTrue();
    expect(DB::table('commercial_contacts')->count())->toBe(0);
    $dossier = app(WhatsappPublicContactsV14::class)->dossier($fiscal);
    expect($dossier)->toHaveCount(1)->and($dossier->first()['published'])->toBeTrue()
        ->and($dossier->first()['status'])->toBe('unknown');
});

it('corrects an invalid format without modifying its source or inventing WhatsApp status', function () {
    $actor = wa16Manager();
    $id = DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'raw:wa16-test', 'digits' => '459999110',
        'e164' => null, 'kind' => 'review', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(WhatsappManualPhoneV16::class)->review($actor, $id, 'corrected', '+55 (45) 99999-1111');
    expect(DB::table('whatsapp_phone_format_reviews_v16')->where('original_phone_id', $id)
        ->value('corrected_e164'))->toBe('5545999991111');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('kind'))->toBe('review');
    expect(DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(0);
});

it('queues only specifically entered numbers through existing connected agent sessions', function () {
    $actor = wa16Manager();
    DB::table('whatsapp_web_scan_agents')->whereIn('session', [1, 2])->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
        'daily_limit' => 200, 'auto_enabled' => false, 'configured_by' => $actor->id,
    ]);
    $out = app(WhatsappManualPhoneV16::class)->queue($actor, "+55 (45) 99999-1111\n+55 (11) 98888-7777");
    expect($out['queued'])->toBe(2);
    $batchRows = DB::table('whatsapp_web_scan_batches')->whereNotNull('target_phone_ids')->get();
    expect($batchRows->sum('requested_limit'))->toBe(2);
    expect(DB::table('whatsapp_phone_numbers')->whereIn('e164', ['5545999991111', '5511988887777'])->count())->toBe(2);
});

it('rejects seller attempts to link business phone and to start selected batches', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => app(WhatsappCompanyChannelLinkV16::class)->assign($seller, 999, 'local:999'))
        ->toThrow(HttpException::class);
    expect(fn () => app(WhatsappManualPhoneV16::class)->queue($seller, '+55 45 99999-1111'))
        ->toThrow(HttpException::class);
});
