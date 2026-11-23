<?php

// EC_PROPOSALS_OD_IMPORT_V1 - testes de importacao historica segura.

use App\Models\EcProposal;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function proODManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{manifest:string,sha:string} */
function proODStage(string $filename, string $contents, string $client, string $dealId = '', string $companyId = ''): array
{
    $sha = hash('sha256', $contents);
    $root = 'proposals/import-batches/registroteste';
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $filepath = 'files/'.$sha.'.'.$ext;
    Storage::disk('local')->put($root.'/'.$filepath, $contents);
    $keys = [
        'arquivo_relativo', 'arquivo_lote', 'sha256', 'bytes', 'cliente_revisado', 'revisado', 'importar',
        'servico_sugerido', 'id_negocio_hubspot', 'id_empresa_hubspot', 'data_emissao',
        'numero_confirmado', 'numero_verificado',
    ];
    $values = [$filename, $filepath, $sha, strlen($contents), $client, 'SIM', 'SIM',
        'DFE', $dealId, $companyId, '2026-11-20', '', 'NAO'];
    $f = fopen('php://temp', 'w+');
    if ($f === false) {
        throw new RuntimeException('Sem stream temporário');
    }
    fputcsv($f, $keys, ';', '"', '');
    fputcsv($f, $values, ';', '"', '');
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    if (! is_string($csv)) {
        throw new RuntimeException('CSV não gerado');
    }
    Storage::disk('local')->put($root.'/revisado.csv', $csv);

    return ['manifest' => $root.'/revisado.csv', 'sha' => $sha];
}

