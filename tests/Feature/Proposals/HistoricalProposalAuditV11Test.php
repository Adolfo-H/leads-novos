<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe o investimento declarado e o total da tabela sem presumir qual foi contratado', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa de teste V11', 'service_codes' => ['LICENCIAMENTO'],
        'issued_on' => '2024-02-22', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('c', 64),
        'title' => 'Proposta divergente V11', 'document_name' => 'exemplo.pdf',
        'historic_year' => 2023, 'historic_number' => '224', 'issued_on' => '2024-02-22',
        'services' => '["LICENCIAMENTO"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => null, 'one_time_amount' => '3000.00',
        'evidence' => json_encode(['finance_v2' => [
            'billing_mode' => 'pre_fixed_reference', 'monthly_state' => 'conflict',
            'implantation_amount' => '3000.00', 'base_quantity' => '213',
        ], 'audit_v11' => [
            'version' => 11, 'source' => 'document_sha256',
            'issue' => ['date' => '2024-02-22', 'state' => 'identified', 'number_year' => '2023', 'year_mismatch' => true],
            'monthly' => ['state' => 'declared_table_conflict', 'declared_values' => ['834.35'], 'table_values' => ['500.00'], 'net_values' => [], 'prefixed' => true, 'reason' => 'O investimento mensal declarado difere do total da tabela-base.', 'evidence' => [], 'table' => ['table_total' => '500.00', 'table_quantity' => '213', 'bands' => [['faixa' => '1', 'min' => '1', 'max' => '500', 'fixo' => '500,00', 'unitario' => '-', 'quantidade' => '213', 'subtotal' => '500,00']]]],
            'warnings' => [],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Investimento mensal declarado')->assertSee('R$ 834,35')->assertSee('R$ 500,00')
        ->assertSee('Conferência de valores do documento')->assertSee('22/02/2024')
        ->assertSee('Ano da numeração: 2023')->assertSee('Não confirmado');
    $this->get(route('proposals.index', ['hist_year' => '2024']))->assertOk()->assertSee('Proposta divergente V11')->assertSee('R$ 834,35')->assertSee('Tabela-base:');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->status)->toBe('imported')->and($proposal->proposal_numbers)->toBeNull();
});
