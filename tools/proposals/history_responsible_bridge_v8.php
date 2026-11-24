<?php

// Local CLI only. No SMTP, CRM updates, issuance or repricing.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function v8Json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function v8Hash($value): string
{
    return hash('sha256', v8Json($value));
}
function v8Read($disk, string $path): array
{
    $value = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('JSON inválido.');
    }

    return $value;
}
function v8Write($disk, string $path, $value): void
{
    if ($disk->exists($path) || ! $disk->put($path, v8Json($value))) {
        throw new RuntimeException('Falha ao criar relatório privado: '.$path);
    }
    chmod($disk->path($path), 0600);
}
function v8Validate(string $key, $value): void
{
    $limits = ['sales_responsible' => 160, 'responsible_name' => 160, 'responsible_email' => 320];
    if (! is_string($value) || trim($value) === '' || mb_strlen($value) > ($limits[$key] ?? 0)) {
        throw new RuntimeException('Valor inválido: '.$key);
    }
    if (str_contains($key, 'email') && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('E-mail inválido.');
    }

}
function v8Missing($value): bool
{
    if ($value === null || trim((string) $value) === '') {
        return true;
    }

    return in_array(mb_strtolower(trim((string) $value)), ['não identificado', 'não identificada', 'não informado', 'não informada', 'nao identificado', 'nao identificada', 'nao informado', 'nao informada', 'pendente'], true);
}
function v8Rows(string $table, string $key, array $ids, bool $lock): Collection
{
    $q = DB::table($table)->whereIn($key, $ids)->orderBy($key);
    if ($lock) {
        $q->lockForUpdate();
    }

    return $q->get()->keyBy($key);
}

