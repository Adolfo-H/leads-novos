<?php

use App\Livewire\WhatsappCompanyLinksV16;
use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\User;
use App\Services\Contacts\WhatsappCompanyChannelLinkV16;
use App\Services\Contacts\WhatsappPublicCompanyChannels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/** @return array{0:User,1:HubSpotCompany,2:Company,3:list<int>} */
function wa17Fixture(): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $source = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA17_SOURCE', 'name' => 'Empresa Site V17', 'domain' => 'wa17-site.example.org',
    ]);
    $dest = Company::query()->create([
        'cnpj_root' => '12345789', 'corporate_name' => 'DESTINO TESTE V17',
    ]);
    $now = now('UTC');
    foreach (['5545999991001', '5545999991002'] as $n) {
        DB::table('whatsapp_public_site_evidences')->insert([
            'domain' => 'wa17-site.example.org', 'e164' => $n,
            'source_url' => 'https://wa17-site.example.org/contato',
            'wa_url' => 'https://wa.me/'.$n,
            'first_seen_at' => $now, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    app(WhatsappPublicCompanyChannels::class)->syncDomain('wa17-site.example.org');
    $ids = DB::table('whatsapp_public_company_channels')
        ->where('hubspot_company_id', $source->id)
        ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

    return [$user, $source, $dest, $ids];
}

it('links multiple published numbers in one operation and preserves their original sources', function (): void {
    [$actor, $source, $dest, $ids] = wa17Fixture();

    $linked = app(WhatsappCompanyChannelLinkV16::class)
        ->assignMany($actor, [$ids[0], $ids[1]], 'local:'.$dest->id);

    expect($linked)->toBe(2)
        ->and(DB::table('whatsapp_company_channel_links_v16')->count())->toBe(2)
        ->and(DB::table('whatsapp_company_channel_links_v16')->where('target_id', $dest->id)->count())->toBe(2)
        ->and(DB::table('whatsapp_public_company_channels')->where('hubspot_company_id', $source->id)->count())->toBe(2)
        ->and(DB::table('commercial_contacts')->count())->toBe(0);
});

it('shows only one linking area and selects all visible numbers of the source company', function (): void {
    [$actor, $source, $dest] = wa17Fixture();

    $this->actingAs($actor)->get(route('contacts.index'))
        ->assertOk()
        ->assertSee('Vincular WhatsApps às empresas')
        ->assertSee('data-test="wa16-company-linker"', false);

    Livewire::actingAs($actor)
        ->test(WhatsappCompanyLinksV16::class)
        ->call('selectGroup', $source->id)
        ->assertSet('selectedChannels', fn (array $ids): bool => count($ids) === 2)
        ->set('companySearch', 'DESTINO TESTE V17')
        ->call('chooseCompany', 'local:'.$dest->id)
        ->call('saveLinks')
        ->assertHasNoErrors();

    expect(DB::table('whatsapp_company_channel_links_v16')->count())->toBe(2);
});

it('rejects mixed source companies and keeps all existing links unchanged', function (): void {
    [$actor, $source, $dest, $ids] = wa17Fixture();
    $other = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA17_OTHER', 'name' => 'Outra empresa', 'domain' => 'wa17-other.example.org',
    ]);
    $when = now('UTC');
    DB::table('whatsapp_public_site_evidences')->insert([
        'domain' => 'wa17-other.example.org', 'e164' => '5511999997777',
        'source_url' => 'https://wa17-other.example.org/contato', 'wa_url' => 'https://wa.me/5511999997777',
        'first_seen_at' => $when, 'last_seen_at' => $when,
        'created_at' => $when, 'updated_at' => $when,
    ]);
    app(WhatsappPublicCompanyChannels::class)->syncDomain('wa17-other.example.org');
    $otherId = (int) DB::table('whatsapp_public_company_channels')->where('hubspot_company_id', $other->id)->value('id');

    expect(fn () => app(WhatsappCompanyChannelLinkV16::class)
        ->assignMany($actor, [$ids[0], $otherId], 'local:'.$dest->id))
        ->toThrow(ValidationException::class);
    expect(DB::table('whatsapp_company_channel_links_v16')->count())->toBe(0);
});

it('does not silently reassign an already linked channel and refuses seller changes', function (): void {
    [$actor, $source, $dest, $ids] = wa17Fixture();
    $service = app(WhatsappCompanyChannelLinkV16::class);
    $service->assign($actor, $ids[0], 'local:'.$dest->id);

    expect(fn () => $service->assignMany($actor, [$ids[0], $ids[1]], 'hubspot:'.$source->id))
        ->toThrow(ValidationException::class);
    expect(DB::table('whatsapp_company_channel_links_v16')->where('target_type', 'local')->count())->toBe(1)
        ->and(DB::table('whatsapp_company_channel_links_v16')->count())->toBe(1);

    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => $service->assignMany($seller, [$ids[1]], 'local:'.$dest->id))
        ->toThrow(HttpException::class);
});
