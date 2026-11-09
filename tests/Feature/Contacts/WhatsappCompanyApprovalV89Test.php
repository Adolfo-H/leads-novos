<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\WhatsappCompanyPhoneApprovalV89;
use App\Services\Contacts\WhatsappCompanyQuickResearchV88;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function wa89Actor(bool $manager = true): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $manager ? User::ROLE_MANAGER : User::ROLE_SELLER])->save();

    return $user;
}

/** @return array{actor:User, company:HubSpotCompany, key:string, run:int} */
function wa89Scenario(): array
{
    $actor = wa89Actor();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA89_COMPANY_AGRO', 'name' => 'Agroterenas S.A.',
        'domain' => 'agroterenas.example.org',
    ]);
    $key = app(WhatsappCompanyQuickResearchV88::class)->keyFor('Agroterenas');
    $run = (int) DB::table('whatsapp_company_web_runs_v87')->insertGetId([
        'target_key' => $key, 'company_label' => 'Agroterenas',
        'status' => 'completed', 'findings_count' => 2,
        'finished_at' => now('UTC'), 'created_by' => $actor->id,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    foreach (['551130395300' => 'public_phone_not_verified', '5511971707811' => 'explicit_whatsapp_link'] as $phone => $kind) {
        DB::table('whatsapp_company_web_findings_v87')->insert([
            'run_id' => $run, 'phone_e164' => $phone, 'kind' => $kind,
            'provider' => 'tavily_index', 'source_url' => 'https://agroterenas.example.org/contato',
            'source_hash' => hash('sha256', $phone.'|https://agroterenas.example.org/contato'),
            'title' => 'Fonte publicada para conferência',
            'discovered_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    return compact('actor', 'company', 'key', 'run');
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
});

it('shows whether discovered phones are new or already in the Prospector without saving them', function (): void {
    $scenario = wa89Scenario();
    $service = app(WhatsappCompanyPhoneApprovalV89::class);
    expect($service->targets('Agroterenas'))->toContain([
        'key' => 'hubspot:'.$scenario['company']->id,
        'name' => 'Agroterenas S.A.', 'source' => 'Empresa HubSpot',
    ]);
    $before = $service->inventory(['551130395300', '5511971707811']);
    expect($before['551130395300']['exists'])->toBeFalse()
        ->and($before['5511971707811']['approved_here'])->toBeFalse()
        ->and(DB::table('whatsapp_company_web_approved_v89')->count())->toBe(0)
        ->and(DB::table('whatsapp_phone_numbers')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('saves only approved sources and phone indexes, preserving unknown WhatsApp status and future syncs', function (): void {
    $scenario = wa89Scenario();
    $service = app(WhatsappCompanyPhoneApprovalV89::class);
    $out = $service->approve($scenario['actor'], 'Agroterenas', $scenario['key'], $scenario['run'],
        'hubspot:'.$scenario['company']->id, ['551130395300', '5511971707811'], 'Conferido na fonte pública');

    expect($out)->toBe(['created' => 2, 'already' => 0, 'total' => 2]);
    $rows = DB::table('whatsapp_company_web_approved_v89')->get();
    expect($rows)->toHaveCount(2);
    expect((string) $rows->first()->note)->toBe('Conferido na fonte pública');
    expect(DB::table('whatsapp_phone_numbers')->whereIn('e164', ['551130395300', '5511971707811'])->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(0)
        ->and(DB::table('whatsapp_phone_sources')->where('origin', 'public')
            ->where('source_key', 'like', 'web-approved-v89:%')->count())->toBe(2);

    // Uma reconstrução posterior não pode apagar as associações aprovadas.
    app(WhatsappPhoneRegistry::class)->sync();
    expect(DB::table('whatsapp_phone_sources')->where('origin', 'public')
        ->where('source_key', 'like', 'web-approved-v89:%')->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->whereIn('e164', ['551130395300', '5511971707811'])
            ->where('contacts_count', '>', 0)->count())->toBe(2);
    $saved = $service->inventory(['551130395300'], 'hubspot:'.$scenario['company']->id);
    expect($saved['551130395300']['approved_here'])->toBeTrue();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('is idempotent and never rewrites an existing WhatsApp confirmation', function (): void {
    $scenario = wa89Scenario();
    $user = HubSpotContact::query()->create([
        'hubspot_id' => 'WA89_CONTACT_EXISTING',
        'first_name' => 'Contato', 'phone' => '+55 (11) 3039-5300',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();
    DB::table('whatsapp_phone_numbers')->where('e164', '551130395300')->update([
        'status' => 'yes', 'evidence' => 'manual_app_confirmed', 'verified_at' => now('UTC'),
    ]);
    $service = app(WhatsappCompanyPhoneApprovalV89::class);
    $args = [$scenario['actor'], 'Agroterenas', $scenario['key'], $scenario['run'],
        'hubspot:'.$scenario['company']->id, ['551130395300']];
    expect($service->approve(...$args)['created'])->toBe(1)
        ->and($service->approve(...$args)['created'])->toBe(0)
        ->and(DB::table('whatsapp_company_web_approved_v89')->count())->toBe(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('e164', '551130395300')->count())->toBe(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('e164', '551130395300')->value('status'))->toBe('yes')
        ->and(DB::table('whatsapp_phone_numbers')->where('e164', '551130395300')->value('evidence'))->toBe('manual_app_confirmed');
    Mail::assertNothingSent();
});

it('blocks unrelated company targets, forged numbers, unfinished searches and sellers', function (): void {
    $scenario = wa89Scenario();
    $foreign = Company::query()->create(['cnpj_root' => '77992211', 'corporate_name' => 'EMPRESA TOTALMENTE DISTINTA']);
    $service = app(WhatsappCompanyPhoneApprovalV89::class);
    $base = [$scenario['actor'], 'Agroterenas', $scenario['key'], $scenario['run']];

    expect(fn () => $service->approve(...[...$base, 'local:'.$foreign->id, ['551130395300']]))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->approve(...[...$base, 'hubspot:'.$scenario['company']->id, ['5511999999999']]))
        ->toThrow(ValidationException::class);
    DB::table('whatsapp_company_web_runs_v87')->where('id', $scenario['run'])->update(['status' => 'queued']);
    expect(fn () => $service->approve(...[...$base, 'hubspot:'.$scenario['company']->id, ['551130395300']]))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->approve(wa89Actor(false), 'Agroterenas', $scenario['key'], $scenario['run'],
        'hubspot:'.$scenario['company']->id, ['551130395300']))->toThrow(HttpException::class);
    expect(DB::table('whatsapp_company_web_approved_v89')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('shows the review form and lets a manager select and approve through Livewire', function (): void {
    $scenario = wa89Scenario();
    $name = 'Agroterenas';
    $component = Livewire::actingAs($scenario['actor'])->test('pages::contacts.whatsapp')
        ->set('publicQuickNameV88', $name)
        ->set('publicQuickSearchKeyV88', $scenario['key'])
        ->assertSee('Salvar telefones selecionados na empresa')
        ->assertSee('Novo no diretório')
        ->set('publicSelectedPhonesV89', ['551130395300'])
        ->set('publicSaveTargetV89', 'hubspot:'.$scenario['company']->id)
        ->set('publicApprovalConfirmedV89', true)
        ->call('savePublicNumbersV89')->assertHasNoErrors()
        ->assertSee('vínculo(s) novo(s) criado(s)');
    expect(DB::table('whatsapp_company_web_approved_v89')->count())->toBe(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('e164', '551130395300')->value('status'))->toBe('unknown');
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
