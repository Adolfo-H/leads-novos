<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\User;
use App\Services\Contacts\WhatsappExternalCompanyDiscoveryV14;
use App\Services\Contacts\WhatsappPublicCompanyChannels;
use App\Services\Contacts\WhatsappPublicContactsV14;
use App\Services\Contacts\WhatsappPublicPhoneIndexerV14;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function waV14Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    return $actor;
}

function waV14Scenario(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '12345678',
        'corporate_name' => 'AGROSUL SEMENTES EXPERIMENTAL',
    ]);
    $hubspot = HubSpotCompany::query()->create([
        'hubspot_id' => 'v14-test-company-002',
        'name' => 'Agrosul Sementes',
        'domain' => 'agrosul.example.org',
        'company_id' => $company->id,
        'match_source' => 'hubspot_explicit_cnpj',
    ]);
    return compact('company','hubspot');
}

it('registers a public business channel in the global phone index, never as a fake personal contact', function () {
    $scenario = waV14Scenario();
    $phone = '5555996073706';
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => 'agrosul.example.org', 'e164' => $phone,
        'source_url' => 'https://agrosul.example.org/contato', 'wa_url' => 'https://wa.me/'.$phone,
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('agrosul.example.org');
    $row = DB::table('whatsapp_phone_numbers')->where('e164', $phone)->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('unknown');
    expect(DB::table('whatsapp_phone_sources')->where('phone_id', $row->id)->where('origin','public')->exists())->toBeTrue();
    expect(DB::table('commercial_contacts')->count())->toBe(0);
    expect(app(WhatsappPublicContactsV14::class)->search('+55 (55) 99607-3706')->count())->toBe(1);

    $this->actingAs(waV14Manager())->get('/contatos?q=%2B55%20%2855%29%2099607-3706')
        ->assertOk()->assertSee('Canais públicos de WhatsApp das empresas');
});

it('shows published WhatsApp evidence in dossier without implying verified account or fake deal', function () {
    $scenario = waV14Scenario();
    $phone = '5555996073706';
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain'=>'agrosul.example.org','e164'=>$phone,
        'source_url'=>'https://agrosul.example.org/whatsapp','wa_url'=>'https://wa.me/'.$phone,
        'first_seen_at'=>now(),'last_seen_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('agrosul.example.org');
    $items = app(WhatsappPublicContactsV14::class)->dossier($scenario['company']);
    expect($items)->toHaveCount(1);
    expect($items->first()['published'])->toBeTrue();
    expect($items->first()['status'])->toBe('unknown');
    DB::table('whatsapp_phone_numbers')->where('e164',$phone)->update([
        'status'=>'yes','evidence'=>'manual_app_confirmed','verified_at'=>now(),
    ]);
    expect(app(WhatsappPublicContactsV14::class)->dossier($scenario['company'])->first()['status'])->toBe('yes');
    expect(DB::table('hubspot_deals')->count())->toBe(0);
});

it('does not show an old public-channel fiscal association after its source becomes unsafe', function () {
    $scenario = waV14Scenario();
    $phone = '5555996073706';
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain'=>'agrosul.example.org','e164'=>$phone,
        'source_url'=>'https://agrosul.example.org/whatsapp','wa_url'=>'https://wa.me/'.$phone,
        'first_seen_at'=>now(),'last_seen_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('agrosul.example.org');
    DB::table('hubspot_companies')->where('id',$scenario['hubspot']->id)
        ->update(['match_source'=>'hubspot_related_explicit_cnpj']);
    $items = app(WhatsappPublicContactsV14::class)->dossier($scenario['company']);
    expect($items)->toHaveCount(0);
});

it('keeps external-index findings under review and never confirms WhatsApp just from search', function () {
    $scenario = waV14Scenario();
    config()->set('whatsapp_public_search.brave_token', 'test-token');
    Http::fake(['https://api.search.brave.com/*' => Http::response([
        'web'=>['results'=>[ [
            'title'=>'Agrosul Sementes WhatsApp',
            'url'=>'https://agrosul.example.org/atendimento',
            'description'=>'Fale no WhatsApp: https://wa.me/5555996073706',
        ] ]],
    ],200)]);
    $id = app(WhatsappExternalCompanyDiscoveryV14::class)
        ->queue(waV14Manager(), (int) $scenario['hubspot']->id);
    expect($id)->toBeGreaterThan(0);
    expect(DB::table('whatsapp_public_external_company_findings')->count())->toBe(1);
    expect(DB::table('whatsapp_public_external_company_findings')->value('review_status'))->toBe('pending');
    expect(DB::table('whatsapp_public_site_evidences')->count())->toBe(0);
    expect(DB::table('commercial_contacts')->count())->toBe(0);
});
