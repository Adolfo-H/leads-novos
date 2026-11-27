<?php

use App\Models\EcProposal;
use App\Models\User;
use App\Services\Proposals\HistoricalProposalReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('separa o aceite CRM do fluxo formal e filtra o histórico pelo nome e serviço', function (): void {
    $actor = User::factory()->create();
    $p = EcProposal::query()->create([
        'public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported',
        'legacy_code' => 'onedrive:'.str_repeat('a', 48), 'client_name' => 'Empresa Teste',
        'service_codes' => ['NFSE'], 'proposal_numbers' => null,
        'issued_on' => null, 'hubspot_company_id' => null, 'hubspot_deal_id' => null, 'notes' => null,
        'created_by' => $actor->id, 'updated_by' => $actor->id,
    ]);
    DB::table('ec_proposal_history_metadata')->insert([
        'proposal_id' => $p->id, 'document_sha256' => str_repeat('a', 64),
        'title' => 'PROPOSTA COMERCIAL EC 2024 305 NFSE', 'document_name' => 'proposta.pdf',
        'historic_year' => 2024, 'historic_number' => '305', 'issued_on' => '2024-08-20',
        'monthly_amount' => '180.00', 'services' => '["NFSE"]',
        'commercial_status' => 'accepted_crm', 'extraction_status' => 'extracted',
        'crm_match' => 'exact_code', 'evidence' => '{}', 'updated_at' => now(),
    ]);
    // EC_ATTACHMENT_V29: previous CRM classification alone is not attachment proof.
    $model = app(HistoricalProposalReadModel::class);
    expect($model->listing(Request::create('/propostas', 'GET', ['hist_status' => 'accepted']))['counts']['accepted'])->toBe(0);
    DB::table('ec_proposal_events')->insert(['proposal_id' => $p->id, 'event' => 'crm_attachment_acceptance_v29', 'metadata' => '{}', 'created_at' => now()]);

    $result = $model->listing(Request::create('/propostas', 'GET', [
        'hist_status' => 'accepted', 'hist_service' => 'NFSE', 'hist_year' => '2024', 'hist_q' => '305',
    ]));
    expect($result['counts']['total'])->toBe(1)
        ->and($result['counts']['accepted'])->toBe(1)
        ->and($result['counts']['sent'])->toBe(0)
        ->and($result['page']->total())->toBe(1)
        ->and($p->refresh()->status)->toBe('imported')
        ->and($p->accepted_at)->toBeNull()
        ->and($p->proposal_numbers)->toBeNull();
});

it('preserva a mensalidade não identificada e diferencia zero', function (): void {
    expect(HistoricalProposalReadModel::money(null))->toBe('Não identificado')
        ->and(HistoricalProposalReadModel::money('0.00'))->toBe('R$ 0,00')
        ->and(HistoricalProposalReadModel::date('2026-11-23'))->toBe('23/11/2026');
});
