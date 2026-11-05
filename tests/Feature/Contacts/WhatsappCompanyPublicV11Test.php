<?php

use App\Jobs\ResearchWhatsappPublicCompanySite;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\PublicWhatsappWebsiteFetcher;
use App\Services\Contacts\WhatsappPhoneRegistry;
use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

function waCompany11Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{actor:User,company:HubSpotCompany,phoneA:HubSpotContact,phoneB:HubSpotContact} */
function waCompany11Fixture(): array
{
    $actor = waCompany11Manager();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA11_COMPANY_1', 'name' => 'Empresa Especial de Teste',
        'domain' => 'empresa-wa11.com.br',
    ]);
    $a = HubSpotContact::query()->create([
        'hubspot_id' => 'WA11_PHONE_A', 'first_name' => 'Daniela',
        'mobile_phone' => '(45) 99999-1010',
    ]);
    $b = HubSpotContact::query()->create([
        'hubspot_id' => 'WA11_PHONE_B', 'first_name' => 'Raul',
        'mobile_phone' => '(45) 98888-2020',
    ]);
    $company->contacts()->attach([$a->id, $b->id]);
    // Este contato não pertence à empresa pesquisada.
    HubSpotContact::query()->create([
        'hubspot_id' => 'WA11_PHONE_OTHER', 'first_name' => 'Contato Outra Empresa',
        'mobile_phone' => '(11) 97777-3030',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();

    return ['actor' => $actor, 'company' => $company, 'phoneA' => $a, 'phoneB' => $b];
}

it('allows a manager to choose a real HubSpot company with a known domain', function () {
    $f = waCompany11Fixture();
    $research = app(WhatsappPublicSiteResearch::class);
    expect($research->companyCandidates('Especial')->pluck('id')->all())
        ->toContain($f['company']->id);
    $snapshot = $research->companySnapshot($f['company']->id);
    expect($snapshot)->not->toBeNull()
        ->and($snapshot['domain'])->toBe('empresa-wa11.com.br')
        ->and($snapshot['phone_total'])->toBe(2)
        ->and($snapshot['phones']->count())->toBe(2);

    Bus::fake([ResearchWhatsappPublicCompanySite::class]);
    $runId = $research->queueCompany($f['actor'], $f['company']->id);
    expect($runId)->toBeInt();
    Bus::assertDispatched(ResearchWhatsappPublicCompanySite::class);
});

it('cross-references published numbers globally without inventing company links or active WhatsApp confirmations', function () {
    $f = waCompany11Fixture();
    app()->instance(PublicWhatsappWebsiteFetcher::class, new class implements PublicWhatsappWebsiteFetcher
    {
        public function get(string $registeredHost, string $path = '/'): ?array
        {
            if ($path !== '/') {
                return null;
            }

            return ['url' => 'https://empresa-wa11.com.br/', 'html' => '<html>'
                .'<a href="https://wa.me/5545999991010">Telefone da empresa</a>'
                .'<a href="https://wa.me/5511977773030">Outro contato do Prospector</a>'
                .'<a href="https://wa.me/5561999994040">Novo telefone</a>'
                .'</html>'];
        }
    });
    Bus::fake([ResearchWhatsappPublicCompanySite::class]);
    $research = app(WhatsappPublicSiteResearch::class);
    $id = $research->queueCompany($f['actor'], $f['company']->id);
    $result = $research->researchDomain($id, 'empresa-wa11.com.br');
    expect($result['links'])->toBe(3)->and($result['matched'])->toBe(1);
    $direct = DB::table('whatsapp_public_site_evidences')->where('e164', '5545999991010')->first();
    $other = DB::table('whatsapp_public_site_evidences')->where('e164', '5511977773030')->first();
    $new = DB::table('whatsapp_public_site_evidences')->where('e164', '5561999994040')->first();
    expect($direct->phone_id)->not->toBeNull()
        ->and($direct->global_phone_id)->not->toBeNull()
        ->and($other->phone_id)->toBeNull()
        ->and($other->global_phone_id)->not->toBeNull()
        ->and($new->global_phone_id)->not->toBeNull(); // EC_WA_PUBLIC_DIRECTORY_V14_TEST
    // Novos canais empresariais entram no índice, sem criar pessoas ou confirmar contas.
    expect($research->metrics()['indexed'])->toBe(3)
        ->and($research->metrics()['new'])->toBe(0);
    $snapshot = $research->companySnapshot($f['company']->id);
    expect($snapshot['phone_total'])->toBe(2)
        ->and($snapshot['direct_total'])->toBe(1)
        ->and($snapshot['confirmed_total'])->toBe(0);
    // Publicidade no site não vira "Tem WhatsApp".
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(0);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'all', 'all', 'all', 1, 'all', 20, 'published')->total())->toBe(3);
});

it('reconciles previously discovered public links without visiting websites', function () {
    $f = waCompany11Fixture();
    $now = now('UTC');
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => 'empresa-wa11.com.br', 'e164' => '5545999991010',
        'source_url' => 'https://empresa-wa11.com.br/contato',
        'wa_url' => 'https://wa.me/5545999991010',
        'first_seen_at' => $now, 'last_seen_at' => $now,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $service = app(WhatsappPublicSiteResearch::class);
    $preview = $service->reconcileExisting(false);
    expect($preview['seen'])->toBe(1)->and($preview['changed'])->toBe(1);
    expect(DB::table('whatsapp_public_site_evidences')->value('global_phone_id'))->toBeNull();
    $applied = $service->reconcileExisting(true);
    expect($applied['global'])->toBe(1)->and($applied['direct'])->toBe(1);
    expect(DB::table('whatsapp_public_site_evidences')->value('phone_id'))->not->toBeNull();
    expect($service->reconcileExisting(true)['changed'])->toBe(0);
});

it('does not allow sellers to request company crawls or inspect invalid company ids', function () {
    $f = waCompany11Fixture();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(app(WhatsappPublicSiteResearch::class)->companySnapshot(999999))->toBeNull();
    $this->expectException(HttpException::class);
    app(WhatsappPublicSiteResearch::class)->queueCompany($seller, $f['company']->id);
});

it('renders the existing contacts as the primary workspace and company research as a secondary tool', function () {
    $manager = waCompany11Manager();
    $this->actingAs($manager)->get(route('contacts.whatsapp'))
        ->assertOk()->assertSee('Todos os números e contatos')
        ->assertSee('Verificar números que já estão no meu banco')
        ->assertSee('Buscar empresa por nome ou domínio')
        ->assertSee('Pesquisar WhatsApp de uma empresa específica')
        ->assertDontSee('wire:click="startPublicSites"', false); // EC_WA_GENERAL_SECTION_V15_TEST
});
