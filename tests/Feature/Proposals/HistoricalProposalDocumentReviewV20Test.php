<?php

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ecHistoryV20Fixture(User $manager, array $review, array $services): EcProposal
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
        'evidence' => json_encode(['review_v20' => $review, 'finance_v2' => ['monthly_state' => 'conflict']], JSON_THROW_ON_ERROR), 'updated_at' => now(),
    ]);

    return $proposal;
}

function ecHistoryV20Base(string $client): array
{
    return [
        'version' => 20, 'source' => 'conferencia_documental_sha256', 'sha256' => hash('sha256', $client),
        'filename' => 'Proposta '.$client.'.pdf', 'filename_number' => '2025/420', 'cover_number' => '2025/416',
        'issued_on' => '2025-09-09', 'client' => $client, 'signer' => 'Comercial Teste', 'contact' => 'Contato Teste',
        'principal' => null, 'setup_amount' => null, 'base_quantity' => null,
        'notes' => ['Não presumir aceite.'], 'conditions' => ['Validade' => '10 dias'], 'preserved_amounts' => [], 'preserved_monthly_amounts' => [],
    ];
}

it('exibe mensalidades alternativas V20 sem somar a implantacao nem presumir aceite', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = ecHistoryV20Base('STC fictícia V20') + [
        'kind' => 'modalidades', 'scope' => 'Licenciamento · modalidades de ambiente', 'principal_label' => 'Modalidades de licenciamento',
        'setup_mode' => 'by_modality', 'setup_note' => 'Conforme modalidade.',
        'scenarios' => [
            ['label' => 'Ambiente compartilhado', 'service' => null, 'monthly' => '320.16', 'quantity' => 166, 'setup' => '2000.00', 'total' => null, 'page' => 4, 'locator' => 'PDF, p. 4'],
            ['label' => 'Ambiente exclusivo por empresa', 'service' => null, 'monthly' => '300.00', 'quantity' => 41, 'setup' => '2000.00', 'total' => null, 'page' => 4, 'locator' => 'PDF, p. 4'],
            ['label' => 'Três ambientes exclusivos', 'service' => null, 'monthly' => null, 'quantity' => null, 'setup' => '3000.00', 'total' => '3000.00', 'page' => 5, 'locator' => 'PDF, p. 5'],
        ],
    ];
    $proposal = ecHistoryV20Fixture($manager, $review, ['LICENCIAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('Modalidades de licenciamento')->assertSee('R$ 320,16/mês')->assertSee('R$ 300,00/mês');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 320,16/mês')->assertSee('R$ 300,00/mês')->assertSee('R$ 3.000,00')->assertSee('Implantação por modalidade');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->one_time_final)->toBeNull()->and($proposal->status)->toBe('imported');
});

it('exibe o pacote estimado V20 e a diferenca declarada de um centavo', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('Semegrão fictícia V20'), [
        'kind' => 'componentes', 'scope' => 'Consultas retroativas + acompanhamento retroativo', 'principal_label' => 'Pacote com desconto · estimado',
        'summary' => '63004.00', 'setup_amount' => '5000.00', 'setup_mode' => 'conditional', 'setup_note' => 'Somente nas consultas sem licença.',
        'scenarios' => [
            ['label' => 'Consultas retroativas', 'service' => '44502.44', 'quantity' => 51322, 'setup' => null, 'total' => '44502.44', 'page' => 4],
            ['label' => 'Acompanhamento retroativo estimado', 'service' => '20726.69', 'quantity' => 15396, 'setup' => null, 'total' => '20726.69', 'page' => 3],
            ['label' => 'Pacote com desconto', 'service' => '65229.13', 'quantity' => null, 'setup' => null, 'discount' => '2225.12', 'total' => '63004.00', 'computed_total' => '63004.01', 'page' => 4, 'note' => 'Diferença documentada.'],
        ],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 63.004,00')->assertSee('R$ 44.502,44')->assertSee('R$ 20.726,69');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 2.225,12')->assertSee('R$ 63.004,01')->assertSee('R$ 63.004,00')->assertSee('conferir diferença');
    expect($proposal->refresh()->one_time_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});

