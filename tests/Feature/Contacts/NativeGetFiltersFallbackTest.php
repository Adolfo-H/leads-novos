<?php

use App\Models\HubSpotContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function nativeFilterManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('filtra contatos por GET sem depender de eventos JavaScript', function (): void {
    Mail::fake();
    Queue::fake();

    HubSpotContact::query()->create([
        'hubspot_id' => 'get-filter-test-801',
        'first_name' => 'AuroraFiltros',
        'last_name' => 'Nativa',
        'email' => 'aurora@get-filter.test',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => 'get-filter-test-802',
        'first_name' => 'BrunaFiltros',
        'last_name' => 'Nativa',
        'email' => 'bruna@get-filter.test',
    ]);

    $this->actingAs(nativeFilterManager())
        ->get(route('contacts.index', [
            'q' => 'AuroraFiltros', 'filtro' => 'all', 'fonte' => 'hubspot',
            'wa' => 'all', 'ordem' => 'name', 'porPagina' => 25,
        ]))
        ->assertOk()
        ->assertSee('id="ec-native-contacts-filter"', false)
        ->assertSee('AuroraFiltros')
        ->assertDontSee('BrunaFiltros')
        ->assertSee('Aplicar filtros');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('respeita filtros HTTP de tipo e pagina em verificacao WhatsApp', function (): void {
    Mail::fake();
    Queue::fake();

    $now = now('UTC');
    foreach ([
        ['get-filter-9001', '5545999999001', 'mobile'],
        ['get-filter-9002', '554532345678', 'landline'],
    ] as [$key, $digits, $kind]) {
        DB::table('whatsapp_phone_numbers')->insert([
            'phone_key' => $key,
            'digits' => $digits,
            'e164' => $digits,
            'kind' => $kind,
            'status' => 'unknown',
            'contacts_count' => 1,
            'occurrences_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $this->actingAs(nativeFilterManager())
        ->get(route('contacts.whatsapp', [
            'tipo' => 'landline', 'situacao' => 'all', 'origem' => 'all',
            'consulta' => 'all', 'publico' => 'all', 'porPagina' => 20, 'pagina' => 1,
        ]))
        ->assertOk()
        ->assertSee('id="ec-native-wa-filter"', false)
        ->assertSee('1 números com os filtros atuais')
        ->assertSee('Aplicar filtros');

    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('oferece links reais para filtrar e paginar sem callbacks Livewire', function (): void {
    $contactView = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    $whatsappView = file_get_contents(resource_path('views/pages/contacts/⚡whatsapp.blade.php'));

    expect($contactView)->not->toBeFalse()
        ->and($whatsappView)->not->toBeFalse()
        ->and((string) $contactView)->toContain('EC_NATIVE_CONTACTS_FILTER_GET_V1')
        ->toContain('method="GET"')
        ->toContain("'pagina' => \$index->currentPage() + 1")
        ->toContain("'filtro' => \$code")
        ->and((string) $whatsappView)->toContain('EC_NATIVE_WA_FILTER_GET_V1')
        ->toContain('method="GET"')
        ->toContain("'numero' => \$row->id")
        ->toContain("'pagina' => \$items->currentPage() + 1")
        ->toContain("'numero' => \$row->id");
});
