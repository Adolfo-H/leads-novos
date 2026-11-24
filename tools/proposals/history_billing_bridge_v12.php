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

function v10Json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function v10Hash($value): string
{
    return hash('sha256', v10Json($value));
}
function v10Read($disk, string $path): array
{
    $value = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('JSON inválido.');
    }

    return $value;
}
function v10Write($disk, string $path, $value): void
{
    if ($disk->exists($path) || ! $disk->put($path, v10Json($value))) {
        throw new RuntimeException('Falha ao criar relatório privado: '.$path);
    }
    chmod($disk->path($path), 0600);
}
function v10Money($value): bool
{
    return $value === null || (is_string($value) && preg_match('/^\d{1,9}\.\d{2}$/D', $value) === 1);
}
function v10Rows(string $table, string $key, array $ids, bool $lock): Collection
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
    if (preg_match('~^proposals/history-billing-v12/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
        throw new RuntimeException('Caminho privado ou dependência V4 inválida.');
    }
    if ($mode === 'export') {
        $proposals = DB::table($coreTable)->where('origin', 'imported')->orderBy('id')->get();
        $ids = $proposals->pluck('id');
        $metadata = DB::table($metaTable)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($proposals as $row) {
            $row->fingerprint = v10Hash($row);
        }
        foreach ($metadata as $row) {
            $row->fingerprint = v10Hash($row);
        }
        $documents = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'sha256', 'storage_path', 'original_name']);
        v10Write($disk, $relative, compact('proposals', 'metadata', 'documents'));
        echo 'Históricos exportados: '.$proposals->count()."\n";
        exit(0);
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_HISTORICO_V12') {
            throw new RuntimeException('Confirmação de restauração inválida.');
        }
        $before = v10Read($disk, $folder.'/before.json');
        $after = v10Read($disk, $folder.'/after.json');
        if ($before === [] || count($before) !== count($after)) {
            throw new RuntimeException('Backup incompleto.');
        }
        DB::transaction(function () use ($before, $after, $coreTable, $metaTable): void {
            $ids = array_keys($before);
            $ps = v10Rows($coreTable, 'id', $ids, true);
            $ms = v10Rows($metaTable, 'proposal_id', $ids, true);
            foreach ($before as $id => $old) {
                if (! $ps->has($id) || ! $ms->has($id) || ($after[$id]['core'] ?? null) !== v10Hash($ps->get($id)) || ($after[$id]['meta'] ?? null) !== v10Hash($ms->get($id))) {
                    throw new RuntimeException('Registro modificado depois da V12: '.$id.'. Restauração cancelada.');
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
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_HISTORICO_V12')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v10Read($disk, $relative);
    if (($plan['version'] ?? null) !== 12 || ! is_array($plan['rows'] ?? null)) {
        throw new RuntimeException('Revisão inválida.');
    }
    if ($plan['rows'] === []) {
        echo "Nenhuma atualização necessária.\n";
        exit(0);
    }
    DB::transaction(function () use ($mode, $plan, $disk, $folder, $coreTable, $metaTable): void {
        $ids = array_column($plan['rows'], 'proposal_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Propostas repetidas.');
        }
        $ps = v10Rows($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v10Rows($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        $cents = static fn ($value): int => $value === null ? 0 : (int) str_replace('.', '', $value);
        $blank = static fn ($value): bool => $value === null || in_array(mb_strtolower(trim((string) $value)), ['', 'não identificado', 'não identificada', 'não informado', 'não informada', 'pendente', '—', '-'], true);
        foreach ($plan['rows'] as $row) {
            $id = (int) $row['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v10Hash($p) !== $row['expected_core'] || v10Hash($m) !== $row['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado desde a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($doc->sha256 !== $row['document_sha256'] || $m->document_sha256 !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento alterado ou ausente: '.$id);
            }
            $b = $row['billing'];
            $meta = $row['meta'];
            $core = $row['core'];
            if (! is_array($b) || ($b['version'] ?? null) !== 12 || ! in_array($b['kind'] ?? null, ['licensing_only', 'annual_package', 'recurring_with_extra'], true) || ! is_array($meta) || ! is_array($core) || ! is_array($b['warnings'] ?? null) || ! is_array($b['evidence'] ?? null)) {
                throw new RuntimeException('Projeção inválida: '.$id);
            }
            foreach (['monthly_reference', 'annual_amount', 'initial_total', 'implantation', 'retroactive', 'historical_import', 'extra_period', 'one_off_total'] as $key) {
                if (! array_key_exists($key, $b) || ! v10Money($b[$key])) {
                    throw new RuntimeException('Valor inválido: '.$id.'/'.$key);
                }
            }
            $services = $b['retroactive'] === null ? ['LICENCIAMENTO'] : ['LICENCIAMENTO', 'RETROATIVAS'];
            if (($b['services'] ?? null) !== $services) {
                throw new RuntimeException('Serviços incompatíveis: '.$id);
            }
            if ($b['kind'] === 'licensing_only') {
                foreach (['monthly_reference', 'annual_amount', 'initial_total', 'implantation', 'retroactive', 'historical_import', 'extra_period', 'one_off_total'] as $key) {
                    if ($b[$key] !== null) {
                        throw new RuntimeException('Classificação simples contém valores: '.$id);
                    }
                }
            } else {
                foreach (['monthly_reference', 'initial_total', 'implantation', 'one_off_total'] as $key) {
                    if ($b[$key] === null) {
                        throw new RuntimeException('Componente ausente: '.$id.'/'.$key);
                    }
                }
                if (($b['retroactive'] === null) === ($b['historical_import'] === null)) {
                    throw new RuntimeException('Avulso ambíguo: '.$id);
                }
                if ($cents($b['implantation']) + $cents($b['retroactive']) + $cents($b['historical_import']) !== $cents($b['one_off_total'])) {
                    throw new RuntimeException('Avulsos não reconciliados: '.$id);
                }
                if ($b['kind'] === 'annual_package') {
                    if ($b['annual_amount'] === null || $b['extra_period'] === null || $b['retroactive'] === null || $cents($b['monthly_reference']) * 12 !== $cents($b['annual_amount']) || $cents($b['one_off_total']) + $cents($b['annual_amount']) + $cents($b['extra_period']) !== $cents($b['initial_total'])) {
                        throw new RuntimeException('Pacote anual não reconciliado: '.$id);
                    }
                } elseif ($b['annual_amount'] !== null || $b['extra_period'] !== null || $b['one_off_total'] !== $b['initial_total']) {
                    throw new RuntimeException('Total inicial não reconciliado: '.$id);
                }
            }
            foreach (['monthly_quantity', 'annual_quantity', 'retro_quantity', 'payment_days'] as $key) {
                if (! array_key_exists($key, $b) || ($b[$key] !== null && (! is_int($b[$key]) || $b[$key] < 0 || $b[$key] > 2147483647))) {
                    throw new RuntimeException('Quantidade inválida: '.$id.'/'.$key);
                }
            }
            if (array_diff(array_keys($meta), ['services', 'monthly_amount', 'one_time_amount', 'responsible_name', 'responsible_email']) || array_diff(array_keys($core), ['service_codes', 'monthly_final', 'one_time_final', 'recurring_total', 'retro_total', 'sales_responsible'])) {
                throw new RuntimeException('Campos fora do escopo: '.$id);
            }
            foreach (['services', 'service_codes'] as $field) {
                $record = $field === 'services' ? $m : $p;
                $previous = json_decode((string) ($record->$field ?? 'null'), true, 512, JSON_THROW_ON_ERROR) ?? [];
                if (! is_array($previous) || array_diff($previous, $services)) {
                    throw new RuntimeException('Classificação anterior incompatível: '.$id);
                }
                if ($field === 'services' && array_key_exists($field, $meta)) {
                    if ($meta[$field] !== $services) {
                        throw new RuntimeException('Classificação inválida: '.$id);
                    }
                    $meta[$field] = v10Json($services);
                } elseif ($field === 'service_codes' && array_key_exists($field, $core)) {
                    if ($core[$field] !== $services) {
                        throw new RuntimeException('Classificação inválida: '.$id);
                    }
                    $core[$field] = v10Json($services);
                }
            }
            foreach ([['monthly_amount', $m, $meta, 'monthly_reference'], ['one_time_amount', $m, $meta, 'one_off_total'], ['monthly_final', $p, $core, 'monthly_reference'], ['one_time_final', $p, $core, 'one_off_total'], ['recurring_total', $p, $core, 'annual_quantity'], ['retro_total', $p, $core, 'retro_quantity']] as [$field, $record, $patch, $key]) {
                if (array_key_exists($field, $patch) && ($record->$field !== null || $patch[$field] === null || $patch[$field] !== $b[$key] || $b['kind'] === 'licensing_only')) {
                    throw new RuntimeException('Tentativa de substituir valor existente ou sem evidência: '.$id.'/'.$field);
                }
            }
            $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            $sig = $row['signature'] ?? null;
            if (array_key_exists('responsible_name', $meta) || array_key_exists('responsible_email', $meta) || array_key_exists('sales_responsible', $core)) {
                if (! is_array($sig) || ($sig['source'] ?? null) !== 'assinatura_final_e_contato_comercial_da_capa' || ! is_string($sig['name'] ?? null) || mb_strlen($sig['name']) > 160 || ! is_string($sig['email'] ?? null) || ! filter_var($sig['email'], FILTER_VALIDATE_EMAIL) || ! str_ends_with(strtolower($sig['email']), '@exportcontrol.com.br')) {
                    throw new RuntimeException('Assinatura inválida: '.$id);
                }
                foreach ([['responsible_name', $m, $meta, 'name'], ['responsible_email', $m, $meta, 'email'], ['sales_responsible', $p, $core, 'name']] as [$field, $record, $patch, $key]) {
                    if (array_key_exists($field, $patch) && (! $blank($record->$field) || $patch[$field] !== $sig[$key])) {
                        throw new RuntimeException('Assinatura anterior preenchida: '.$id.'/'.$field);
                    }
                }
                $oldEvidence['identity_v10'] = $sig;
            }
            $oldEvidence['billing_v12'] = $b;
            $meta['evidence'] = v10Json($oldEvidence);
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
        v10Write($disk, $folder.'/before.json', $before);
        foreach ($updates as $id => $u) {
            if ($u['core'] !== []) {
                DB::table($coreTable)->where('id', $id)->update($u['core']);
            }
            DB::table($metaTable)->where('proposal_id', $id)->update($u['meta']);
        }
        $ps = v10Rows($coreTable, 'id', $ids, false);
        $ms = v10Rows($metaTable, 'proposal_id', $ids, false);
        $after = [];
        foreach ($ids as $id) {
            $after[$id] = ['core' => v10Hash($ps->get($id)), 'meta' => v10Hash($ms->get($id))];
        }
        v10Write($disk, $folder.'/after.json', $after);
    });
    if ($mode === 'apply') {
        echo "V12 concluída. Histórico, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