it('faz a prévia sem alterar banco ou importar arquivos', function (): void {
    Storage::fake('local');
    $staged = proODStage('Cliente A - DFE.pdf', '%PDF-1.4 teste 1', 'Cliente A');
    $this->artisan('proposals:onedrive-import', ['--manifest' => $staged['manifest']])->assertExitCode(0);
    expect(EcProposal::query()->count())->toBe(0)
        ->and(DB::table('ec_proposal_documents')->count())->toBe(0)
        ->and(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
});

it('só importa com confirmação explícita e gestor autorizado', function (): void {
    Storage::fake('local');
    $staged = proODStage('Cliente B - DFE.pdf', '%PDF-1.4 teste 2', 'Cliente B');
    $manager = proODManager();
    $options = [
        '--manifest' => $staged['manifest'], '--apply' => true,
        '--actor' => $manager->email,
    ];
    $this->artisan('proposals:onedrive-import', $options)->assertExitCode(1);
    expect(EcProposal::query()->count())->toBe(0);
    $options['--confirm'] = 'IMPORTAR_HISTORICO';
    $this->artisan('proposals:onedrive-import', $options)->assertExitCode(0);
    $p = EcProposal::query()->firstOrFail();
    expect($p->status)->toBe('imported')
        ->and($p->proposal_numbers)->toBeNull()
        ->and($p->client_name)->toBe('Cliente B')
        ->and(DB::table('ec_proposal_number_ledger')->count())->toBe(0)
        ->and(DB::table('ec_proposal_documents')->where('sha256', $staged['sha'])->count())->toBe(1)
        ->and(DB::table('ec_proposal_events')->where('event', 'historical_onedrive_import')->count())->toBe(1);
    $this->artisan('proposals:onedrive-import', $options)->assertExitCode(0);
    expect(EcProposal::query()->count())->toBe(1);
});

it('mantém o vínculo específico de negócio e não infere aceite pela etapa do funil', function (): void {
    Storage::fake('local');
    $manager = proODManager();
    $company = HubSpotCompany::query()->create(['hubspot_id' => '990400', 'name' => 'Empresa Exemplo']);
    $deal1 = HubSpotDeal::query()->create([
        'hubspot_id' => '990401', 'name' => 'Empresa Exemplo - DFE', 'stage_label' => 'Negócio fechado',
    ]);
    $deal2 = HubSpotDeal::query()->create([
        'hubspot_id' => '990402', 'name' => 'Empresa Exemplo - NFSE', 'stage_label' => 'Proposta apresentada',
    ]);
    DB::table('hubspot_company_deal')->insert([
        ['hubspot_company_id' => $company->id, 'hubspot_deal_id' => $deal1->id, 'is_primary' => true],
        ['hubspot_company_id' => $company->id, 'hubspot_deal_id' => $deal2->id, 'is_primary' => true],
    ]);
    $staged = proODStage('Empresa Exemplo - DFE.pdf', '%PDF-1.4 teste 3', 'Empresa Exemplo', '990401', '990400');
    $this->artisan('proposals:onedrive-import', [
        '--manifest' => $staged['manifest'], '--apply' => true,
        '--confirm' => 'IMPORTAR_HISTORICO', '--actor' => $manager->email,
    ])->assertExitCode(0);
    $proposal = EcProposal::query()->firstOrFail();
    expect((int) $proposal->hubspot_deal_id)->toBe((int) $deal1->id)
        ->and((int) $proposal->hubspot_company_id)->toBe((int) $company->id)
        ->and($proposal->status)->toBe('imported')
        ->and($proposal->accepted_at)->toBeNull();
});

it('rejeita vínculo falso entre negócio e empresa sem importar nada', function (): void {
    Storage::fake('local');
    $manager = proODManager();
    HubSpotCompany::query()->create(['hubspot_id' => '990500', 'name' => 'Empresa sem negócio']);
    HubSpotDeal::query()->create(['hubspot_id' => '990501', 'name' => 'Negócio sem empresa']);
    $staged = proODStage('Cliente C - DFE.pdf', '%PDF-1.4 teste 4', 'Cliente C', '990501', '990500');
    $this->artisan('proposals:onedrive-import', [
        '--manifest' => $staged['manifest'], '--apply' => true,
        '--confirm' => 'IMPORTAR_HISTORICO', '--actor' => $manager->email,
    ])->assertExitCode(1);
    expect(EcProposal::query()->count())->toBe(0);
});

it('mantém duas propostas separadas quando o mesmo PDF foi aprovado para dois negócios', function (): void {
    Storage::fake('local');
    $manager = proODManager();
    $deal1 = HubSpotDeal::query()->create(['hubspot_id' => '991001', 'name' => 'Serviço contrato A']);
    $deal2 = HubSpotDeal::query()->create(['hubspot_id' => '991002', 'name' => 'Serviço contrato B']);
    $staged = proODStage('Empresa Exemplo - proposta A.pdf', '%PDF-1.4 modelo repetido', 'Empresa Exemplo', '991001');
    $csv = Storage::disk('local')->get($staged['manifest']);
    $rows = array_values(array_filter(explode("\n", trim($csv))));
    $second = str_getcsv($rows[1], ';', '"', '');
    $second[0] = 'Empresa Exemplo - proposta B.pdf';
    $second[8] = '991002';
    $out = fopen('php://temp', 'w+');
    if ($out === false) {
        throw new RuntimeException('CSV temporário indisponível');
    }
    fwrite($out, $csv);
    fputcsv($out, $second, ';', '"', '');
    rewind($out);
    $both = stream_get_contents($out);
    fclose($out);
    if (! is_string($both)) {
        throw new RuntimeException('CSV não gerado');
    }
    Storage::disk('local')->put($staged['manifest'], $both);
    $this->artisan('proposals:onedrive-import', [
        '--manifest' => $staged['manifest'], '--apply' => true,
        '--confirm' => 'IMPORTAR_HISTORICO', '--actor' => $manager->email,
    ])->assertExitCode(0);
    expect(EcProposal::query()->count())->toBe(2)
        ->and(EcProposal::query()->where('hubspot_deal_id', $deal1->id)->count())->toBe(1)
        ->and(EcProposal::query()->where('hubspot_deal_id', $deal2->id)->count())->toBe(1)
        ->and(DB::table('ec_proposal_number_ledger')->count())->toBe(0);
});

it('ignora linhas vazias do CSV sem adicionar candidatos e preserva validacoes', function (): void {
    Storage::fake('local');
    $staged = proODStage('Cliente D - DFE.pdf', '%PDF-1.4 teste branco', 'Cliente D');
    $csv = Storage::disk('local')->get($staged['manifest']);
    Storage::disk('local')->put($staged['manifest'], $csv."\n");

    $this->artisan('proposals:onedrive-import', [
        '--manifest' => $staged['manifest'],
    ])->assertExitCode(0);

    expect(EcProposal::query()->count())->toBe(0)
        ->and(DB::table('ec_proposal_documents')->count())->toBe(0);
});

it('recusa CSV sem cabecalho e nao modifica propostas', function (): void {
    Storage::fake('local');
    $manifest = 'proposals/import-batches/registroteste/revisado.csv';
    Storage::disk('local')->put($manifest, '');

    $this->artisan('proposals:onedrive-import', ['--manifest' => $manifest])
        ->assertExitCode(1);

    expect(EcProposal::query()->count())->toBe(0);
});
