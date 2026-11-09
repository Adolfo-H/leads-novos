<?php

use App\Jobs\ResearchWhatsappCompanyWebV87;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\PublicWhatsappWebsiteFetcher;
use App\Services\Contacts\WhatsappCompanyWebResearchV87;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function wa87Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

beforeEach(function (): void {
    config()->set('whatsapp_public_search.tavily_token', 'test-token');
    config()->set('whatsapp_public_search.brave_token', '');
    Http::preventStrayRequests();
    Bus::fake([ResearchWhatsappCompanyWebV87::class]);
    Mail::fake();
});

it('searches a HubSpot business without website, including publicly displayed ordinary phones', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-NOSITE', 'name' => 'AGROTERENAS S.A.', 'domain' => null,
    ]);
    Http::fake(['https://api.tavily.com/search' => Http::response([
        'results' => [[
            'title' => 'Agroterenas - Atendimento',
            'url' => 'https://contatos.agroterenas.com.br/atendimento',
            'content' => 'Agroterenas S.A. telefone (11) 99862-9941 e WhatsApp https://wa.me/5511971707811',
        ]],
    ], 200)]);
    $service = app(WhatsappCompanyWebResearchV87::class);
    $key = 'hubspot:'.$company->id;
    $id = $service->queue(wa87Manager(), $key);
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class);
    $result = $service->execute($id);
    expect($result['status'])->toBe('completed')
        ->and($result['findings'])->toBeGreaterThanOrEqual(2);
    $found = $service->findings($key);
    expect($found->pluck('phone_e164')->all())
        ->toContain('5511998629941', '5511971707811');
    expect($found->firstWhere('phone_e164', '5511998629941')->kind)->toBe('public_phone_not_verified')
        ->and($found->firstWhere('phone_e164', '5511971707811')->kind)->toBe('explicit_whatsapp_link');
    // Encontrar um telefone público não é provar WhatsApp e não cria um contato.
    expect(DB::table('whatsapp_phone_numbers')->where('e164', '5511998629941')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('reports searches with no results as completed instead of claiming the queue is still running', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-NORESULTS', 'name' => 'EMPRESA DE EXEMPLO SEM RESULTADOS',
    ]);
    Http::fake(['https://api.tavily.com/search' => Http::response(['results' => []], 200)]);
    $service = app(WhatsappCompanyWebResearchV87::class);
    $id = $service->queue(wa87Manager(), 'hubspot:'.$company->id);
    expect($service->execute($id)['status'])->toBe('completed')
        ->and((int) DB::table('whatsapp_company_web_runs_v87')->where('id', $id)->value('findings_count'))->toBe(0)
        ->and(DB::table('whatsapp_company_web_runs_v87')->where('id', $id)->value('finished_at'))->not->toBeNull();
});

it('does not invent internet searches for a company without site and without configured provider', function (): void {
    config()->set('whatsapp_public_search.tavily_token', '');
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-NOPROVIDER', 'name' => 'SEM FONTE DISPONIVEL',
    ]);
    try {
        app(WhatsappCompanyWebResearchV87::class)->queue(wa87Manager(), 'hubspot:'.$company->id);
        $this->fail('Deveria recusar uma busca que não possui fontes.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('wa87_search');
    }
    expect(DB::table('whatsapp_company_web_runs_v87')->count())->toBe(0);
});

it('can inspect a registered public site without any third-party API key', function (): void {
    config()->set('whatsapp_public_search.tavily_token', '');
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-SITE', 'name' => 'EMPRESA WEB PUBLICA', 'domain' => 'empresawebpublica.com.br',
    ]);
    app()->instance(PublicWhatsappWebsiteFetcher::class, new class implements PublicWhatsappWebsiteFetcher
    {
        public function get(string $registeredHost, string $path = '/'): ?array
        {
            if ($path !== '/') {
                return null;
            }

            return [
                'url' => 'https://empresawebpublica.com.br/',
                'html' => '<html><a href="https://wa.me/5545999991010">Atendimento</a> '
                    .'Telefone (45) 3030-2020 <a href="tel:+55(45)3030-4040">Ligar</a></html>',
            ];
        }
    });
    $service = app(WhatsappCompanyWebResearchV87::class);
    $key = 'hubspot:'.$company->id;
    $run = $service->queue(wa87Manager(), $key);
    expect($service->execute($run)['status'])->toBe('completed')
        ->and($service->findings($key)->pluck('phone_e164')->all())
        ->toContain('5545999991010', '554530302020', '554530304040');
    Http::assertNothingSent();
});

