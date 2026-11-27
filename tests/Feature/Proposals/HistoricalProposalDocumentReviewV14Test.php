<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('exibe alternativas documentais V14 sem presumir valor unico ou aceite', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => 'Empresa teste cenários V14', 'service_codes' => ['RETROATIVAS'],
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    $hash = str_repeat('a', 64);
    $review = [
        'version' => 14, 'source' => 'conferencia_pdf_sha256', 'sha256' => $hash,
        'filename' => 'exemplo.pdf', 'filename_number' => '2024/90', 'cover_number' => '2024/91',
        'issued_on' => '2024-11-07', 'client' => 'Empresa teste cenários V14', 'signer' => 'Pessoa Comercial', 'contact' => 'Contato Teste',
        'kind' => 'condicoes', 'principal' => null, 'principal_label' => 'Duas condições comerciais',
        'setup_amount' => '1500.00', 'setup_mode' => 'conditional', 'setup_note' => 'Sem licenciamento.', 'base_quantity' => 5291,
        'scenarios' => [
            ['label' => 'Somente retroativas', 'service' => '7102.50', 'quantity' => 5291, 'setup' => '1500.00', 'total' => '8602.50', 'page' => 3, 'note' => 'Inclui implantação.'],
            ['label' => 'Com licenciamento', 'service' => '6602.50', 'quantity' => null, 'setup' => null, 'total' => '6602.50', 'page' => 3, 'note' => null],
        ],
        'notes' => ['Não presumir modalidade aceita.'], 'conditions' => ['Validade' => '30 dias'], 'preserved_amounts' => [],
    ];
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => $hash,
        'title' => 'Proposta cenários V14', 'document_name' => 'exemplo.pdf', 'historic_year' => 2024,
        'historic_number' => '90', 'services' => '["RETROATIVAS"]', 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'evidence' => json_encode(['review_v14' => $review, 'finance_v2' => ['one_time_state' => 'conflict']], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('Duas condições comerciais')->assertSee('R$ 8.602,50')->assertSee('R$ 6.602,50')->assertSee('Pessoa Comercial');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('Memória de cálculo · cenários da proposta')->assertSee('R$ 7.102,50')->assertSee('R$ 1.500,00')->assertSee('R$ 8.602,50')->assertSee('R$ 6.602,50')->assertSee('EC 2024/91')->assertDontSee('Valor único ainda não identificado');
    expect($proposal->refresh()->status)->toBe('imported')->and($proposal->one_time_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});
