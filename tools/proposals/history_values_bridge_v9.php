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

function v9Json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function v9Hash($value): string
{
    return hash('sha256', v9Json($value));
}
function v9Read($disk, string $path): array
{
    $value = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException('JSON inválido.');
    }

    return $value;
}
function v9Write($disk, string $path, $value): void
{
    if ($disk->exists($path) || ! $disk->put($path, v9Json($value))) {
        throw new RuntimeException('Falha ao criar relatório privado: '.$path);
    }
    chmod($disk->path($path), 0600);
}
function v9Money($value): bool
{
    return $value === null || (is_string($value) && preg_match('/^\d{1,9}\.\d{2}$/D', $value) === 1);
}
function v9Rows(string $table, string $key, array $ids, bool $lock): Collection
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
    if (preg_match('~^proposals/history-values-v9/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
        throw new RuntimeException('Caminho privado ou dependência V4 inválida.');
    }
    if ($mode === 'export') {
        $proposals = DB::table($coreTable)->where('origin', 'imported')->orderBy('id')->get();
        $ids = $proposals->pluck('id');
        $metadata = DB::table($metaTable)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($proposals as $row) {
            $row->fingerprint = v9Hash($row);
        }
        foreach ($metadata as $row) {
            $row->fingerprint = v9Hash($row);
        }
        $documents = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'sha256', 'storage_path']);
        v9Write($disk, $relative, compact('proposals', 'metadata', 'documents'));
        echo 'Históricos exportados: '.$proposals->count()."\n";
        exit(0);
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_HISTORICO_V9') {
            throw new RuntimeException('Confirmação de restauração inválida.');
        }
        $before = v9Read($disk, $folder.'/before.json');
        $after = v9Read($disk, $folder.'/after.json');
        if ($before === [] || count($before) !== count($after)) {
            throw new RuntimeException('Backup incompleto.');
        }
        DB::transaction(function () use ($before, $after, $coreTable, $metaTable): void {
            $ids = array_keys($before);
            $ps = v9Rows($coreTable, 'id', $ids, true);
            $ms = v9Rows($metaTable, 'proposal_id', $ids, true);
            foreach ($before as $id => $old) {
                if (! $ps->has($id) || ! $ms->has($id) || ($after[$id]['core'] ?? null) !== v9Hash($ps->get($id)) || ($after[$id]['meta'] ?? null) !== v9Hash($ms->get($id))) {
                    throw new RuntimeException('Registro modificado depois da V9: '.$id.'. Restauração cancelada.');
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
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_HISTORICO_V9')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v9Read($disk, $relative);
    if (($plan['version'] ?? null) !== 9 || ! is_array($plan['rows'] ?? null)) {
        throw new RuntimeException('Revisão inválida.');
    }
    if ($plan['rows'] === []) {
        echo "Nenhuma atualização necessária.\n";
        exit(0);
    }
    DB::transaction(function () use ($mode, $plan, $disk, $folder, $coreTable, $metaTable): void {
        $ids = array_column($plan['rows'], 'proposal_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Propostas repetidas no lote.');
        }
        $ps = v9Rows($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v9Rows($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        $validServices = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'];
        foreach ($plan['rows'] as $r) {
            $id = (int) $r['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v9Hash($p) !== $r['expected_core'] || v9Hash($m) !== $r['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado desde a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($doc->sha256 !== $r['document_sha256'] || $m->document_sha256 !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento alterado ou ausente: '.$id);
            }
            $meta = $r['meta'];
            $core = $r['core'];
            if (! is_array($meta) || ! is_array($core) || array_diff(['monthly_amount', 'one_time_amount', 'services'], array_keys($meta)) || array_diff(array_keys($meta), ['monthly_amount', 'one_time_amount', 'services']) || array_diff(array_keys($core), ['monthly_final', 'one_time_final', 'retro_total', 'recurring_total', 'service_codes'])) {
                throw new RuntimeException('Campos inválidos: '.$id);
            }
            $oldServices = json_decode((string) ($m->services ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($meta['services']) || array_diff($meta['services'], $validServices) || (is_array($oldServices) && $oldServices !== [] && $oldServices !== $meta['services'])) {
                throw new RuntimeException('Serviços divergentes: '.$id);
            }
            $evidence = $r['evidence'];
            $f = $evidence['finance_v2'] ?? [];
            $bundle = ($f['billing_mode'] ?? '') === 'historical_bundle_one_time';
            $retro = $meta['services'] === ['RETROATIVAS'] || $bundle;
            if (! v9Money($meta['monthly_amount']) || ! v9Money($meta['one_time_amount']) || ($retro && $meta['monthly_amount'] !== null)) {
                throw new RuntimeException('Valores financeiros inválidos: '.$id);
            }
            if (($evidence['finance_v9']['version'] ?? null) !== 9 || ! is_array($f) || ($f['reader_version'] ?? null) !== 9 || ! v9Money($f['implantation_amount'] ?? null) || ! v9Money($f['conditional_total'] ?? null)) {
                throw new RuntimeException('Evidência financeira inválida: '.$id);
            }
            if ($bundle) {
                $components = $f['components'] ?? [];
                if (count($meta['services']) !== 2 || array_diff(['RETROATIVAS', 'ACOMPANHAMENTO'], $meta['services']) !== [] || ! is_array($components) || count($components) !== 2 || $meta['monthly_amount'] !== null || $meta['one_time_amount'] === null || ($f['monthly_state'] ?? null) !== 'not_applicable') {
                    throw new RuntimeException('Composição de serviços inválida: '.$id);
                }
                $sum = 0;
                $componentCodes = [];
                foreach ($components as $component) {
                    $amount = $component['amount'] ?? null;
                    if (($component['billing'] ?? '') !== 'one_time' || $amount === null || ! v9Money($amount) || (! empty($component['bands']) && ($component['table_total'] ?? null) !== $amount)) {
                        throw new RuntimeException('Componente inválido: '.$id);
                    }
                    $componentCodes[] = $component['service'] ?? '';
                    $sum += (int) str_replace('.', '', $amount);
                }
                if (array_diff(['RETROATIVAS', 'ACOMPANHAMENTO'], $componentCodes) !== [] || $sum !== (int) str_replace('.', '', $meta['one_time_amount'])) {
                    throw new RuntimeException('Total dos serviços divergente: '.$id);
                }
            }
            // Only finance namespaces change. Keep CRM V4/manual review, identity and any other evidence.
            $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            $previousFinance = is_array($oldEvidence['finance_v2'] ?? null) ? $oldEvidence['finance_v2'] : [];
            $oldEvidence['finance_v2'] = $f;
            $oldEvidence['finance_v9'] = $evidence['finance_v9'];
            foreach ($core as $key => $value) {
                if (in_array($key, ['monthly_final', 'one_time_final'], true)) {
                    if (! v9Money($value) || ($retro && $key === 'monthly_final' && $value !== null)) {
                        throw new RuntimeException('Valor do cadastro inválido: '.$id);
                    }
                    $prior = $key === 'monthly_final' ? $m->monthly_amount : $m->one_time_amount;
                    $priorImpl = $previousFinance['implantation_amount'] ?? null;
                    $allowed = [$prior];
                    if ($key === 'one_time_final' && $priorImpl !== null) {
                        $allowed[] = $priorImpl;
                        if ($prior !== null) {
                            $allowed[] = number_format((float) $prior + (float) $priorImpl, 2, '.', '');
                        }
                    }
                    if ($p->$key !== null && (string) $p->$key !== (string) $value && ! in_array((string) $p->$key, array_map(static fn ($v) => $v === null ? null : (string) $v, $allowed), true)) {
                        throw new RuntimeException('Valor principal não corresponde à projeção histórica anterior: '.$id.'/'.$key);
                    }
                } elseif ($key === 'service_codes') {
                    $previous = json_decode((string) ($p->service_codes ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
                    if (! empty($previous) || $value !== $meta['services']) {
                        throw new RuntimeException('Serviço já preenchido ou divergente: '.$id);
                    }
                    $core[$key] = v9Json($value);
                } elseif (! is_int($value) || $value < 0 || $value > 2147483647 || $p->$key !== null) {
                    throw new RuntimeException('Quantidade inválida ou já preenchida: '.$id);
                }
            }
            $meta['services'] = v9Json($meta['services']);
            $meta['evidence'] = v9Json($oldEvidence);
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
        v9Write($disk, $folder.'/before.json', $before);
        foreach ($updates as $id => $u) {
            if ($u['core'] !== []) {
                DB::table($coreTable)->where('id', $id)->update($u['core']);
            }
            DB::table($metaTable)->where('proposal_id', $id)->update($u['meta']);
        }
        $ps = v9Rows($coreTable, 'id', $ids, false);
        $ms = v9Rows($metaTable, 'proposal_id', $ids, false);
        $after = [];
        foreach ($ids as $id) {
            $after[$id] = ['core' => v9Hash($ps->get($id)), 'meta' => v9Hash($ms->get($id))];
        }
        v9Write($disk, $folder.'/after.json', $after);
    });
    if ($mode === 'apply') {
        echo "V9 concluída. Propostas originais, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
