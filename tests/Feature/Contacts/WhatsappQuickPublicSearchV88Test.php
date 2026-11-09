<?php

use App\Jobs\ResearchWhatsappCompanyWebV87;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\WhatsappCompanyQuickResearchV88;
use App\Services\Contacts\WhatsappCompanyWebResearchV87;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function wa88Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function (): void {
    config()->set('whatsapp_public_search.tavily_token', 'v88-test-key');
    config()->set('whatsapp_public_search.brave_token', '');
    Bus::fake([ResearchWhatsappCompanyWebV87::class]);
    Mail::fake();
});

it('opens a single name and search button without depending on grouped-deal details', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'V88-AGROT-COMP', 'name' => 'AGROTERENAS S.A.',
        'domain' => 'agroterenas.com.br',
    ]);
    foreach (['Agroterenas - Licenciamento', 'AGROTERENAS S.A. - ACOMPANHAMENTO', 'AGROTERENAS S.A. - RETROATIVAS'] as $i => $name) {
        $deal = HubSpotDeal::query()->create(['hubspot_id' => 'V88-DEAL-'.($i + 1), 'name' => $name]);
        $company->deals()->attach($deal->id, ['is_primary' => true]);
    }

    Livewire::actingAs(wa88Manager())->test('pages::contacts.whatsapp')
        ->assertSee('Pesquisar números')
        ->assertSee('data-test="wa88-pesquisa-simples"', false)
        ->set('publicQuickNameV88', 'Agroterenas')
        ->call('searchPublicCompanyV88')
        ->assertHasNoErrors()
        ->assertSet('publicQuickSearchKeyV88', app(WhatsappCompanyQuickResearchV88::class)->keyFor('Agroterenas'));

    $run = DB::table('whatsapp_company_web_runs_v87')->first();
    expect($run)->not->toBeNull()
        ->and($run->company_label)->toBe('Agroterenas')
        ->and($run->domain)->toBe('agroterenas.com.br')
        ->and($run->target_key)->toStartWith('name:');
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class);
    Mail::assertNothingSent();
});

it('can search a company that is not yet registered in HubSpot or Receita', function (): void {
    $service = app(WhatsappCompanyQuickResearchV88::class);
    $id = $service->start(wa88Manager(), 'Empresa Nova de Teste');
    expect($id)->toBeInt()
        ->and(DB::table('whatsapp_company_web_runs_v87')->where('id', $id)->value('domain'))->toBeNull();
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class);
    Mail::assertNothingSent();
});

it('reuses the existing public search executor and records genuine candidate sources', function (): void {
    Http::fake([
        'https://api.tavily.com/search' => Http::response([
            'results' => [[
                'title' => 'Agroterenas - atendimento comercial',
                'url' => 'https://empresa-pesquisa.example.org/contato',
                'content' => 'Agroterenas: (11) 99862-9941. WhatsApp https://wa.me/5511971707811',
            ]],
        ], 200),
    ]);
    $service = app(WhatsappCompanyQuickResearchV88::class);
    $id = $service->start(wa88Manager(), 'Agroterenas');
    $result = app(WhatsappCompanyWebResearchV87::class)->execute($id);
    expect($result['status'])->toBe('completed');

    $found = $service->findings($service->latest($service->keyFor('Agroterenas')));
    expect($found->pluck('phone_e164')->all())->toContain('5511998629941', '5511971707811')
        ->and(DB::table('whatsapp_phone_numbers')->whereIn('e164', ['5511998629941', '5511971707811'])->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('shows zero results as zero instead of borrowing an older successful search', function (): void {
    Http::fake(['https://api.tavily.com/search' => Http::response(['results' => []], 200)]);
    $service = app(WhatsappCompanyQuickResearchV88::class);
    $id = $service->start(wa88Manager(), 'Empresa sem telefone divulgado');
    expect(app(WhatsappCompanyWebResearchV87::class)->execute($id)['status'])->toBe('completed')
        ->and($service->findings($service->latest($service->keyFor('Empresa sem telefone divulgado'))))->toHaveCount(0);
});

it('does not queue an unsearchable company without configured provider or known domain', function (): void {
    config()->set('whatsapp_public_search.tavily_token', '');
    config()->set('whatsapp_public_search.brave_token', '');

    Livewire::actingAs(wa88Manager())->test('pages::contacts.whatsapp')
        ->set('publicQuickNameV88', 'Sem dominio ou API')
        ->call('searchPublicCompanyV88')
        ->assertHasErrors(['wa88_name']);
    expect(DB::table('whatsapp_company_web_runs_v87')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('accepts an explicit company domain without inventing a CRM association', function (): void {
    config()->set('whatsapp_public_search.tavily_token', '');
    config()->set('whatsapp_public_search.brave_token', '');
    $service = app(WhatsappCompanyQuickResearchV88::class);
    $run = $service->start(wa88Manager(), 'agroterenas.com.br');
    expect($run)->toBeInt()
        ->and(DB::table('whatsapp_company_web_runs_v87')->where('id', $run)->value('domain'))->toBe('agroterenas.com.br');
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class);
});

it('avoids repeated charges when the same search is already pending', function (): void {
    $service = app(WhatsappCompanyQuickResearchV88::class);
    $user = wa88Manager();
    $first = $service->start($user, 'Agroterenas');
    $again = $service->start($user, '  agroterenas  ');
    expect($again)->toBe($first)
        ->and(DB::table('whatsapp_company_web_runs_v87')->count())->toBe(1);
    Bus::assertDispatched(ResearchWhatsappCompanyWebV87::class, 1);
});
