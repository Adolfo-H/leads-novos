<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\ContactPeopleDirectory;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('agrupa canais institucionais repetidos de matriz e filial sem apagar ou cruzar empresas', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '88113322', 'corporate_name' => 'Cooperativa Exemplo',
    ]);
    $otherCompany = Company::query()->create([
        'cnpj_root' => '99113322', 'corporate_name' => 'Outra Cooperativa',
    ]);
    $first = Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '88113322000101',
        'type' => 'matrix', 'order_number' => '0001', 'check_digits' => '01',
        'email' => 'CONTATO@EXEMPLO.COM.BR', 'phone_1' => '(45) 3333-1111',
    ]);
    $branch = Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '88113322000292',
        'type' => 'branch', 'order_number' => '0002', 'check_digits' => '92',
        'email' => 'contato@exemplo.com.br', 'phone_1' => '4533331111',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '88113322000373',
        'type' => 'branch', 'order_number' => '0003', 'check_digits' => '73',
        'email' => 'outro@exemplo.com.br', 'phone_1' => '4533333333',
    ]);
    Establishment::query()->create([
        'company_id' => $otherCompany->id, 'cnpj' => '99113322000107',
        'type' => 'matrix', 'order_number' => '0001', 'check_digits' => '07',
        'email' => 'CONTATO@EXEMPLO.COM.BR', 'phone_1' => '(45) 3333-1111',
    ]);

    $dir = app(ContactPeopleDirectory::class);
    expect($dir->paginate('', 'receita')->total())->toBe(3)
        ->and($dir->totalsLean())->toBe($dir->totalsAggregated())
        ->and($dir->totals()['receita'])->toBe(3)
        ->and($dir->paginate()->total())->toBe(3)
        ->and(Establishment::query()->count())->toBe(4);

    $visible = collect($dir->paginate('', 'receita')->items())->pluck('source_key')->all();
    expect($visible)->toContain('receita:'.$first->uuid)
        ->not->toContain('receita:'.$branch->uuid);

    // Busca pelo CNPJ da filial deve continuar acessando seu registro original.
    expect($dir->paginate('88113322000292')->total())->toBe(1)
        ->and($dir->paginate('88113322000292')->items()[0]->source_key)->toBe('receita:'.$branch->uuid);
});

it('agrupa telefones iguais sem e-mail, mas preserva canais diferentes', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '66778899', 'corporate_name' => 'Matriz e Filiais Exemplo',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '66778899000100',
        'type' => 'matrix', 'order_number' => '0001', 'check_digits' => '00',
        'phone_1' => '(45) 3333-1111',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '66778899000290',
        'type' => 'branch', 'order_number' => '0002', 'check_digits' => '90',
        'phone_1' => '4533331111',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '66778899000371',
        'type' => 'branch', 'order_number' => '0003', 'check_digits' => '71',
        'phone_1' => '4533332222',
    ]);

    $dir = app(ContactPeopleDirectory::class);
    expect($dir->paginate('', 'receita')->total())->toBe(2)
        ->and($dir->paginate('', 'all', 'all', 'all', null, 1, 12, 'recent', true)->total())->toBe(2)
        ->and(Establishment::query()->count())->toBe(3);
});

it('mostra positivos do robo como evidencia tecnica e filtra sem forjar confirmacao manual', function (): void {
    $person = HubSpotContact::query()->create([
        'hubspot_id' => '99080001', 'first_name' => 'Pessoa', 'last_name' => 'Robô',
        'mobile_phone' => '(45) 99999-9090',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();
    $phoneId = (int) DB::table('whatsapp_phone_numbers')->where('e164', '5545999999090')->value('id');
    expect($phoneId)->toBeGreaterThan(0);
    DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->update([
        'status' => 'yes', 'evidence' => 'web_found_unofficial',
        'verified_at' => now('UTC'), 'verified_by' => null,
    ]);

    $directory = app(ContactPeopleDirectory::class);
    $record = $directory->paginate('Pessoa Robô')->items()[0];
    expect($record->source_key)->toBe('hubspot:'.$person->hubspot_id)
        ->and($record->whatsapp_status)->toBe('unknown')
        ->and($record->whatsapp_robot_identified)->toBeTrue()
        ->and($directory->paginate('', 'all', 'all', 'identified')->total())->toBe(1)
        ->and($directory->paginate('', 'all', 'all', 'yes')->total())->toBe(0)
        ->and(app(WhatsappPhoneRegistry::class)->totals()['yes'])->toBe(1)
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($manager)->get(route('contacts.index', ['wa' => 'identified']))
        ->assertOk()
        ->assertSee('WhatsApp identificado · robô')
        ->assertSee('Com WhatsApp (robô ou manual)');
});
