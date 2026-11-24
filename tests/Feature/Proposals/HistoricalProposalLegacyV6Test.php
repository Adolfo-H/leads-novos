<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('preserva na V6 o pagamento único e a implantação condicional das retroativas', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $p = EcProposal::query()->create(['public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported', 'client_name' => 'Empresa retroativa exemplo', 'service_codes' => ['RETROATIVAS'], 'created_by' => $manager->id, 'updated_by' => $manager->id]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $p->id, 'document_sha256' => str_repeat('a', 64), 'title' => 'Proposta histórica exemplo', 'document_name' => 'exemplo.pdf', 'services' => '["RETROATIVAS"]', 'commercial_status' => 'sent_history', 'extraction_status' => 'extracted', 'crm_match' => 'company_only', 'monthly_amount' => null, 'one_time_amount' => '10122.50', 'evidence' => json_encode(['finance_v2' => ['reader_version' => 5, 'monthly_state' => 'not_applicable', 'one_time_state' => 'identified', 'implantation_amount' => '3000.00', 'implantation_mode' => 'conditional', 'conditional_total' => '13122.50', 'calculation_mode' => 'single_fee', 'base_quantity' => '6938', 'conditions' => ['prazo' => '45 dias', 'periodo_consultado' => '2020–2022']]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $p))->assertOk()->assertSee('R$ 10.122,50')->assertSee('R$ 3.000,00')->assertSee('R$ 13.122,50')->assertSee('6.938')->assertSee('Implantação condicional')->assertSee('Não há tabela progressiva')->assertSee('45 dias')->assertSee('2020–2022')->assertDontSee('Mensalidade ainda não identificada');
    $this->get(route('proposals.index'))->assertOk()->assertSee('R$ 10.122,50')->assertSee('Pagamento único')->assertSee('Implantação condicional');
    expect($p->refresh()->status)->toBe('imported')->and($p->accepted_at)->toBeNull()->and($p->proposal_numbers)->toBeNull();
});

it('exibe a tabela base pre fixada e separa a modalidade pos fixada das simulacoes', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $p = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa exemplo', 'service_codes' => ['LICENCIAMENTO'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $p->id, 'document_sha256' => str_repeat('b', 64),
        'title' => 'Proposta histórica de exemplo', 'document_name' => 'exemplo.pdf',
        'services' => '["LICENCIAMENTO"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => '500.00', 'one_time_amount' => '3000.00',
        'evidence' => json_encode(['finance_v2' => [
            'reader_version' => 6, 'monthly_state' => 'identified', 'one_time_state' => 'identified',
            'billing_mode' => 'pre_fixed_reference', 'base_label' => 'Base mensal da modalidade pré-fixada',
            'base_quantity' => '157', 'annual_quantity' => '1887', 'reference_year' => '2022',
            'implantation_amount' => '3000.00', 'implantation_mode' => 'stated',
            'legacy_table' => true, 'table_quantity' => '157', 'table_total' => '500.00',
            'bands' => [['faixa' => '1', 'min' => '1', 'max' => '500', 'fixo' => '500,00', 'unitario' => '-', 'quantidade' => '157', 'subtotal' => '500,00']],
            'illustrative_tables' => [['mode' => 'example', 'table_quantity' => '1500', 'table_total' => '1400.00', 'bands' => []]],
            'conditions' => ['teste' => '30 dias de teste', 'pagamento' => 'Aproximadamente 60 dias'],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $p))->assertOk()
        ->assertSee('Mensalidade pré-fixada')->assertSee('R$ 500,00')->assertSee('R$ 3.000,00')
        ->assertSee('Total da tabela-base pré-fixada')->assertSee('157')->assertSee('1.887')
        ->assertSee('Documentos em 2022')->assertSee('Modalidade pós-fixada e exemplos')
        ->assertSee('R$ 1.400,00')->assertSee('exemplo, não valor contratado');
    $this->get(route('proposals.index'))->assertOk()->assertSee('Estimativa pré-fixada')->assertSee('Pós-fixada: conforme uso')->assertDontSee('R$ 1.400,00');
    expect($p->refresh()->status)->toBe('imported')->and($p->accepted_at)->toBeNull();
});
