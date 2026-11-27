<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ecHistoryV16Fixture(User $manager, array $review, array $services): EcProposal
{
    $proposal = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'client_name' => $review['client'], 'service_codes' => $services,
        'created_by' => $manager->id, 'updated_by' => $manager->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $proposal->id, 'document_sha256' => $review['sha256'],
        'title' => $review['filename'], 'document_name' => $review['filename'], 'historic_year' => 2025,
        'historic_number' => '416', 'services' => json_encode($services, JSON_THROW_ON_ERROR), 'commercial_status' => 'sent_history',
        'extraction_status' => 'extracted', 'crm_match' => 'company_only',
        'evidence' => json_encode(['review_v16' => $review, 'finance_v2' => ['monthly_state' => 'conflict']], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);

    return $proposal;
}

function ecHistoryV16Base(string $client): array
{
    return [
        'version' => 16, 'source' => 'conferencia_documental_sha256', 'sha256' => hash('sha256', $client),
        'filename' => 'Proposta '.$client.'.pdf', 'filename_number' => '2025/420', 'cover_number' => '2025/416',
        'issued_on' => '2025-09-09', 'client' => $client, 'signer' => 'Comercial Teste', 'contact' => 'Contato Teste',
        'principal' => null, 'setup_amount' => null, 'base_quantity' => null,
        'notes' => ['Não presumir aceite.'], 'conditions' => ['Validade' => '10 dias'], 'preserved_amounts' => [], 'preserved_monthly_amounts' => [],
    ];
}

it('exibe mensalidades alternativas V16 sem somar a implantacao nem presumir aceite', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = ecHistoryV16Base('STC fictícia V16') + [
        'kind' => 'modalidades', 'scope' => 'Licenciamento · modalidades de ambiente', 'principal_label' => 'Modalidades de licenciamento',
        'setup_mode' => 'by_modality', 'setup_note' => 'Conforme modalidade.',
        'scenarios' => [
            ['label' => 'Ambiente compartilhado', 'service' => null, 'monthly' => '320.16', 'quantity' => 166, 'setup' => '2000.00', 'total' => null, 'page' => 4, 'locator' => 'PDF, p. 4'],
            ['label' => 'Ambiente exclusivo por empresa', 'service' => null, 'monthly' => '300.00', 'quantity' => 41, 'setup' => '2000.00', 'total' => null, 'page' => 4, 'locator' => 'PDF, p. 4'],
            ['label' => 'Três ambientes exclusivos', 'service' => null, 'monthly' => null, 'quantity' => null, 'setup' => '3000.00', 'total' => '3000.00', 'page' => 5, 'locator' => 'PDF, p. 5'],
        ],
    ];
    $proposal = ecHistoryV16Fixture($manager, $review, ['LICENCIAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('Modalidades de licenciamento')->assertSee('R$ 320,16/mês')->assertSee('R$ 300,00/mês');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 320,16/mês')->assertSee('R$ 300,00/mês')->assertSee('R$ 3.000,00')->assertSee('Implantação por modalidade');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->one_time_final)->toBeNull()->and($proposal->status)->toBe('imported');
});

it('exibe o pacote estimado V16 e a diferenca declarada de um centavo', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV16Base('Semegrão fictícia V16'), [
        'kind' => 'componentes', 'scope' => 'Consultas retroativas + acompanhamento retroativo', 'principal_label' => 'Pacote com desconto · estimado',
        'summary' => '63004.00', 'setup_amount' => '5000.00', 'setup_mode' => 'conditional', 'setup_note' => 'Somente nas consultas sem licença.',
        'scenarios' => [
            ['label' => 'Consultas retroativas', 'service' => '44502.44', 'quantity' => 51322, 'setup' => null, 'total' => '44502.44', 'page' => 4],
            ['label' => 'Acompanhamento retroativo estimado', 'service' => '20726.69', 'quantity' => 15396, 'setup' => null, 'total' => '20726.69', 'page' => 3],
            ['label' => 'Pacote com desconto', 'service' => '65229.13', 'quantity' => null, 'setup' => null, 'discount' => '2225.12', 'total' => '63004.00', 'computed_total' => '63004.01', 'page' => 4, 'note' => 'Diferença documentada.'],
        ],
    ]);
    $proposal = ecHistoryV16Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 63.004,00')->assertSee('R$ 44.502,44')->assertSee('R$ 20.726,69');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 2.225,12')->assertSee('R$ 63.004,01')->assertSee('R$ 63.004,00')->assertSee('conferir diferença');
    expect($proposal->refresh()->one_time_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});
