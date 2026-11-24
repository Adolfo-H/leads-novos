<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('mostra módulos com valores separados e preserva o fluxo formal', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa teste V13', 'service_codes' => ['DFE', 'NFSE'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('e', 64),
        'title' => 'Proposta módulos V13', 'document_name' => 'exemplo.pdf', 'historic_year' => 2026,
        'historic_number' => '513', 'services' => '["DFE","NFSE"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'evidence' => json_encode(['review_v13' => [
            'version' => 13, 'price' => ['kind' => 'monthly_components', 'state' => 'identified', 'monthly' => '430.00', 'one_time' => null,
                'components' => [
                    ['service' => 'DFE', 'label' => 'DF-e', 'billing' => 'monthly', 'amount' => '250.00'],
                    ['service' => 'NFSE', 'label' => 'NFS-e', 'billing' => 'monthly', 'amount' => '180.00'],
                ], 'evidence' => [], 'warnings' => []],
            'issue' => ['date' => '2026-04-06', 'state' => 'identified'], 'signature' => ['name' => 'Pessoa Comercial', 'email' => 'pessoa@exportcontrol.com.br'],
            'volumes' => [], 'warnings' => [],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('R$ 430,00')->assertSee('R$ 250,00')->assertSee('R$ 180,00')
        ->assertSee('Pessoa Comercial')->assertSee('06/04/2026');
    $this->get(route('proposals.index'))->assertOk()->assertSee('Proposta módulos V13')->assertSee('R$ 430,00');
    expect($proposal->refresh()->status)->toBe('imported')->and($proposal->accepted_at)->toBeNull()->and($proposal->proposal_numbers)->toBeNull();
});

it('não trata diferença de um centavo como bloqueio e conserva preço registrado', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa arredondamento', 'service_codes' => ['ACOMPANHAMENTO'], 'monthly_final' => '2074.31',
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('f', 64),
        'title' => 'Proposta arredondamento V13', 'document_name' => 'exemplo.pdf', 'historic_year' => 2025,
        'historic_number' => '348', 'services' => '["ACOMPANHAMENTO"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only', 'monthly_amount' => '2074.31',
        'evidence' => json_encode(['finance_v2' => ['monthly_state' => 'conflict'], 'audit_v11' => ['monthly' => ['state' => 'declared_table_conflict']], 'review_v13' => [
            'price' => ['kind' => 'monthly', 'state' => 'rounding', 'monthly' => '2074.30', 'one_time' => null, 'table_amount' => '2074.31', 'components' => [], 'warnings' => [], 'evidence' => []],
            'issue' => ['state' => 'identified', 'date' => '2025-02-25'], 'signature' => null, 'volumes' => [], 'warnings' => [],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Possível arredondamento de R$ 0,01')->assertSee('R$ 2.074,30')->assertSee('R$ 2.074,31')
        ->assertDontSee('Foram identificados valores mensais divergentes');
    expect((string) $proposal->refresh()->monthly_final)->toBe('2074.31');
});
