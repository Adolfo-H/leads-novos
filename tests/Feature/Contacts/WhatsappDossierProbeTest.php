<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Services\Contacts\WhatsappPublicCompanyChannels;
use App\Services\Contacts\WhatsappPublicContacts;
use Illuminate\Support\Facades\DB;

it('mostra consultas inconclusivas e não localizadas no dossiê sem inventar confirmação negativa', function (): void {
    $company = Company::query()->create(['cnpj_root' => '91919191', 'corporate_name' => 'Empresa de teste WA V91']);
    HubSpotCompany::query()->create([
        'hubspot_id' => 'v91-test-9191', 'name' => 'Empresa de teste WA V91',
        'domain' => 'wa-v91.example.org', 'company_id' => $company->id, 'match_source' => 'hubspot_explicit_cnpj',
    ]);
    $number = '5555996073706';
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => 'wa-v91.example.org', 'e164' => $number,
        'source_url' => 'https://wa-v91.example.org/contato', 'wa_url' => 'https://wa.me/'.$number,
        'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('wa-v91.example.org');
    $id = DB::table('whatsapp_phone_numbers')->where('e164', $number)->value('id');
    expect($id)->not->toBeNull();
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $id, 'result' => 'inconclusive', 'checked_at' => now('UTC'),
        'attempts' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    $item = app(WhatsappPublicContacts::class)->dossier($company)->first();
    expect($item['status'])->toBe('unknown')
        ->and($item['probe_result'])->toBe('inconclusive')
        ->and($item['probe_checked_at'])->not->toBeNull();

    DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->update(['result' => 'not_found']);
    $next = app(WhatsappPublicContacts::class)->dossier($company)->first();
    expect($next['status'])->toBe('unknown')
        ->and($next['probe_result'])->toBe('not_found');

    DB::table('whatsapp_phone_numbers')->where('id', $id)->update([
        'status' => 'yes', 'evidence' => 'web_found_unofficial', 'verified_by' => null,
    ]);
    $technicalPositive = app(WhatsappPublicContacts::class)->dossier($company)->first();
    expect($technicalPositive['status'])->toBe('unknown')
        ->and($technicalPositive['evidence'])->toBe('web_found_unofficial');

    DB::table('whatsapp_phone_numbers')->where('id', $id)->update([
        'status' => 'no', 'evidence' => 'legacy_manual', 'verified_by' => null,
    ]);
    $ambiguousLegacy = app(WhatsappPublicContacts::class)->dossier($company)->first();
    expect($ambiguousLegacy['status'])->toBe('unknown');
});
