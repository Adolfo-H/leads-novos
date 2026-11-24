<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe descontos e alternativas de implantacao sem confundir com aceite ou mensalidade', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa condicional V10', 'service_codes' => ['RETROATIVAS'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('a', 64),
        'title' => 'Proposta condicional V10', 'document_name' => 'exemplo.pdf',
        'services' => '["RETROATIVAS"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => null, 'one_time_amount' => '800.00',
        'evidence' => json_encode(['finance_v2' => [
            'reader_version' => 10, 'billing_mode' => 'historical_one_time_scenarios',
            'monthly_state' => 'not_applicable', 'one_time_state' => 'identified',
            'implantation_amount' => '300.00', 'implantation_mode' => 'conditional',
            'conditional_total' => '1300.00', 'calculation_mode' => 'single_fee',
            'pricing_terms_v10' => [
                'kind' => 'one_time', 'gross' => '1000.00', 'discount_amount' => '200.00',
                'conditional_discount' => true, 'scenarios' => [
                    ['label' => 'Com aceite do licenciamento', 'service' => '800.00', 'implantation' => null, 'total' => '800.00'],
                    ['label' => 'Sem aceite do licenciamento', 'service' => '1000.00', 'implantation' => '300.00', 'total' => '1300.00'],
                ],
            ],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Composição do investimento')->assertSee('Cenários comerciais previstos')
        ->assertSee('R$ 800,00')->assertSee('R$ 1.300,00')->assertSee('R$ 200,00')
        ->assertSee('Nenhuma condição comercial foi presumida como aceita');
    $this->get(route('proposals.index'))->assertOk()->assertSee('com desconto condicional')->assertSee('Sem aceite:');
    expect($proposal->refresh()->status)->toBe('imported')->and($proposal->accepted_at)->toBeNull()->and($proposal->proposal_numbers)->toBeNull();
});

it('exibe desenvolvimento pontual e adicional mensal percentual em vez de mensalidade pendente', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa percentual V10', 'service_codes' => ['DFE'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('b', 64),
        'title' => 'Proposta percentual V10', 'document_name' => 'exemplo.pdf',
        'services' => '["DFE"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => null, 'one_time_amount' => '1200.00',
        'evidence' => json_encode(['finance_v2' => [
            'reader_version' => 10, 'billing_mode' => 'percentage_addon',
            'monthly_state' => 'formula', 'one_time_state' => 'identified',
            'calculation_mode' => 'custom_development',
            'pricing_terms_v10' => ['kind' => 'percentage_addon', 'development_amount' => '1200.00', 'percent' => '10', 'condition' => 'Caso atenda às necessidades.'],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Adicional sobre mensalidade vigente')->assertSee('+10%')->assertSee('R$ 1.200,00')
        ->assertSee('Caso atenda às necessidades')->assertDontSee('Mensalidade ainda não identificada');
    $this->get(route('proposals.index'))->assertOk()->assertSee('+10%')->assertSee('Desenvolvimento · pontual')->assertDontSee('Mensalidade pendente');
});
