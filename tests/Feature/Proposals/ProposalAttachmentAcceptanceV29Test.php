<?php

use App\Models\EcProposal;
use App\Services\Proposals\HistoricalProposalReadModel;
use App\Services\Proposals\ProposalAttachmentAcceptanceV29;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function v29Proposal(string $origin = 'imported', array $codes = ['NFSE']): EcProposal
{
    return EcProposal::query()->create(['public_id' => (string) Str::uuid(), 'origin' => $origin, 'status' => $origin === 'imported' ? 'imported' : 'issued', 'client_name' => 'Cliente de teste', 'service_codes' => $codes, 'monthly_final' => '180.00']);
}

function v29Document(EcProposal $p, string $kind, string $service, string $stage = 'closedwon'): array
{
    $raw = '%PDF-1.4 teste '.Str::random(16);
    $path = 'proposals/test/'.Str::uuid().'.pdf';
    $sha = hash('sha256', $raw);
    Storage::disk('local')->put($path, $raw);
    $id = DB::table('ec_proposal_documents')->insertGetId(['proposal_id' => $p->id, 'kind' => $kind, 'service' => $service, 'storage_path' => $path, 'original_name' => 'proposta.pdf', 'sha256' => $sha, 'bytes' => strlen($raw), 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now()]);

    return ['document_id' => $id, 'proposal_id' => $p->id, 'sha256' => $sha, 'deal_id' => '993002', 'deal_name' => 'Negócio teste', 'stage' => $stage, 'file_id' => '994001', 'activity_type' => 'notes', 'activity_id' => '994002', 'file_name' => 'renomeado.pdf', 'storage_path' => $path];
}

function v29Report(array $matches, string $stage = 'closedwon'): array
{
    return ['version' => 29, 'checked_at' => '2026-11-27T00:00:00Z', 'matches' => $matches, 'deals' => [['id' => '993002', 'stage' => $stage]]];
}

it('confirma por hash sem depender de nome ou vinculo CRM e mantém valor e aceite formal', function (): void {
    Storage::fake('local');
    $p = v29Proposal();
    $doc = v29Document($p, 'historical_pdf', 'NFSE');
    $service = app(ProposalAttachmentAcceptanceV29::class);
    $report = v29Report([$doc]);
    expect($service->process($report, false)['new'])->toBe(1)->and(ProposalAttachmentAcceptanceV29::verified((int) $p->id))->toBeFalse();
    expect($service->process($report, true)['new'])->toBe(1)->and($service->process($report, true)['new'])->toBe(0);
    expect(ProposalAttachmentAcceptanceV29::verified((int) $p->id))->toBeTrue()->and($p->refresh()->status)->toBe('imported')->and($p->accepted_at)->toBeNull()->and((string) $p->monthly_final)->toBe('180.00');
    expect(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
    $data = app(HistoricalProposalReadModel::class)->listing(Request::create('/propostas', 'GET', ['hist_status' => 'accepted']));
    expect($data['counts']['accepted'])->toBe(1)->and($data['page']->total())->toBe(1);
});

it('não confirma proposta quando o arquivo local mudou', function (): void {
    Storage::fake('local');
    $p = v29Proposal();
    $doc = v29Document($p, 'historical_pdf', 'NFSE');
    Storage::disk('local')->put($doc['storage_path'], 'alterado');
    expect(fn () => app(ProposalAttachmentAcceptanceV29::class)->process(v29Report([$doc]), true))->toThrow(RuntimeException::class);
    expect(ProposalAttachmentAcceptanceV29::verified((int) $p->id))->toBeFalse();
});

it('não transforma parte de uma proposta com dois serviços em aceite completo', function (): void {
    Storage::fake('local');
    $p = v29Proposal('new', ['DFE', 'NFSE']);
    $dfe = v29Document($p, 'proposal_pdf', 'DFE');
    $nfse = v29Document($p, 'proposal_pdf', 'NFSE');
    $svc = app(ProposalAttachmentAcceptanceV29::class);
    expect($svc->process(v29Report([$dfe]), true)['new'])->toBe(0)->and(ProposalAttachmentAcceptanceV29::verified((int) $p->id))->toBeFalse();
    expect($svc->process(v29Report([$dfe, $nfse]), true)['new'])->toBe(1);
});

it('não aceita etapa inelegível nem proposta recusada', function (): void {
    Storage::fake('local');
    $p = v29Proposal();
    $doc = v29Document($p, 'historical_pdf', 'NFSE', 'closedlost');
    $svc = app(ProposalAttachmentAcceptanceV29::class);
    expect(fn () => $svc->process(v29Report([$doc], 'closedlost'), true))->toThrow(RuntimeException::class);
    $p->forceFill(['status' => 'declined'])->save();
    $doc['stage'] = 'closedwon';
    expect($svc->process(v29Report([$doc]), true)['new'])->toBe(0);
});
