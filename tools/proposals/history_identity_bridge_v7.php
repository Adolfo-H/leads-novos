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

function v7Json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function v7Hash($value): string
{
    return hash('sha256', v7Json($value));
}
function v7Read($disk, string $path): array
{
    $value = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('JSON inválido.');
    }

    return $value;
}
function v7Write($disk, string $path, $value): void
{
    if ($disk->exists($path) || ! $disk->put($path, v7Json($value))) {
        throw new RuntimeException('Falha ao criar relatório privado: '.$path);
    }
    chmod($disk->path($path), 0600);
}
function v7Validate(string $key, $value): void
{
    if ($key === 'recurring_total') {
        if (! is_int($value) || $value < 0 || $value > 2147483647) {
            throw new RuntimeException('Quantidade inválida.');
        }

        return;
    }
    $limits = ['client_contact' => 160, 'client_email' => 200, 'client_cnpj' => 30, 'sales_responsible' => 160, 'issued_on' => 10, 'responsible_name' => 220, 'responsible_email' => 320, 'recipient_email' => 320, 'recipient_name' => 160, 'client_legal_name' => 220];
    if (! is_string($value) || trim($value) === '' || mb_strlen($value) > ($limits[$key] ?? 0)) {
        throw new RuntimeException('Valor inválido: '.$key);
    }
    if (str_contains($key, 'email') && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('E-mail inválido.');
    }
    if ($key === 'issued_on' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]))) {
        throw new RuntimeException('Data inválida.');
    }
    if ($key === 'client_cnpj' && (preg_match('/^\d{14}$/D', $value) !== 1 || $value === '43386711000159')) {
        throw new RuntimeException('CNPJ inválido ou fornecedor.');
    }
}
function v7Rows(string $table, string $key, array $ids, bool $lock): Collection
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
    if (preg_match('~^proposals/history-identity-v7/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
        throw new RuntimeException('Caminho privado ou dependência V4 inválida.');
    }
    if ($mode === 'export') {
        $proposals = DB::table($coreTable)->where('origin', 'imported')->orderBy('id')->get();
        $ids = $proposals->pluck('id');
        $metadata = DB::table($metaTable)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($proposals as $row) {
            $row->fingerprint = v7Hash($row);
        }
        foreach ($metadata as $row) {
            $row->fingerprint = v7Hash($row);
        }
        $documents = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'sha256', 'storage_path']);
        v7Write($disk, $relative, compact('proposals', 'metadata', 'documents'));
        echo 'Históricos exportados: '.$proposals->count()."\n";
        exit(0);
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_HISTORICO_V7') {
            throw new RuntimeException('Confirmação de restauração inválida.');
        }
        $before = v7Read($disk, $folder.'/before.json');
        $after = v7Read($disk, $folder.'/after.json');
        if ($before === [] || count($before) !== count($after)) {
            throw new RuntimeException('Backup incompleto.');
        }
        DB::transaction(function () use ($before, $after, $coreTable, $metaTable): void {
            $ids = array_keys($before);
            $ps = v7Rows($coreTable, 'id', $ids, true);
            $ms = v7Rows($metaTable, 'proposal_id', $ids, true);
            foreach ($before as $id => $old) {
                if (! $ps->has($id) || ! $ms->has($id) || ($after[$id]['core'] ?? null) !== v7Hash($ps->get($id)) || ($after[$id]['meta'] ?? null) !== v7Hash($ms->get($id))) {
                    throw new RuntimeException('Registro modificado depois da V7: '.$id.'. Restauração cancelada.');
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
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_HISTORICO_V7')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v7Read($disk, $relative);
    if (($plan['version'] ?? null) !== 7 || ! is_array($plan['rows'])) {
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
        $ps = v7Rows($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v7Rows($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        foreach ($plan['rows'] as $r) {
            $id = (int) $r['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v7Hash($p) !== $r['expected_core'] || v7Hash($m) !== $r['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado desde a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($doc->sha256 !== $r['document_sha256'] || $m->document_sha256 !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento alterado ou ausente: '.$id);
            }
            $meta = $r['meta'];
            $core = $r['core'];
            $identity = $r['identity_patch'];
            $finance = $r['finance_patch'];
            if (! is_array($meta) || ! is_array($core) || ! is_array($identity) || ! is_array($finance)
                || array_diff(array_keys($meta), ['responsible_name', 'responsible_email', 'recipient_email', 'issued_on'])
                || array_diff(array_keys($core), ['client_contact', 'client_email', 'client_cnpj', 'sales_responsible', 'issued_on', 'recurring_total'])
                || array_diff(array_keys($identity), ['recipient_name', 'recipient_email', 'responsible_name', 'responsible_email', 'client_legal_name', 'responsible_source'])
                || array_diff(array_keys($finance), ['annual_quantity', 'base_quantity', 'annual_quantity_evidence_v7', 'base_quantity_evidence_v7', 'conditions_patch'])) {
                throw new RuntimeException('Campos inválidos: '.$id);
            }
            foreach ($meta as $key => $value) {
                if ($m->$key !== null && $m->$key !== '') {
                    throw new RuntimeException('Metadado já preenchido: '.$id.'/'.$key);
                }
                v7Validate($key, $value);
            }
            foreach ($core as $key => $value) {
                if ($p->$key !== null && $p->$key !== '') {
                    throw new RuntimeException('Cadastro já preenchido: '.$id.'/'.$key);
                }
                v7Validate($key, $value);
            }
            // Only the explicit identity/volume namespaces change. CRM and all prices stay intact.
            $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            $oldIdentity = is_array($oldEvidence['identity_v3'] ?? null) ? $oldEvidence['identity_v3'] : [];
            foreach ($identity as $key => $value) {
                if ($key === 'responsible_source') {
                    if (! is_array($value) || (! isset($identity['responsible_name']) && ! isset($identity['responsible_email']))) {
                        throw new RuntimeException('Fonte de assinatura inválida: '.$id);
                    }
                } else {
                    if (isset($oldIdentity[$key]) && $oldIdentity[$key] !== '') {
                        throw new RuntimeException('Identidade já preenchida: '.$id.'/'.$key);
                    }
                    v7Validate($key, $value);
                }
                $oldIdentity[$key] = $value;
            }
            $oldFinance = is_array($oldEvidence['finance_v2'] ?? null) ? $oldEvidence['finance_v2'] : [];
            foreach (['annual_quantity', 'base_quantity'] as $field) {
                if (! array_key_exists($field, $finance)) {
                    continue;
                }
                $value = $finance[$field];
                if (isset($oldFinance[$field]) && $oldFinance[$field] !== '') {
                    throw new RuntimeException('Volume anterior preservado: '.$id.'/'.$field);
                }
                if (! is_string($value) || preg_match('/^\d{1,10}$/D', $value) !== 1 || (int) $value > 2147483647
                    || ! is_array($finance[$field.'_evidence_v7'] ?? null) || $finance[$field.'_evidence_v7'] === []) {
                    throw new RuntimeException('Volume ou evidência inválida: '.$id.'/'.$field);
                }
                $oldFinance[$field] = $value;
                $oldFinance[$field.'_evidence_v7'] = $finance[$field.'_evidence_v7'];
            }
            $conditions = is_array($oldFinance['conditions'] ?? null) ? $oldFinance['conditions'] : [];
            foreach ($finance['conditions_patch'] ?? [] as $key => $value) {
                if (! in_array($key, ['validade', 'pagamento', 'dependencia'], true) || ! is_string($value) || mb_strlen($value) > 400) {
                    throw new RuntimeException('Condição inválida: '.$id);
                }
                $prior = $conditions[$key] ?? '';
                if ($prior !== '' && (! is_string($prior) || ! str_starts_with($value, $prior))) {
                    throw new RuntimeException('Condição comercial divergente: '.$id);
                }
                $conditions[$key] = $value;
            }
            if (isset($finance['conditions_patch'])) {
                $oldFinance['conditions'] = $conditions;
            }
            if ($identity !== []) {
                $oldEvidence['identity_v3'] = $oldIdentity;
            }
            if ($finance !== []) {
                $oldEvidence['finance_v2'] = $oldFinance;
            }
            $oldEvidence['identity_v7'] = ['version' => 7, 'source' => 'document_sha256', 'sha256' => $doc->sha256];
            $meta['evidence'] = v7Json($oldEvidence);
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
        v7Write($disk, $folder.'/before.json', $before);
        foreach ($updates as $id => $u) {
            if ($u['core'] !== []) {
                DB::table($coreTable)->where('id', $id)->update($u['core']);
            }
            DB::table($metaTable)->where('proposal_id', $id)->update($u['meta']);
        }
        $ps = v7Rows($coreTable, 'id', $ids, false);
        $ms = v7Rows($metaTable, 'proposal_id', $ids, false);
        $after = [];
        foreach ($ids as $id) {
            $after[$id] = ['core' => v7Hash($ps->get($id)), 'meta' => v7Hash($ms->get($id))];
        }
        v7Write($disk, $folder.'/after.json', $after);
    });
    if ($mode === 'apply') {
        echo "V7 concluída. Identidade e volumes atualizados; preços, documentos, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
