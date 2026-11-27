<?php

use App\Models\EcProposal;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Proposals\ProposalCrmCoverageV28;
use App\Services\Proposals\ProposalCrmMatchV28;
use Database\Seeders\EcProposalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function v28Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function v28Deal(HubSpotCompany $company, string $name, string $stage = 'closedwon', string $id = '994002'): HubSpotDeal
{
    $deal = HubSpotDeal::query()->create(['hubspot_id' => $id, 'name' => $name, 'stage_id' => $stage, 'stage_label' => $stage === 'closedwon' ? 'Negócio fechado' : 'Proposta apresentada', 'pipeline_id' => 'default', 'hubspot_created_at' => '2026-03-01', 'closed_at' => '2026-05-01']);
    DB::table('hubspot_company_deal')->insert(['hubspot_company_id' => $company->id, 'hubspot_deal_id' => $deal->id, 'is_primary' => true]);

    return $deal;
}

function v28Historical(string $number, ?int $companyId = null): EcProposal
{
    $p = EcProposal::query()->create(['public_id' => (string) Str::uuid(), 'origin' => 'imported', 'status' => 'imported', 'client_name' => 'Empresa Exemplo LTDA', 'service_codes' => ['NFSE'], 'monthly_final' => '2000.00', 'hubspot_company_id' => $companyId]);
    $contents = 'arquivo de teste '.$p->id;
    $sha = hash('sha256', $contents);
    $path = 'proposals/test/'.$p->id.'.pdf';
    Storage::disk('local')->put($path, $contents);
    DB::table('ec_proposal_documents')->insert(['proposal_id' => $p->id, 'kind' => 'historical_pdf', 'service' => 'NFSE', 'storage_path' => $path, 'original_name' => 'original.pdf', 'sha256' => $sha, 'bytes' => strlen($contents), 'mime' => 'application/pdf', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ec_proposal_history_metadata')->insert(['proposal_id' => $p->id, 'document_sha256' => $sha, 'title' => 'Proposta EC 2026/'.$number, 'document_name' => 'original.pdf', 'historic_year' => 2026, 'historic_number' => $number, 'issued_on' => '2026-04-04', 'services' => '["NFSE"]', 'monthly_amount' => '2000.00', 'commercial_status' => 'sent_history', 'extraction_status' => 'extracted', 'crm_match' => 'company_only', 'crm_company_id' => $companyId, 'evidence' => '{"finance_v2":{"preservar":true}}', 'updated_at' => now()]);

    return $p;
}

// EC_ATTACHMENT_V29
it('não permite que referência exata sem anexo confirme aceite', function (): void {
    Storage::fake('local');
    $company = HubSpotCompany::query()->create(['hubspot_id' => '994001', 'name' => 'Empresa Exemplo LTDA']);
    v28Deal($company, 'Empresa Exemplo - EC 2026/512 NFS-e');
    $p = v28Historical('512', (int) $company->id);
    $svc = app(ProposalCrmCoverageV28::class);
    expect($svc->reconcile(true)['count'])->toBe(0)->and($p->refresh()->hubspot_deal_id)->toBeNull();
    $this->assertDatabaseHas('ec_proposal_history_metadata', ['proposal_id' => $p->id, 'commercial_status' => 'sent_history']);
    expect(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
});

it('não escolhe negócio fechado se existem duas propostas ou negócios concorrentes', function (): void {
    Storage::fake('local');
    $company = HubSpotCompany::query()->create(['hubspot_id' => '994001', 'name' => 'Empresa Exemplo LTDA']);
    v28Deal($company, 'Empresa Exemplo - NFS-e');
    v28Historical('512', (int) $company->id);
    v28Historical('513', (int) $company->id);
    expect(app(ProposalCrmCoverageV28::class)->reconcile(true)['count'])->toBe(0);
    v28Deal($company, 'Empresa Exemplo - NFS-e', 'decisionmakerboughtin', '994003');
    expect(app(ProposalCrmCoverageV28::class)->reconcile(true)['count'])->toBe(0);
});

it('não aceita documento de outro serviço e rejeita negócios com encerramento anterior', function (): void {
    expect(ProposalCrmMatchV28::criterion(['NFSE'], '2026/512', '2026-04-04', ['name' => 'EC 2026/512 DF-e']))->toBeNull()
        ->and(ProposalCrmMatchV28::criterion(['NFSE'], '2026/512', '2026-04-04', ['name' => 'Empresa NFS-e', 'hubspot_created_at' => '2026-01-01', 'closed_at' => '2026-03-01']))->toBeNull();
});

it('mantém negócios aceitos sem PDF em indicador separado', function (): void {
    Storage::fake('local');
    $company = HubSpotCompany::query()->create(['hubspot_id' => '994001', 'name' => 'Empresa Exemplo LTDA']);
    v28Deal($company, 'Empresa Exemplo - NFSE');
    $actor = v28Manager();
    $this->actingAs($actor)->get(route('proposals.crm-coverage-v28'))->assertOk()->assertSee('Aceites por anexos do HubSpot')->assertSee('Nenhuma varredura concluída');
    expect(EcProposal::query()->count())->toBe(0);
});

it('mostra botão de modelo em branco e exige autorização de acesso', function (): void {
    $this->seed(EcProposalCatalogSeeder::class);
    $this->get(route('proposals.model-pdf-v28', ['service' => 'NFSE']))->assertRedirect(route('login'));
    $actor = v28Manager();
    $this->actingAs($actor)->get(route('proposals.templates'))->assertOk()->assertSee('Ver PDF do modelo');
    $this->actingAs($actor)->get(route('proposals.model-pdf-v28', ['service' => 'INVALIDO']))->assertNotFound();
});
