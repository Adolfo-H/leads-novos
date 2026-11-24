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
    if (preg_match('~^proposals/history-reconcile-v13/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
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
        if ($confirm !== 'RESTAURAR_HISTORICO_V13') {
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
                    throw new RuntimeException('Registro modificado depois da V13: '.$id.'. Restauração cancelada.');
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
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_HISTORICO_V13')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v10Read($disk, $relative);
    if (($plan['version'] ?? null) !== 13 || ! is_array($plan['rows'] ?? null)) {
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
        $validServices = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'];
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
            $audit = $row['audit'] ?? null;
            $meta = $row['meta'] ?? null;
            $core = $row['core'] ?? null;
            if (! is_array($audit) || ($audit['version'] ?? null) !== 13 || ($audit['source'] ?? null) !== 'document_sha256' || ! is_array($audit['issue'] ?? null) || ! is_array($audit['warnings'] ?? null) || ! is_array($audit['volumes'] ?? null) || ! is_array($meta) || ! is_array($core)) {
                throw new RuntimeException('Auditoria inválida: '.$id);
            }
            $price = $audit['price'] ?? null;
            if ($price !== null) {
                if (! is_array($price) || ! in_array($price['kind'] ?? '', ['monthly', 'monthly_components', 'one_time', 'one_time_components', 'review'], true) || ! in_array($price['state'] ?? '', ['identified', 'rounding', 'review', 'declared_conflict', 'previous_review', 'unresolved'], true) || ! is_array($price['components'] ?? null) || ! is_array($price['evidence'] ?? null) || ! v10Money($price['monthly'] ?? null) || ! v10Money($price['one_time'] ?? null)) {
                    throw new RuntimeException('Preço inválido: '.$id);
                }
                foreach ($price['components'] as $component) {
                    if (! in_array($component['service'] ?? '', $validServices, true) || ! in_array($component['billing'] ?? '', ['monthly', 'one_time'], true) || ($component['amount'] ?? null) === null || ! v10Money($component['amount'])) {
                        throw new RuntimeException('Componente inválido: '.$id);
                    }
                }
            }
            if (array_diff(array_keys($meta), ['services', 'issued_on', 'responsible_name', 'responsible_email', 'monthly_amount', 'one_time_amount']) || array_diff(array_keys($core), ['service_codes', 'issued_on', 'sales_responsible', 'monthly_final', 'one_time_final', 'recurring_total'])) {
                throw new RuntimeException('Campos fora do escopo: '.$id);
            }
            $originalMeta = $meta;
            $originalCore = $core;
            foreach (['meta', 'core'] as $side) {
                $fields = $side === 'meta' ? $originalMeta : $originalCore;
                $record = $side === 'meta' ? $m : $p;
                foreach ($fields as $key => $value) {
                    if ($key === 'services' || $key === 'service_codes') {
                        $old = json_decode((string) ($record->$key ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
                        if (! empty($old) || ! is_array($value) || $value === [] || array_diff($value, $validServices)) {
                            throw new RuntimeException('Serviço anterior ou inválido: '.$id);
                        }
                        $other = $key === 'services' ? json_decode((string) ($p->service_codes ?? 'null'), true) : json_decode((string) ($m->services ?? 'null'), true);
                        if (! empty($other) && array_diff($other, $value)) {
                            throw new RuntimeException('Classificação anterior incompatível: '.$id);
                        }
                        if ($side === 'meta') {
                            $meta[$key] = v10Json($value);
                        } else {
                            $core[$key] = v10Json($value);
                        }
                    } elseif ($key === 'issued_on') {
                        if ($record->$key !== null || ! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || ($audit['issue']['date'] ?? null) !== $value || ! in_array($audit['issue']['state'] ?? '', ['identified', 'explicit_with_other_dates'], true)) {
                            throw new RuntimeException('Emissão anterior ou inválida: '.$id);
                        }
                        foreach ([$m->issued_on, $p->issued_on] as $old) {
                            if ($old !== null && substr((string) $old, 0, 10) !== $value) {
                                throw new RuntimeException('Data anterior divergente: '.$id);
                            }
                        }
                    } elseif (in_array($key, ['responsible_name', 'responsible_email', 'sales_responsible'], true)) {
                        $sig = $audit['signature'] ?? [];
                        $expected = $key === 'responsible_email' ? ($sig['email'] ?? null) : ($sig['name'] ?? null);
                        if (! $blank($record->$key) || ! is_string($value) || trim($value) === '' || mb_strlen($value) > 160 || $value !== $expected || ($key === 'responsible_email' && (! filter_var($value, FILTER_VALIDATE_EMAIL) || ! str_ends_with(strtolower($value), '@exportcontrol.com.br')))) {
                            throw new RuntimeException('Assinatura anterior ou inválida: '.$id);
                        }
                    } elseif (in_array($key, ['monthly_amount', 'monthly_final', 'one_time_amount', 'one_time_final'], true)) {
                        $isMonthly = str_starts_with($key, 'monthly_');
                        $expected = $price[$isMonthly ? 'monthly' : 'one_time'] ?? null;
                        if ($record->$key !== null || $value === null || ! v10Money($value) || $expected !== $value || ! in_array($price['state'] ?? '', ['identified', 'rounding'], true)) {
                            throw new RuntimeException('Valor anterior ou inválido: '.$id);
                        }
                        $previous = $isMonthly ? [$m->monthly_amount, $p->monthly_final] : [$m->one_time_amount, $p->one_time_final];
                        foreach ($previous as $old) {
                            if ($old === null || (string) $old === $value) {
                                continue;
                            }
                            if (($price['state'] ?? '') !== 'rounding' || ! v10Money((string) $old) || abs((int) str_replace('.', '', (string) $old) - (int) str_replace('.', '', $value)) > 1) {
                                throw new RuntimeException('Valor financeiro anterior divergente: '.$id);
                            }
                        }
                    } elseif ($key === 'recurring_total') {
                        if ($record->$key !== null || ! is_int($value) || $value < 0 || $value > 2147483647 || (string) ($audit['volumes']['annual_quantity'] ?? '') !== (string) $value) {
                            throw new RuntimeException('Volume anterior ou inválido: '.$id);
                        }
                    }
                }
            }
            $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            // Only the new namespace is written, never older CRM/identity/price projections.
            $oldEvidence['review_v13'] = $audit;
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
        echo "V13 concluída. Histórico, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
