<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exibe pacote anual e seus avulsos sem repetir a cobrança mensal ou a implantação', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa de teste V12', 'service_codes' => ['LICENCIAMENTO', 'RETROATIVAS'],
        'issued_on' => '2022-10-21', 'monthly_final' => '500.00', 'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => str_repeat('d', 64),
        'title' => 'Proposta anual V12', 'document_name' => 'exemplo.pdf',
        'historic_year' => 2022, 'historic_number' => '162', 'issued_on' => '2022-10-21',
        'services' => '["LICENCIAMENTO","RETROATIVAS"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'monthly_amount' => '500.00', 'one_time_amount' => '9192.00',
        'evidence' => json_encode(['billing_v12' => [
            'kind' => 'annual_package', 'monthly_reference' => '500.00', 'annual_amount' => '6000.00',
            'initial_total' => '15692.00', 'implantation' => '3000.00', 'retroactive' => '6192.00',
            'historical_import' => null, 'extra_period' => '500.00', 'one_off_total' => '9192.00',
            'monthly_quantity' => 347, 'annual_quantity' => 4168, 'retro_quantity' => 4446,
            'payment_days' => 15, 'warnings' => [], 'evidence' => [],
        ]], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()
        ->assertSee('Licenciamento · pacote anual')->assertSee('R$ 6.000,00')->assertSee('R$ 15.692,00')
        ->assertSee('R$ 6.192,00')->assertSee('R$ 3.000,00')->assertSee('não é cobrança mensal adicional');
    $this->get(route('proposals.index', ['hist_year' => '2022']))->assertOk()->assertSee('Proposta anual V12')
        ->assertSee('Pacote anual')->assertSee('R$ 15.692,00')->assertSee('Consultas retroativas:');
    expect($proposal->refresh()->status)->toBe('imported')->and($proposal->proposal_numbers)->toBeNull();
});
