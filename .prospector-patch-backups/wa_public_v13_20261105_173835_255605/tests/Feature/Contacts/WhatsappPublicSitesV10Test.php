<?php

use App\Jobs\ResearchWhatsappPublicCompanySite;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\PublicWhatsappLinkExtractor;
use App\Services\Contacts\PublicWhatsappWebsiteFetcher;
use App\Services\Contacts\SafePublicWhatsappWebsiteFetcher;
use App\Services\Contacts\WhatsappPhoneRegistry;
use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

it('extracts only explicit real phone links, not invite links or random text', function () {
    $extractor = new PublicWhatsappLinkExtractor;
    $html = '<html><body><a href="https://wa.me/5545999991010">WhatsApp comercial</a>'
        .'<a href="https://api.whatsapp.com/send?phone=554532341010&amp;text=Oi">Falar com atendimento</a>'
        .'<a href="https://wa.me/message/abcde">Convite</a><p>559999999999</p></body></html>';
    expect(collect($extractor->extract($html))->pluck('e164')->all())->toBe(['5545999991010', '554532341010']);
    expect(PublicWhatsappLinkExtractor::numberFromLink('https://evil.example/wa.me/5545999991010'))->toBeNull();
});

it('rejects private destinations and non-domains before networking', function () {
    expect(SafePublicWhatsappWebsiteFetcher::domain('127.0.0.1'))->toBeNull()
        ->and(SafePublicWhatsappWebsiteFetcher::domain('http://localhost:8080'))->toBeNull()
        ->and(SafePublicWhatsappWebsiteFetcher::domain('169.254.169.254'))->toBeNull()
        ->and(SafePublicWhatsappWebsiteFetcher::domain('https://user:pass@example.com'))->toBeNull()
        ->and(SafePublicWhatsappWebsiteFetcher::domain('https://contoso.com'))->toBe('contoso.com');
});

it('matches a public website link only with a directly linked HubSpot contact, without changing confirmations', function () {
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'SITE101', 'name' => 'Empresa Linkada', 'domain' => 'empresa-exemplo.com.br',
    ]);
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => 'CONTACT101', 'first_name' => 'Fernanda', 'last_name' => 'Comercial',
        'mobile_phone' => '(45) 99999-1010',
    ]);
    $company->contacts()->attach($contact->id, ['is_primary' => true]);
    // Outro contato, não associado à empresa, não deve receber evidência por semelhança.
    HubSpotContact::query()->create([
        'hubspot_id' => 'CONTACT102', 'first_name' => 'Outro', 'mobile_phone' => '(45) 98888-2020',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();

    app()->instance(PublicWhatsappWebsiteFetcher::class, new class implements PublicWhatsappWebsiteFetcher {
        public function get(string $registeredHost, string $path = '/'): ?array
        {
            if ($path !== '/') {
                return null;
            }
            return [
                'url' => 'https://empresa-exemplo.com.br/',
                'html' => '<a href="https://wa.me/5545999991010">WhatsApp</a>'
                    .'<a href="https://wa.me/5545988882020">Outro telefone</a>',
            ];
        }
    });
    Bus::fake([ResearchWhatsappPublicCompanySite::class]);
    $run = app(WhatsappPublicSiteResearch::class)->queueRun($actor, 3);
    $result = app(WhatsappPublicSiteResearch::class)->researchDomain($run, 'empresa-exemplo.com.br');
    expect($result['status'])->toBe('done')->and($result['links'])->toBe(2)
        ->and($result['matched'])->toBe(1);
    $matched = DB::table('whatsapp_public_site_evidences')->where('e164', '5545999991010')->first();
    $unlinked = DB::table('whatsapp_public_site_evidences')->where('e164', '5545988882020')->first();
    expect($matched->phone_id)->not->toBeNull()->and($unlinked->phone_id)->toBeNull();
    expect(DB::table('whatsapp_phone_numbers')->where('id', $matched->phone_id)->value('status'))->toBe('unknown');
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
    expect(DB::table('whatsapp_public_site_evidences')->count())->toBe(2);
});

it('only managers can queue public research and the daily schedule is opt-in', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $research = app(WhatsappPublicSiteResearch::class);
    expect((bool) $research->settings()->auto_enabled)->toBeFalse();
    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $research->queueRun($seller, 3);
});

it('renders the public research module on the existing Whatsapp central for a manager', function () {
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($actor)->get(route('contacts.whatsapp'))
        ->assertOk()->assertSee('WhatsApps divulgados pelas empresas')
        ->assertSee('Pesquisar agora')->assertSee('Rotina diária opcional');
});