$mode = $argv[1] ?? '';
$relative = $argv[2] ?? '';
$confirm = $argv[3] ?? '';
$coreTable = 'ec_proposals';
$metaTable = 'ec_proposal_history_metadata';
$disk = Storage::disk('local');
$folder = dirname($relative);
try {
    if (preg_match('~^proposals/history-responsible-v8/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
        throw new RuntimeException('Caminho privado ou dependência V4 inválida.');
    }
    if ($mode === 'export') {
        $proposals = DB::table($coreTable)->where('origin', 'imported')->orderBy('id')->get();
        $ids = $proposals->pluck('id');
        $metadata = DB::table($metaTable)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($proposals as $row) {
            $row->fingerprint = v8Hash($row);
        }
        foreach ($metadata as $row) {
            $row->fingerprint = v8Hash($row);
        }
        $documents = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'sha256', 'storage_path']);
        v8Write($disk, $relative, compact('proposals', 'metadata', 'documents'));
        echo 'Históricos exportados: '.$proposals->count()."\n";
        exit(0);
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_RESPONSAVEIS_V8') {
            throw new RuntimeException('Confirmação de restauração inválida.');
        }
        $before = v8Read($disk, $folder.'/before.json');
        $after = v8Read($disk, $folder.'/after.json');
        if ($before === [] || count($before) !== count($after)) {
            throw new RuntimeException('Backup incompleto.');
        }
        DB::transaction(function () use ($before, $after, $coreTable, $metaTable): void {
            $ids = array_keys($before);
            $ps = v8Rows($coreTable, 'id', $ids, true);
            $ms = v8Rows($metaTable, 'proposal_id', $ids, true);
            foreach ($before as $id => $old) {
                if (! $ps->has($id) || ! $ms->has($id) || ($after[$id]['core'] ?? null) !== v8Hash($ps->get($id)) || ($after[$id]['meta'] ?? null) !== v8Hash($ms->get($id))) {
                    throw new RuntimeException('Registro modificado depois da V8: '.$id.'. Restauração cancelada.');
                }
            }
            foreach ($before as $id => $old) {
                if ($old['core'] !== []) {
                    DB::table($coreTable)->where('id', $id)->update($old['core']);
                }
                DB::table($metaTable)->where('proposal_id', $id)->update($old['meta']);
            }
        });
        echo "Dados anteriores restaurados.\n";
        exit(0);
    }
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_RESPONSAVEIS_V8')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v8Read($disk, $relative);
    if (($plan['version'] ?? null) !== 8 || ! is_array($plan['rows'])) {
        throw new RuntimeException('Revisão inválida.');
    }
    if ($plan['rows'] === []) {
        echo "Nenhum registro precisa de atualização.\n";
        exit(0);
    }
    DB::transaction(function () use ($mode, $plan, $disk, $folder, $coreTable, $metaTable): void {
        $ids = array_column($plan['rows'], 'proposal_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Propostas repetidas no lote.');
        }
        $ps = v8Rows($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v8Rows($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        foreach ($plan['rows'] as $r) {
            $id = (int) $r['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v8Hash($p) !== $r['expected_core'] || v8Hash($m) !== $r['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado desde a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($doc->sha256 !== $r['document_sha256'] || $m->document_sha256 !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento alterado ou ausente: '.$id);
            }
            $meta = $r['meta'];
            $core = $r['core'];
            $identity = $r['identity_patch'];
            $signature = $r['signature'];
            if (! is_array($meta) || ! is_array($core) || ! is_array($identity) || ! is_array($signature)
                || array_diff(array_keys($meta), ['responsible_name', 'responsible_email'])
                || array_diff(array_keys($core), ['sales_responsible'])
                || array_diff(array_keys($identity), ['responsible_name', 'responsible_email'])
                || ($signature['state'] ?? null) !== 'identified'
                || ! is_string($signature['signature_name'] ?? null)
                || ! is_string($signature['evidence'] ?? null)) {
                throw new RuntimeException('Campos inválidos: '.$id);
            }
            foreach ($meta as $key => $value) {
                if (! v8Missing($m->$key)) {
                    throw new RuntimeException('Metadado já preenchido: '.$id.'/'.$key);
                }
                v8Validate($key, $value);
            }
            foreach ($core as $key => $value) {
                if (! v8Missing($p->$key)) {
                    throw new RuntimeException('Cadastro já preenchido: '.$id.'/'.$key);
                }
                v8Validate($key, $value);
            }
            $oldEvidence = json_decode($m->evidence === null || $m->evidence === '' ? '{}' : (string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            $oldIdentity = is_array($oldEvidence['identity_v3'] ?? null) ? $oldEvidence['identity_v3'] : [];
            foreach ($identity as $key => $value) {
                if (! v8Missing($oldIdentity[$key] ?? null)) {
                    throw new RuntimeException('Identidade já preenchida: '.$id.'/'.$key);
                }
                v8Validate($key, $value);
                $oldIdentity[$key] = $value;
            }
            if ($identity !== []) {
                $oldIdentity['responsible_source'] = ['source' => 'assinatura_final_v8', 'candidates' => [$signature['signature_name']]];
                $oldEvidence['identity_v3'] = $oldIdentity;
            }
            $oldEvidence['responsible_v8'] = ['version' => 8, 'sha256' => $doc->sha256, 'signature' => $signature];
            $meta['evidence'] = v8Json($oldEvidence);
            $meta['updated_at'] = now('UTC');
            if ($core !== []) {
                $core['updated_at'] = now('UTC');
            }
            $before[$id] = ['core' => array_intersect_key((array) $p, $core), 'meta' => array_intersect_key((array) $m, $meta)];
            $updates[$id] = compact('core', 'meta');
        }
        echo 'Validados: '.count($updates)." registros.\n";
        if ($mode === 'check') {
            echo "SIMULAÇÃO: nenhum dado alterado.\n";

            return;
        }
        v8Write($disk, $folder.'/before.json', $before);
        foreach ($updates as $id => $u) {
            if ($u['core'] !== []) {
                DB::table($coreTable)->where('id', $id)->update($u['core']);
            }
            DB::table($metaTable)->where('proposal_id', $id)->update($u['meta']);
        }
        $ps = v8Rows($coreTable, 'id', $ids, false);
        $ms = v8Rows($metaTable, 'proposal_id', $ids, false);
        $after = [];
        foreach ($ids as $id) {
            $after[$id] = ['core' => v8Hash($ps->get($id)), 'meta' => v8Hash($ms->get($id))];
        }
        v8Write($disk, $folder.'/after.json', $after);
    });
    if ($mode === 'apply') {
        echo "V8 concluída. Responsáveis atualizados; preços, documentos, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
