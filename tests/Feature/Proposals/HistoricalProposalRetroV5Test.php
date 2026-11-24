<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe o pagamento único histórico e a implantação condicional sem presumir mensalidade', function (): void {
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
