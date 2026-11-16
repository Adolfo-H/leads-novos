<?php

use App\Jobs\ResearchWhatsappPublicCompanySite;
use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\PublicWhatsappWebsiteFetcher;
use App\Services\Contacts\WhatsappPublicCompanyChannels;
use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function wa13Actor(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

function wa13Evidence(string $domain, string $e164): void
{
    $now = now('UTC');
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => $domain,
        'e164' => $e164,
        'source_url' => 'https://'.$domain.'/contato',
        'wa_url' => 'https://wa.me/'.$e164,
        'company_label' => 'Empresa pública',
        'first_seen_at' => $now, 'last_seen_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

it('saves existing discovered numbers as public business channels without fake contacts or deals', function (): void {
    $fiscal = Company::query()->create(['cnpj_root' => '12345670', 'corporate_name' => 'EMPRESA TESTE V13']);
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V13_001', 'name' => 'Empresa Teste V13',
        'domain' => 'empresa-v13.com.br', 'company_id' => $fiscal->id,
        'match_source' => 'hubspot_explicit_cnpj',
    ]);
    $other = HubSpotCompany::query()->create([
        'hubspot_id' => 'V13_002', 'name' => 'Empresa distinta', 'domain' => 'outra-v13.com.br',
    ]);
    wa13Evidence('empresa-v13.com.br', '5545999991010');
    $service = app(WhatsappPublicCompanyChannels::class);
    expect($service->syncDomain('empresa-v13.com.br'))->toBe(1)
        ->and($service->syncDomain('empresa-v13.com.br'))->toBe(0);

    $rows = $service->forCompany($company->id);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->e164)->toBe('5545999991010')
        ->and((int) $rows[0]->company_id)->toBe($fiscal->id)
        ->and($service->forCompany($other->id))->toHaveCount(0)
        ->and(CommercialContact::query()->count())->toBe(0)
        ->and(HubSpotDeal::query()->count())->toBe(0)
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);
});

it('does not propagate unsafe fiscal company id and only displays deals truly associated with that company', function (): void {
    $fiscal = Company::query()->create(['cnpj_root' => '12345671', 'corporate_name' => 'REGISTRO FISCAL DISTINTO']);
    $unsafeCompany = HubSpotCompany::query()->create([
        'hubspot_id' => 'V13_UNSAFE', 'name' => 'Empresa sem vínculo fiscal seguro',
        'domain' => 'unsafe-v13.com.br',
    ]);
    // Registros históricos inseguro: force SQL update para não burlar o bloqueio do model.
    DB::table('hubspot_companies')->where('id', $unsafeCompany->id)->update([
        'company_id' => $fiscal->id, 'match_source' => 'hubspot_related_explicit_cnpj',
    ]);
    wa13Evidence('unsafe-v13.com.br', '5545999992020');
    app(WhatsappPublicCompanyChannels::class)->syncDomain('unsafe-v13.com.br');
    $channels = app(WhatsappPublicCompanyChannels::class)->forCompany($unsafeCompany->id);
    expect($channels)->toHaveCount(1)->and($channels[0]->company_id)->toBeNull();

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'V13_DEAL_001', 'name' => 'Negócio existente',
        'stage_label' => 'Oportunidade',
    ]);
    $unsafeCompany->deals()->attach($deal->id, ['is_primary' => true]);
    expect(app(WhatsappPublicCompanyChannels::class)->companyDeals($unsafeCompany->id))->toHaveCount(1)
        ->and(app(WhatsappPublicCompanyChannels::class)->companyDeals($unsafeCompany->id)[0]->name)->toBe('Negócio existente');
});

it('backfills existing discoveries without visiting websites and renders saved channels for selected company', function (): void {
    $actor = wa13Actor();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V13_BACKFILL', 'name' => 'Cooperativa V13',
        'domain' => 'cooperativa-v13.com.br',
    ]);
    wa13Evidence('cooperativa-v13.com.br', '5545999993030');
    $service = app(WhatsappPublicCompanyChannels::class);
    expect($service->backfill()['created'])->toBe(1)
        ->and($service->backfill()['created'])->toBe(0);
    Livewire::actingAs($actor)->test('pages::contacts.whatsapp')
        ->call('selectPublicCompany', $company->id)
        ->assertSee('Canais públicos da empresa cadastrados')
        ->assertSee('data-phone-e164="5545999993030"', false)
        ->assertSee('Negócios já associados à empresa no HubSpot')
        ->assertHasNoErrors();
});

it('registers channels automatically after the site scan without using WhatsApp accounts', function (): void {
    $actor = wa13Actor();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V13_SCAN', 'name' => 'Cooperativa Scanner V13',
        'domain' => 'scan-v13.com.br',
    ]);
    app()->instance(PublicWhatsappWebsiteFetcher::class, new class implements PublicWhatsappWebsiteFetcher
    {
        public function get(string $registeredHost, string $path = '/'): ?array
        {
            if ($path !== '/') {
                return null;
            }

            return [
                'url' => 'https://scan-v13.com.br/',
                'html' => '<html><a href="https://wa.me/5545999994040">WhatsApp Comercial</a></html>',
            ];
        }
    });
    Bus::fake([ResearchWhatsappPublicCompanySite::class]);
    $research = app(WhatsappPublicSiteResearch::class);
    $run = $research->queueCompany($actor, $company->id);
    $result = $research->researchDomain($run, 'scan-v13.com.br');
    expect($result['status'])->toBe('done')
        ->and(app(WhatsappPublicCompanyChannels::class)->forCompany($company->id))->toHaveCount(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(0);
});
