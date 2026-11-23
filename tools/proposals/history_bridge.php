<?php

// Explicit offline administrative command. Run only inside the existing Sail app.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$relative = $argv[2] ?? '';
if (! preg_match('~^proposals/history-enrichment/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative)) {
    fwrite(STDERR, "Caminho privado inválido.\n");
    exit(1);
}
$disk = Storage::disk('local');
$path = $disk->path($relative);
$fields = ['proposal_id', 'document_sha256', 'title', 'document_name', 'historic_year', 'historic_number', 'issued_on', 'responsible_name', 'responsible_email', 'recipient_email', 'monthly_amount', 'one_time_amount', 'services', 'calculation', 'commercial_status', 'extraction_status', 'crm_company_id', 'crm_deal_id', 'crm_stage', 'crm_match', 'evidence'];

try {
    if ($mode === 'export') {
        $proposals = DB::table('ec_proposals')->where('origin', 'imported')->orderBy('id')->get(['id', 'legacy_code', 'client_name', 'service_codes', 'issued_on', 'hubspot_company_id', 'hubspot_deal_id', 'status']);
        $docs = DB::table('ec_proposal_documents as d')->join('ec_proposals as p', 'p.id', '=', 'd.proposal_id')->where('p.origin', 'imported')->whereIn('d.kind', ['historical_pdf', 'historical_original'])->select(['d.id', 'd.proposal_id', 'd.original_name', 'd.storage_path', 'd.sha256'])->get();
        $dealFields = ['id', 'hubspot_id', 'name', 'stage_label'];
        if (Schema::hasColumn('hubspot_deals', 'archived')) {
            $dealFields[] = 'archived';
        }
        $companyFields = ['id', 'hubspot_id', 'name'];
        foreach (['cnpj', 'tax_id', 'document_number', 'cnpj_number', 'document'] as $field) {
            if (Schema::hasColumn('hubspot_companies', $field)) {
                $companyFields[] = $field;
            }
        }
        $data = ['proposals' => $proposals, 'documents' => $docs,
            'companies' => DB::table('hubspot_companies')->get($companyFields),
            'deals' => DB::table('hubspot_deals')->get($dealFields),
            'links' => DB::table('hubspot_company_deal')->get(['hubspot_company_id', 'hubspot_deal_id'])];
        if (! $disk->put($relative, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            throw new RuntimeException('Falha ao gravar exportação');
        }
        chmod($path, 0600);
        echo 'Exportados '.$proposals->count()." registros históricos (somente leitura).\n";
        exit(0);
    }
    if (! in_array($mode, ['check', 'apply'], true)) {
        throw new RuntimeException('Modo inválido');
    }
    if ($mode === 'apply' && ($argv[3] ?? '') !== 'ENRIQUECER_HISTORICO') {
        throw new RuntimeException('Confirmação explícita necessária');
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($data['version'] ?? null) !== 1 || ! is_array($data['rows'] ?? null) || count($data['rows']) === 0) {
        throw new RuntimeException('Revisão sem registros');
    }
    $proposals = DB::table('ec_proposals')->where('origin', 'imported')->get(['id', 'legacy_code', 'client_name', 'hubspot_company_id', 'hubspot_deal_id'])->keyBy('id');
    $documents = DB::table('ec_proposal_documents')->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path', 'original_name'])->groupBy('proposal_id');
    $stages = DB::table('hubspot_deals')->get(['id', 'stage_label'])->keyBy('id');
    $validCompanies = DB::table('hubspot_companies')->pluck('id')->map(fn ($x) => (int) $x)->flip();
    $links = DB::table('hubspot_company_deal')->get(['hubspot_company_id', 'hubspot_deal_id']);
    $pairs = [];
    foreach ($links as $link) {
        $pairs[$link->hubspot_company_id.':'.$link->hubspot_deal_id] = true;
    }
    $acceptedStages = ['ACEITE DA PROPOSTA', 'CONTRATO ASSINADO', 'INICIO DO TESTE', 'NEGOCIO FECHADO'];
    $seen = [];
    $seenDeals = [];
    $payload = [];
    foreach ($data['rows'] as $row) {
        if (! is_array($row) || array_diff($fields, array_keys($row))) {
            throw new RuntimeException('Linha incompleta');
        }
        $id = (int) $row['proposal_id'];
        if (isset($seen[$id])) {
            throw new RuntimeException('Registro repetido '.$id);
        }
        $seen[$id] = true;
        $p = $proposals->get($id);
        $dd = $documents->get($id);
        if (! $p || $p->legacy_code !== ($row['legacy_code'] ?? null) || $p->client_name !== ($row['client_name'] ?? null) || ! $dd || $dd->count() !== 1) {
            throw new RuntimeException('Registro histórico mudou: '.$id);
        }
        $doc = $dd->first();
        if ($doc->sha256 !== $row['document_sha256'] || $doc->original_name !== $row['document_name'] || ! preg_match('/^[a-f0-9]{64}$/D', $row['document_sha256']) || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
            throw new RuntimeException('Documento mudou: '.$id);
        }
        if (! is_array($row['services']) || array_diff($row['services'], ['DFE', 'NFSE', 'LICENCIAMENTO', 'RETROATIVAS', 'ACOMPANHAMENTO'])) {
            throw new RuntimeException('Serviço inválido: '.$id);
        }
        foreach (['monthly_amount', 'one_time_amount'] as $amount) {
            if ($row[$amount] !== null && ! preg_match('/^\d{1,9}\.\d{2}$/D', (string) $row[$amount])) {
                throw new RuntimeException('Valor inválido: '.$id);
            }
        }
        if (! in_array($row['commercial_status'], ['accepted_crm', 'sent_history'], true)) {
            throw new RuntimeException('Situação inválida');
        }
        $cid = $row['crm_company_id'];
        $did = $row['crm_deal_id'];
        if ($cid !== null && ! $validCompanies->has((int) $cid)) {
            throw new RuntimeException('Empresa CRM mudou: '.$id);
        }
        if ($did !== null) {
            if ($cid === null || ! isset($pairs[$cid.':'.$did]) || isset($seenDeals[$did])) {
                throw new RuntimeException('Vínculo CRM inválido/duplicado: '.$id);
            }
            if (($p->hubspot_company_id !== null && (int) $p->hubspot_company_id !== (int) $cid) || ($p->hubspot_deal_id !== null && (int) $p->hubspot_deal_id !== (int) $did)) {
                throw new RuntimeException('Vínculo anterior divergente: '.$id);
            }
            $seenDeals[$did] = true;
            $stage = $stages->get((int) $did);
            if (! $stage || $stage->stage_label !== $row['crm_stage']) {
                throw new RuntimeException('Etapa CRM mudou; gere nova revisão: '.$id);
            }
        }
        if ($row['commercial_status'] === 'accepted_crm' && ($did === null || ! in_array(mb_strtoupper(Str::ascii((string) $row['crm_stage'])), $acceptedStages, true) || ! in_array($row['crm_match'], ['exact_code', 'unique_company_service'], true))) {
            throw new RuntimeException('Aceite CRM sem evidência: '.$id);
        }
        $v = array_intersect_key($row, array_flip($fields));
        $v['services'] = json_encode($v['services'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $v['evidence'] = json_encode($v['evidence'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $v['updated_at'] = now('UTC');
        $payload[] = $v;
    }
    echo 'Validados '.count($payload).' de '.$proposals->count()." registros históricos.\n";
    if ($mode === 'check') {
        echo "SIMULAÇÃO: nenhuma gravação no banco.\n";
        exit(0);
    }
    if (! Schema::hasTable('ec_proposal_history_metadata')) {
        throw new RuntimeException('Migration do histórico ainda não executada');
    }
    // Preserve the previous enrichment snapshot in the private batch directory.
    $previous = DB::table('ec_proposal_history_metadata')->orderBy('proposal_id')->get();
    $snapshot = dirname($relative).'/before-metadata.json';
    if (! $disk->put($snapshot, json_encode($previous, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
        throw new RuntimeException('Falha ao salvar snapshot privado');
    }
    chmod($disk->path($snapshot), 0600);
    DB::transaction(function () use ($payload, $fields): void {
        foreach (array_chunk($payload, 100) as $batch) {
            DB::table('ec_proposal_history_metadata')->upsert($batch, ['proposal_id'], array_merge(array_values(array_diff($fields, ['proposal_id'])), ['updated_at']));
        }
    });
    echo 'ENRIQUECIMENTO CONCLUÍDO: '.count($payload)." registros atualizados na tabela exclusiva do histórico.\n";
    echo "Propostas, arquivos originais, numeração, aceite formal e HubSpot não foram alterados.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
