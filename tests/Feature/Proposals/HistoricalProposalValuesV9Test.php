<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe servicos retroativos combinados como pagamento unico e preserva a implantacao condicional', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa exemplo V9', 'service_codes' => ['RETROATIVAS', 'ACOMPANHAMENTO'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('a', 64),
        'title' => 'Proposta exemplo V9', 'document_name' => 'exemplo.pdf',
        'services' => '["RETROATIVAS","ACOMPANHAMENTO"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => null, 'one_time_amount' => '2850.00',
        'evidence' => json_encode(['finance_v2' => [
            'reader_version' => 9, 'billing_mode' => 'historical_bundle_one_time',
            'monthly_state' => 'not_applicable', 'one_time_state' => 'identified',
            'bundle_estimated' => true, 'base_quantity' => '2000',
            'implantation_amount' => '3000.00', 'implantation_mode' => 'conditional',
            'conditional_total' => '5850.00', 'calculation_mode' => 'service_components',
            'components' => [
                ['service' => 'RETROATIVAS', 'label' => 'Consultas retroativas', 'billing' => 'one_time', 'amount' => '2250.00', 'table_quantity' => '2000', 'table_total' => '2250.00', 'bands' => [], 'estimated' => false],
                ['service' => 'ACOMPANHAMENTO', 'label' => 'Acompanhamento retroativo e cobrança', 'billing' => 'one_time', 'amount' => '600.00', 'table_quantity' => '600', 'table_total' => '600.00', 'bands' => [], 'estimated' => true],
            ],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Valor total dos serviços · pagamento único')
        ->assertSee('R$ 2.850,00')->assertSee('R$ 2.250,00')->assertSee('R$ 600,00')
        ->assertSee('Implantação condicional')->assertSee('R$ 5.850,00')
        ->assertDontSee('Mensalidade ainda não identificada');
    $this->get(route('proposals.index'))->assertOk()->assertSee('Pagamento único')->assertSee('R$ 2.850,00')->assertSee('Acompanhamento retroativo');
    expect($proposal->refresh()->status)->toBe('imported')->and($proposal->accepted_at)->toBeNull()->and($proposal->proposal_numbers)->toBeNull();
});

it('exibe a mensalidade recuperada e sinaliza divergencia da base anual', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa mensal V9', 'service_codes' => ['ACOMPANHAMENTO'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('b', 64),
        'title' => 'Proposta mensal V9', 'document_name' => 'mensal.pdf', 'services' => '["ACOMPANHAMENTO"]',
        'commercial_status' => 'sent_history', 'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => '1450.00', 'one_time_amount' => null,
        'evidence' => json_encode(['finance_v2' => ['reader_version' => 9, 'monthly_state' => 'identified', 'billing_mode' => 'monthly_reference', 'base_quantity' => '1500', 'annual_quantity' => '18000', 'annual_basis' => 'projected', 'annual_reference_conflict' => ['18000', '1800']]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('R$ 1.450,00')->assertSee('Projeção anual da precificação')->assertSee('18.000')
        ->assertSee('O documento apresenta volumes anuais diferentes')->assertDontSee('Mensalidade ainda não identificada');
    $this->get(route('proposals.index'))->assertOk()->assertSee('R$ 1.450,00')->assertDontSee('Mensalidade pendente');
});