it('prioriza a condicao especial mensal V20 e distingue a divergencia da implantacao', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('Cliente fictício V20 licença'), [
        'kind' => 'modalidades', 'scope' => 'Licenciamento', 'principal_label' => 'Mensalidade oferecida · condição especial',
        'monthly_summary' => '1409.12', 'setup_summary' => '4800.00', 'setup_mode' => 'by_modality', 'setup_note' => 'Valor declarado no documento.',
        'base_quantity' => 1377, 'base_label' => 'Documentos / mês', 'list_note' => 'Implantação oferecida: R$ 4.800,00 · percentual divergente',
        'preserved_monthly_amounts' => ['1565.69'], 'notes' => ['Percentual de 60% diverge do valor final declarado.'],
        'scenarios' => [
            ['label' => 'Valor normal (referência)', 'service' => null, 'monthly' => '1565.69', 'quantity' => 1377, 'setup' => '8000.00', 'total' => null, 'page' => 3],
            ['label' => 'Condição especial JBS', 'service' => null, 'monthly' => '1409.12', 'quantity' => 1377, 'setup' => '4800.00', 'total' => null, 'page' => 4],
        ],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['LICENCIAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 1.409,12/mês')->assertSee('Tabela-base: R$ 1.565,69/mês')->assertSee('percentual divergente');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('Implantação oferecida')->assertSee('R$ 4.800,00')->assertSee('R$ 8.000,00')->assertSee('Percentual de 60% diverge')->assertSee('R$ 1.409,12/mês');
    expect($proposal->refresh()->monthly_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});

it('exibe o bonus gratuito V20 sem transformar a referencia anterior em valor devido', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('Cliente fictício V20 bônus'), [
        'kind' => 'fixo', 'scope' => 'Consultas retroativas + acompanhamento como bônus condicional',
        'principal' => '42000.40', 'principal_label' => 'Valor oferecido · acompanhamento condicional gratuito',
        'setup_mode' => 'not_informed', 'setup_note' => 'Não informada no documento.', 'base_quantity' => 38734,
        'list_note' => 'Bônus condicional: acompanhamento R$ 0,00 (referência R$ 17.366,23)',
        'scenarios' => [
            ['label' => 'Consultas retroativas', 'service' => '42000.40', 'quantity' => 38734, 'setup' => null, 'total' => '42000.40', 'page' => null, 'locator' => 'Word, item 1.5.1'],
            ['label' => 'Bônus de acompanhamento retroativo', 'service' => '17366.23', 'quantity' => null, 'setup' => null, 'total' => '0.00', 'discount' => '17366.23', 'page' => null, 'locator' => 'Word, item 1.6'],
        ],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 42.000,40')->assertSee('Bônus condicional: acompanhamento R$ 0,00');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('Word, item 1.6')->assertSee('R$ 17.366,23')->assertSee('R$ 0,00')->assertSee('Não informada no documento.');
    expect($proposal->refresh()->accepted_at)->toBeNull();
});

it('exibe mensalidade declarada V20 sem assinatura presumida', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('Futuro fictícia V20'), [
        'kind' => 'mensalidade', 'scope' => 'Acompanhamento especializado', 'principal_label' => 'Mensalidade declarada',
        'monthly_summary' => '1411.83', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'signer' => 'Não identificado', 'base_quantity' => 813, 'base_label' => 'Documentos / mês',
        'notes' => ['Campo de assinatura sem nome.'],
        'scenarios' => [['label' => 'Mensalidade do acompanhamento', 'service' => null, 'monthly' => '1411.83', 'quantity' => 813, 'setup' => null, 'total' => null, 'page' => 3]],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['ACOMPANHAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 1.411,83/mês')->assertSee('Mensalidade declarada');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('Campo de assinatura sem nome.')->assertSee('R$ 1.411,83/mês');
    expect($proposal->refresh()->accepted_at)->toBeNull();
});

it('mantem valor normal e desconto condicional mensal V20 como alternativas', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('Gamelão fictícia V20'), [
        'kind' => 'modalidades', 'scope' => 'Acompanhamento especializado', 'principal_label' => 'Mensalidade normal · desconto condicionado',
        'monthly_summary' => '500.00', 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
        'list_note' => 'R$ 250,00/mês somente se cumprida a condição Cavalca',
        'scenarios' => [
            ['label' => 'Mensalidade normal', 'service' => null, 'monthly' => '500.00', 'quantity' => 133, 'setup' => null, 'total' => null, 'page' => 4],
            ['label' => 'Mensalidade com desconto condicional', 'service' => null, 'monthly' => '250.00', 'quantity' => 133, 'setup' => null, 'total' => null, 'page' => 4],
        ],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['ACOMPANHAMENTO']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 500,00/mês')->assertSee('R$ 250,00/mês somente se cumprida a condição Cavalca');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 250,00/mês')->assertSee('Mensalidade com desconto condicional');
    expect($proposal->refresh()->monthly_final)->toBeNull();
});