it('renders the one-click web research for a company without site and does not send a message', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-UI', 'name' => 'AGROTERENAS S.A.', 'domain' => null,
    ]);
    $key = 'hubspot:'.$company->id;
    Livewire::actingAs(wa87Manager())->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', $key)
        ->assertHasNoErrors()
        ->assertSee('Pesquisar telefones e WhatsApps na internet')
        ->call('startCompanyWebResearchV87', $key)
        ->assertHasNoErrors();
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class);
    Mail::assertNothingSent();
});

it('keeps stale queue entries recoverable without overwriting old findings', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-STALE', 'name' => 'EMPRESA REVISITADA', 'domain' => null,
    ]);
    $key = 'hubspot:'.$company->id;
    $actor = wa87Manager();
    $service = app(WhatsappCompanyWebResearchV87::class);
    $old = $service->queue($actor, $key);
    DB::table('whatsapp_company_web_runs_v87')->where('id', $old)->update([
        'updated_at' => now('UTC')->subMinutes(7),
    ]);
    $new = $service->queue($actor, $key);
    expect($new)->not->toBe($old)
        ->and(DB::table('whatsapp_company_web_runs_v87')->where('id', $old)->value('status'))->toBe('timed_out')
        ->and(DB::table('whatsapp_company_web_runs_v87')->where('id', $new)->value('status'))->toBe('queued');
});

it('searches one grouped company rather than starting separate jobs for each deal', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-GROUP-COMP', 'name' => 'AGROTERENAS S.A.',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'V87-GROUP-DEAL', 'name' => 'Agroterenas - Licenciamento',
    ]);
    $company->deals()->attach($deal->id, ['is_primary' => true]);
    $key = 'brand:AGROTERENAS';
    Livewire::actingAs(wa87Manager())->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', $key)
        ->assertHasNoErrors()
        ->assertSee('Pesquisar telefones e WhatsApps na internet')
        ->call('startCompanyWebResearchV87', $key)
        ->assertHasNoErrors();
    expect(DB::table('whatsapp_company_web_runs_v87')->count())->toBe(1)
        ->and(DB::table('whatsapp_company_web_runs_v87')->value('target_key'))->toBe($key);
});

it('also shows the public search in the legacy company-with-site selection', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-LEGACY-SITE', 'name' => 'EMPRESA COM SITE',
        'domain' => 'empresa-com-site.com.br',
    ]);
    Livewire::actingAs(wa87Manager())->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', 'hubspot:'.$company->id)
        ->assertHasNoErrors()
        ->assertSee('Pesquisar telefones e WhatsApps na internet')
        ->call('startCompanyWebResearchV87', 'hubspot:'.$company->id)
        ->assertHasNoErrors();
    expect(DB::table('whatsapp_company_web_runs_v87')->count())->toBe(1);
});

it('reports a failed public API distinctly from a search that finished with zero results', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V87-BADAPI', 'name' => 'EMPRESA COM API INDISPONIVEL',
    ]);
    Http::fake(['https://api.tavily.com/search' => Http::response(['message' => 'Unauthorized'], 401)]);
    $service = app(WhatsappCompanyWebResearchV87::class);
    $id = $service->queue(wa87Manager(), 'hubspot:'.$company->id);
    expect($service->execute($id)['status'])->toBe('unavailable');
    $run = DB::table('whatsapp_company_web_runs_v87')->where('id', $id)->first();
    $sources = json_decode((string) $run->source_status, true);
    expect($sources['tavily'])->toBe('http_401')
        ->and($run->error_code)->toBe('sources_unavailable');
});
