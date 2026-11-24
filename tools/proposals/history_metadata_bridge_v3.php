<?php

// Local CLI only. Re-enrich financial metadata without touching original proposal records.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function historyMetadataV3Fingerprint(object $row): string
{
    return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

$mode = $argv[1] ?? '';
$relative = $argv[2] ?? '';
if (! preg_match('~^proposals/history-metadata-v3/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative)) {
    fwrite(STDERR, "Caminho privado inválido.\n");
    exit(1);
}
$disk = Storage::disk('local');
$path = $disk->path($relative);
$table = 'ec_proposal_history_metadata';
try {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException('Instale e conclua o enriquecimento V1 antes desta atualização.');
    }
    if ($mode === 'export') {
        $ids = DB::table('ec_proposals')->where('origin', 'imported')->pluck('id');
        $metadata = DB::table($table)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($metadata as $row) {
            $fingerprint = historyMetadataV3Fingerprint($row);
            $row->fingerprint = $fingerprint;
        }
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)
            ->whereIn('kind', ['historical_pdf', 'historical_original'])
            ->get(['id', 'proposal_id', 'storage_path', 'original_name', 'sha256']);
        if (! $disk->put($relative, json_encode(['total' => $ids->count(), 'metadata' => $metadata, 'documents' => $docs, 'missing_metadata_ids' => $ids->diff($metadata->pluck('proposal_id'))->values()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            throw new RuntimeException('Falha ao gravar exportação.');
        }
        chmod($path, 0600);
        echo "Exportação somente leitura: {$metadata->count()} de {$ids->count()} registros históricos.\n";
        exit(0);
    }
    if (! in_array($mode, ['check', 'apply', 'restore'], true)) {
        throw new RuntimeException('Modo inválido.');
    }
    if ($mode === 'apply' && ($argv[3] ?? '') !== 'ATUALIZAR_HISTORICO_V3') {
        throw new RuntimeException('Confirmação explícita necessária.');
    }
    if ($mode === 'restore') {
        if (($argv[3] ?? '') !== 'RESTAURAR_HISTORICO_V3') {
            throw new RuntimeException('Confirmação de restauração necessária.');
        }
        $folder = dirname($relative);
        $before = json_decode((string) $disk->get($folder.'/before-metadata.json'), true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $disk->get($folder.'/after-metadata.json'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($before) || ! is_array($after) || $before === [] || count($before) !== count($after)) {
            throw new RuntimeException('Backups ausentes ou incompletos.');
        }
        $fields = ['responsible_name', 'responsible_email', 'recipient_email', 'issued_on', 'services', 'monthly_amount', 'one_time_amount', 'calculation', 'evidence', 'updated_at'];
        DB::transaction(function () use ($before, $after, $fields, $table): void {
            $ids = array_column($before, 'proposal_id');
            $current = DB::table($table)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->lockForUpdate()->get()->keyBy('proposal_id');
            if ($current->count() !== count($before)) {
                throw new RuntimeException('Registros históricos mudaram.');
            }
            foreach ($before as $r) {
                $id = (int) $r['proposal_id'];
                $row = $current->get($id);
                if (! $row || ($after[$id] ?? null) !== historyMetadataV3Fingerprint($row)) {
                    throw new RuntimeException('Metadados modificados depois da V3: '.$id.'. Restauração cancelada.');
                }
            }
            foreach ($before as $r) {
                DB::table($table)->where('proposal_id', (int) $r['proposal_id'])->update(array_intersect_key($r, array_flip($fields)));
            }
            echo 'Metadados anteriores restaurados: '.count($before)."\n";
        });
        exit(0);
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($data['version'] ?? null) !== 3 || ! is_array($data['rows'] ?? null) || count($data['rows']) === 0) {
        throw new RuntimeException('Revisão vazia ou inválida.');
    }
    // The apply pass rechecks the exact metadata fingerprint and file hash in one transaction.
    // Only the historical projection is updated; core records and CRM links remain untouched.
    DB::transaction(function () use ($mode, $data, $table, $disk, $relative): void {
        $query = DB::table($table)->orderBy('proposal_id');
        if ($mode === 'apply') {
            $query->lockForUpdate();
        }
        $current = $query->get()->keyBy('proposal_id');
        $ids = DB::table('ec_proposals')->where('origin', 'imported')->pluck('id')->flip();
        $docs = DB::table('ec_proposal_documents')->whereIn('kind', ['historical_pdf', 'historical_original'])
            ->whereIn('proposal_id', $ids->keys())->get(['proposal_id', 'storage_path', 'sha256'])->groupBy('proposal_id');
        $seen = [];
        $updates = [];
        $before = [];
        foreach ($data['rows'] as $r) {
            if (! is_array($r) || array_diff(['proposal_id', 'expected_fingerprint', 'document_sha256', 'monthly_amount', 'one_time_amount', 'calculation', 'evidence', 'responsible_name', 'responsible_email', 'recipient_email', 'issued_on', 'services'], array_keys($r))) {
                throw new RuntimeException('Linha inválida.');
            }
            $id = (int) $r['proposal_id'];
            $old = $current->get($id);
            $dd = $docs->get($id);
            if (isset($seen[$id]) || ! $ids->has($id) || ! $old || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro histórico inválido: '.$id);
            }
            $seen[$id] = true;
            if (historyMetadataV3Fingerprint($old) !== $r['expected_fingerprint']) {
                throw new RuntimeException('Metadados alterados desde a simulação. Gere uma nova revisão: '.$id);
            }
            $doc = $dd->first();
            if ($r['document_sha256'] !== $old->document_sha256 || $r['document_sha256'] !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento divergente: '.$id);
            }
            foreach (['monthly_amount', 'one_time_amount'] as $key) {
                if ($r[$key] !== null && (! is_string($r[$key]) || ! preg_match('/^\d{1,9}\.\d{2}$/D', $r[$key]))) {
                    throw new RuntimeException('Valor inválido: '.$id);
                }
            }
            if ($r['calculation'] !== null && (! is_string($r['calculation']) || mb_strlen($r['calculation']) > 16000)) {
                throw new RuntimeException('Memória inválida: '.$id);
            }
            if (! is_array($r['evidence']) || ($r['evidence']['finance_v2']['version'] ?? null) !== 2 || ($r['evidence']['identity_v3']['version'] ?? null) !== 3) {
                throw new RuntimeException('Estrutura financeira inválida: '.$id);
            }
            foreach (['responsible_name' => 220, 'responsible_email' => 320, 'recipient_email' => 320] as $field => $max) {
                if ($r[$field] !== null && (! is_string($r[$field]) || mb_strlen($r[$field]) > $max || ($field !== 'responsible_name' && filter_var($r[$field], FILTER_VALIDATE_EMAIL) === false))) {
                    throw new RuntimeException('Identificação inválida: '.$id);
                }
            }
            if ($r['issued_on'] !== null && (! is_string($r['issued_on']) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $r['issued_on'], $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
                throw new RuntimeException('Data inválida: '.$id);
            }
            $oldServices = json_decode((string) $old->services, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($r['services']) || array_diff($r['services'], ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE']) !== [] || ($oldServices !== [] && $r['services'] !== $oldServices)) {
                throw new RuntimeException('Serviços inválidos ou divergentes: '.$id);
            }
            // Merge only the known evidence namespaces.
            $evidence = json_decode((string) $old->evidence, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($evidence)) {
                $evidence = [];
            }
            $evidence['finance_v2'] = $r['evidence']['finance_v2'];
            $evidence['identity_v3'] = $r['evidence']['identity_v3'];
            $updates[$id] = [
                'responsible_name' => $r['responsible_name'],
                'responsible_email' => $r['responsible_email'],
                'recipient_email' => $r['recipient_email'],
                'issued_on' => $r['issued_on'],
                'services' => json_encode($r['services'], JSON_THROW_ON_ERROR),
                'monthly_amount' => $r['monthly_amount'],
                'one_time_amount' => $r['one_time_amount'],
                'calculation' => $r['calculation'],
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now('UTC'),
            ];
            $before[] = $old;
        }
        echo 'Validados '.count($updates)." registros históricos.\n";
        if ($mode === 'check') {
            echo "SIMULAÇÃO: nenhuma gravação no banco.\n";

            return;
        }
        $snapshot = dirname($relative).'/before-metadata.json';
        if ($disk->exists($snapshot)) {
            throw new RuntimeException('Snapshot já existe; gere nova revisão para evitar sobrescrevê-lo.');
        }
        if (! $disk->put($snapshot, json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))) {
            throw new RuntimeException('Não foi possível salvar o backup de metadados.');
        }
        chmod($disk->path($snapshot), 0600);
        foreach ($updates as $id => $values) {
            DB::table($table)->where('proposal_id', $id)->update($values);
        }
        $after = [];
        foreach (DB::table($table)->whereIn('proposal_id', array_keys($updates))->orderBy('proposal_id')->get() as $row) {
            $after[(int) $row->proposal_id] = historyMetadataV3Fingerprint($row);
        }
        $afterPath = dirname($relative).'/after-metadata.json';
        if (! $disk->put($afterPath, json_encode($after, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Falha ao salvar referência de restauração.');
        }
        chmod($disk->path($afterPath), 0600);
        echo 'METADADOS COMERCIAIS ATUALIZADOS: '.count($updates)."\n";
    });
    if ($mode === 'apply') {
        echo "Propostas, documentos, aceite, HubSpot e numeração oficial preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