it('exibe valor pontual V20 e mantém duas versões de acompanhamento distintas', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    foreach (['31472.55', '36923.80'] as $amount) {
        $review = array_replace(ecHistoryV20Base('Cliente fictício V20 '.$amount), [
            'kind' => 'fixo', 'scope' => 'Acompanhamento retroativo', 'principal_label' => 'Acompanhamento retroativo',
            'principal' => $amount, 'setup_mode' => 'not_informed', 'setup_note' => 'Não informada.',
            'signer' => 'Não identificado', 'notes' => ['Prazo a conferir.'],
            'scenarios' => [['label' => 'Análise e cobrança', 'service' => $amount, 'monthly' => null, 'quantity' => 27470, 'setup' => null, 'total' => $amount, 'page' => null, 'locator' => 'Word, seção 1.5']],
        ]);
        $proposal = ecHistoryV20Fixture($manager, $review, ['ACOMPANHAMENTO']);
        $this->actingAs($manager)->get(route('proposals.show', $proposal))->assertOk()->assertSee('Prazo a conferir.')->assertSee('Word, seção 1.5');
    }
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 31.472,55')->assertSee('R$ 36.923,80');
});

it('exibe o valor normal e o desconto condicionado V20 sem presumir contratação', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $review = array_replace(ecHistoryV20Base('BM fictícia V20'), [
        'kind' => 'condicoes', 'scope' => 'Consultas retroativas', 'principal_label' => 'Valor pontual · desconto condicionado',
        'setup_amount' => '3000.00', 'setup_mode' => 'conditional', 'setup_note' => 'Somente sem licenciamento.',
        'conditions' => ['Condição especial' => 'Fechamento na primeira semana de teste.'],
        'scenarios' => [
            ['label' => 'Valor normal das consultas', 'service' => '3612.50', 'quantity' => 2250, 'setup' => null, 'total' => '3612.50', 'page' => 3],
            ['label' => 'Consultas com desconto condicionado de 20%', 'service' => '3612.50', 'quantity' => 2250, 'setup' => null, 'total' => '2890.00', 'discount' => '722.50', 'page' => 3],
            ['label' => 'Valor normal + implantação se devida', 'service' => '3612.50', 'quantity' => 2250, 'setup' => '3000.00', 'total' => '6612.50', 'page' => 3],
        ],
    ]);
    $proposal = ecHistoryV20Fixture($manager, $review, ['RETROATIVAS']);
    $this->actingAs($manager)->get(route('proposals.index'))->assertOk()->assertSee('R$ 3.612,50')->assertSee('R$ 2.890,00')->assertSee('Implantação condicional');
    $this->get(route('proposals.show', $proposal))->assertOk()->assertSee('R$ 722,50')->assertSee('R$ 6.612,50')->assertSee('Fechamento na primeira semana de teste.');
    expect($proposal->refresh()->one_time_final)->toBeNull()->and($proposal->monthly_final)->toBeNull()->and($proposal->accepted_at)->toBeNull();
});
