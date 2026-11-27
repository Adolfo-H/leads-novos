<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ecHistoryV24Fixture(User $manager, array $review, array $services): EcProposal
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
        'evidence' => json_encode(['review_v24' => $review, 'finance_v2' => ['monthly_state' => 'conflict']], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);

    return $proposal;
}

function ecHistoryV24Base(string $client): array
{
    return [
        'version' => 24, 'source' => 'conferencia_documental_sha256', 'sha256' => hash('sha256', $client),
        'filename' => 'Proposta '.$client.'.pdf', 'filename_number' => '2025/420', 'cover_number' => '2025/416',
        'issued_on' => '2025-09-09', 'client' => $client, 'signer' => 'Comercial Teste', 'contact' => 'Contato Teste',
        'principal' => null, 'setup_amount' => null, 'base_quantity' => null,
        'notes' => ['Não presumir aceite.'], 'conditions' => ['Validade' => '10 dias'], 'preserved_amounts' => [], 'preserved_monthly_amounts' => [],
    ];
}

it('V24 exibe estimativa NFS-e com tributos incorporados sem gravar preco contratado', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('Usina fictícia V24'), [
        'kind' => 'modalidades', 'scope' => 'NFS-e · monitoramento', 'principal_label' => 'Mensalidade estimada · tributos incluídos',
        'monthly_summary' => '202.93', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'conditions' => ['Valor líquido' => 'R$ 180,10/mês.', 'Tributos' => '11,25% já incorporados.'],
        'list_note' => 'Líquido R$ 180,10 · tributos já inclusos',
        'scenarios' => [['label' => 'Mensalidade final estimada', 'service' => null, 'monthly' => '202.93', 'quantity' => 236, 'setup' => null, 'total' => null, 'page' => 4]],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['NFSE']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 202,93/mês')->assertSee('Líquido R$ 180,10');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 202,93/mês')->assertSee('11,25% já incorporados.');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});

it('V24 mostra valor de retroativas sem tributos e implantacao condicional separada', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('Cliente fictício V24 retro'), [
        'kind' => 'condicoes', 'scope' => 'Consultas retroativas', 'principal_label' => 'Consultas retroativas · valor sem tributos',
        'summary' => '12695.00', 'setup_amount' => '3000.00', 'setup_mode' => 'conditional', 'setup_note' => 'Se não contratar a licença.',
        'conditions' => ['Tributos' => 'Carga incorporada na emissão; alíquota não informada.'],
        'scenarios' => [
            ['label' => 'Consultas · valor sem tributos', 'service' => '12695.00', 'monthly' => null, 'quantity' => 8525, 'setup' => null, 'total' => '12695.00', 'page' => 3],
            ['label' => 'Subtotal com implantação, se devida', 'service' => '12695.00', 'monthly' => null, 'quantity' => 8525, 'setup' => '3000.00', 'total' => '15695.00', 'page' => 3],
        ],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 12.695,00')->assertSee('valor sem tributos');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 15.695,00')->assertSee('alíquota não informada.');
    expect($proposal->refresh()->one_time_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});

it('V24 distingue mensalidade de implantacao e excedente anual', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('Cliente fictício V24 CT-e'), [
        'kind' => 'modalidades', 'scope' => 'Licenciamento · consulta de exportação via CT-e', 'principal_label' => 'Mensalidade · franquia anual',
        'monthly_summary' => '677.50', 'setup_summary' => '1800.00', 'setup_amount' => '1800.00', 'setup_mode' => 'conditional', 'setup_note' => 'Após teste se efetivar.',
        'conditions' => ['Excedente' => 'R$ 0,35 por nota adicional; cobrança anual.'], 'list_note' => 'Excedente anual R$ 0,35/nota',
        'scenarios' => [['label' => 'Mensalidade e implantação', 'service' => null, 'monthly' => '677.50', 'quantity' => 12000, 'setup' => '1800.00', 'total' => null, 'page' => 4]],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['LICENCIAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 677,50/mês')->assertSee('R$ 1.800,00');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 0,35 por nota adicional; cobrança anual.');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->one_time_final)->toBeNull();
});

it('V24 mostra bônus condicional sem somar ao valor principal e preserva CRM', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('Cliente fictício V24 bônus'), [
        'kind' => 'fixo', 'scope' => 'Consultas retroativas', 'principal_label' => 'Consultas retroativas · bônus condicional',
        'principal' => '15964.00', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'conditions' => ['Condição especial' => 'Acompanhamento gratuito durante o contrato atual.'],
        'list_note' => 'Bônus condicional R$ 0,00',
        'scenarios' => [
            ['label' => 'Consultas retroativas', 'service' => '15964.00', 'quantity' => 11204, 'setup' => null, 'total' => '15964.00', 'page' => 3],
            ['label' => 'Bônus de acompanhamento retroativo', 'service' => '17366.23', 'quantity' => null, 'setup' => null, 'total' => '0.00', 'discount' => '17366.23', 'page' => 3],
        ],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 15.964,00')->assertSee('Bônus condicional');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 17.366,23')->assertSee('Acompanhamento gratuito durante o contrato atual.');
    expect($proposal->refresh()->accepted_at)->toBeNull();
});

it('V24 distingue plano escalonado da Colombo de mensalidade unica', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('Renovação fictícia V24'), [
        'kind' => 'modalidades', 'scope' => 'Licenciamento · renovação', 'principal_label' => 'Plano de pagamento · 36 meses',
        'filename_number' => 'Não informado', 'cover_number' => 'Não informado', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'conditions' => ['Total do plano em 36 meses' => 'R$ 41.636,64'],
        'list_note' => 'R$ 41.636,64 no plano de 36 meses',
        'scenarios' => [
            ['label' => 'Fev/2026 a mar/2027', 'service' => null, 'monthly' => '500.00', 'quantity' => null, 'setup' => null, 'total' => null, 'page' => 4],
            ['label' => 'Abr/2027 a dez/2027', 'service' => null, 'monthly' => '1552.27', 'quantity' => null, 'setup' => null, 'total' => null, 'page' => 4],
        ],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['LICENCIAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 500,00/mês')->assertSee('R$ 1.552,27/mês')->assertSee('Sem numeração na capa');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 41.636,64');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->one_time_final)->toBeNull();
});
it('V24 distingue estimativa por XML de preco fixo', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV24Base('XML fictício V24'), [
        'kind' => 'estimativa', 'scope' => 'Consultas retroativas · XML reconstruído', 'principal_label' => 'Estimativa por XML gerado',
        'summary' => '11250.00', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'conditions' => ['Preço unitário' => 'R$ 0,15 por XML criado.'], 'list_note' => 'R$ 0,15/XML',
        'scenarios' => [['label' => 'Estimativa', 'service' => '11250.00', 'monthly' => null, 'quantity' => 75000, 'setup' => null, 'total' => '11250.00', 'page' => 3]],
    ]);
    $proposal = ecHistoryV24Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 11.250,00')->assertSee('R$ 0,15/XML');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 0,15 por XML criado.');
    expect($proposal->refresh()->one_time_final)->toBeNull();
});
