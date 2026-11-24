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
    if (preg_match('~^proposals/history-values-v11/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1 || ! Schema::hasTable($metaTable)) {
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
        if ($confirm !== 'RESTAURAR_HISTORICO_V11') {
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
                    throw new RuntimeException('Registro modificado depois da V11: '.$id.'. Restauração cancelada.');
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
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'ATUALIZAR_HISTORICO_V11')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v10Read($disk, $relative);
    if (($plan['version'] ?? null) !== 11 || ! is_array($plan['rows'] ?? null)) {
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
        $ps = v10Rows($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v10Rows($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        $validServices = ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'];
        foreach ($plan['rows'] as $r) {
            $id = (int) $r['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v10Hash($p) !== $r['expected_core'] || v10Hash($m) !== $r['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado desde a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($doc->sha256 !== $r['document_sha256'] || $m->document_sha256 !== $doc->sha256 || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento alterado ou ausente: '.$id);
            }
            $meta = $r['meta'];
            $core = $r['core'];
            if (! is_array($meta) || ! is_array($core)) {
                throw new RuntimeException('Campos inválidos: '.$id);
            }
            $audit = $r['evidence']['audit_v11'] ?? null;
            if (! is_array($audit) || ($audit['version'] ?? null) !== 11 || ($audit['source'] ?? '') !== 'document_sha256' || ! is_array($audit['issue'] ?? null) || ! is_array($audit['warnings'] ?? null)) {
                throw new RuntimeException('Auditoria V11 inválida: '.$id);
            }
            $monthlyAudit = $audit['monthly'] ?? null;
            if ($monthlyAudit !== null) {
                if (! is_array($monthlyAudit) || ! in_array($monthlyAudit['state'] ?? null, ['identified', 'conflict', 'declared_table_conflict', 'conditional_review', 'unresolved', 'previous_review'], true)) {
                    throw new RuntimeException('Estado mensal de auditoria inválido: '.$id);
                }
                foreach (['declared_values', 'table_values', 'net_values'] as $field) {
                    if (! is_array($monthlyAudit[$field] ?? null)) {
                        throw new RuntimeException('Evidência de valor inválida: '.$id);
                    }
                    foreach ($monthlyAudit[$field] as $value) {
                        if ($value === null || ! v10Money($value)) {
                            throw new RuntimeException('Valor de auditoria inválido: '.$id);
                        }
                    }
                }
                if (($monthlyAudit['state'] ?? '') === 'identified' && (($monthlyAudit['value'] ?? null) === null || ! v10Money($monthlyAudit['value']))) {
                    throw new RuntimeException('Preço recuperado inválido: '.$id);
                }
            }
            $dateMeta = [];
            $dateCore = [];
            foreach (['meta', 'core'] as $side) {
                $fields = $side === 'meta' ? $meta : $core;
                if (! array_key_exists('issued_on', $fields)) {
                    continue;
                }
                $value = $fields['issued_on'];
                if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || ($audit['issue']['state'] ?? null) !== 'identified' || ($audit['issue']['date'] ?? null) !== $value || ($audit['issue']['candidates'] ?? []) !== [$value]) {
                    throw new RuntimeException('Data de emissão sem evidência inequívoca: '.$id);
                }
                foreach ([$p->issued_on, $m->issued_on] as $oldDate) {
                    if ($oldDate !== null && substr((string) $oldDate, 0, 10) !== $value) {
                        throw new RuntimeException('Data de emissão já preenchida e divergente: '.$id);
                    }
                }
                if ($side === 'meta') {
                    if ($m->issued_on !== null) {
                        throw new RuntimeException('Data histórica já preenchida: '.$id);
                    }
                    $dateMeta['issued_on'] = $value;
                    unset($meta['issued_on']);
                } else {
                    if ($p->issued_on !== null) {
                        throw new RuntimeException('Data principal já preenchida: '.$id);
                    }
                    $dateCore['issued_on'] = $value;
                    unset($core['issued_on']);
                }
            }
            // Real conflicts are display-only evidence. Audit-only updates do not
            // revalidate or rewrite old financial/CRM fields.
            if (($r['audit_only'] ?? false) === true) {
                if ($meta !== [] || $core !== [] || array_keys($r['evidence']) !== ['audit_v11']) {
                    throw new RuntimeException('Auditoria tentou alterar campos não autorizados: '.$id);
                }
                $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
                $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
                $oldEvidence['audit_v11'] = $audit;
                $meta = array_merge($dateMeta, ['evidence' => v10Json($oldEvidence), 'updated_at' => now('UTC')]);
                $core = $dateCore;
                if ($core !== []) {
                    $core['updated_at'] = now('UTC');
                }
                $before[$id] = ['core' => array_intersect_key((array) $p, $core), 'meta' => array_intersect_key((array) $m, $meta)];
                $updates[$id] = compact('core', 'meta');

                continue;
            }
            if (is_array($monthlyAudit) && in_array($monthlyAudit['state'] ?? '', ['conflict', 'declared_table_conflict', 'conditional_review', 'previous_review'], true) && ($meta['monthly_amount'] ?? null) !== ($m->monthly_amount === null ? null : (string) $m->monthly_amount)) {
                throw new RuntimeException('Divergência documental não permite substituir mensalidade: '.$id);
            }
            if (! is_array($meta) || ! is_array($core) || array_diff(['monthly_amount', 'one_time_amount', 'services'], array_keys($meta)) || array_diff(array_keys($meta), ['monthly_amount', 'one_time_amount', 'services', 'responsible_name', 'responsible_email']) || array_diff(array_keys($core), ['monthly_final', 'one_time_final', 'retro_total', 'recurring_total', 'service_codes', 'sales_responsible'])) {
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
            if (! v10Money($meta['monthly_amount']) || ! v10Money($meta['one_time_amount']) || ($retro && $meta['monthly_amount'] !== null)) {
                throw new RuntimeException('Valores financeiros inválidos: '.$id);
            }
            if (($evidence['finance_v10']['version'] ?? null) !== 10 || ! is_array($f) || ($f['reader_version'] ?? null) !== 11 || ! v10Money($f['implantation_amount'] ?? null) || ! v10Money($f['conditional_total'] ?? null)) {
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
                    if (($component['billing'] ?? '') !== 'one_time' || $amount === null || ! v10Money($amount) || (! empty($component['bands']) && ($component['table_total'] ?? null) !== $amount)) {
                        throw new RuntimeException('Componente inválido: '.$id);
                    }
                    $componentCodes[] = $component['service'] ?? '';
                    $sum += (int) str_replace('.', '', $amount);
                }
                if (array_diff(['RETROATIVAS', 'ACOMPANHAMENTO'], $componentCodes) !== [] || $sum !== (int) str_replace('.', '', $meta['one_time_amount'])) {
                    throw new RuntimeException('Total dos serviços divergente: '.$id);
                }
            }
            $terms = $f['pricing_terms_v10'] ?? null;
            if ($terms !== null) {
                if (! is_array($terms) || ! in_array($terms['kind'] ?? '', ['one_time', 'percentage_addon'], true)) {
                    throw new RuntimeException('Condições comerciais inválidas: '.$id);
                }
                if ($terms['kind'] === 'percentage_addon') {
                    $rate = $terms['percent'] ?? null;
                    if (($f['billing_mode'] ?? '') !== 'percentage_addon' || ($f['monthly_state'] ?? '') !== 'formula' || $meta['monthly_amount'] !== null || $meta['one_time_amount'] === null || ($terms['development_amount'] ?? null) !== $meta['one_time_amount'] || ! is_string($rate) || preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D', $rate) !== 1 || (float) $rate <= 0 || (float) $rate > 100 || ! is_string($terms['condition'] ?? null) || trim($terms['condition']) === '') {
                        throw new RuntimeException('Regra percentual inválida: '.$id);
                    }
                } else {
                    $gross = $terms['gross'] ?? null;
                    $discount = $terms['discount_amount'] ?? null;
                    if ($meta['services'] !== ['RETROATIVAS'] || $gross === null || ! v10Money($gross) || ! v10Money($discount) || $meta['one_time_amount'] === null || ($f['service_amount'] ?? null) !== $meta['one_time_amount'] || (int) str_replace('.', '', $gross) - ($discount === null ? 0 : (int) str_replace('.', '', $discount)) !== (int) str_replace('.', '', $meta['one_time_amount'])) {
                        throw new RuntimeException('Composição do desconto inválida: '.$id);
                    }
                    $scenarios = $terms['scenarios'] ?? null;
                    if (! is_array($scenarios) || count($scenarios) > 4) {
                        throw new RuntimeException('Cenários inválidos: '.$id);
                    }
                    foreach ($scenarios as $scenario) {
                        $service = $scenario['service'] ?? null;
                        $fee = $scenario['implantation'] ?? null;
                        $total = $scenario['total'] ?? null;
                        if ($service === null || $total === null || ! v10Money($service) || ! v10Money($fee) || ! v10Money($total) || ! in_array($service, [$gross, $meta['one_time_amount']], true) || ($fee !== null && $fee !== ($f['implantation_amount'] ?? null)) || (int) str_replace('.', '', $service) + ($fee === null ? 0 : (int) str_replace('.', '', $fee)) !== (int) str_replace('.', '', $total)) {
                            throw new RuntimeException('Total de cenário inválido: '.$id);
                        }
                    }
                }
            }
            // Only finance namespaces change. Keep CRM V4/manual review, identity and any other evidence.
            $oldEvidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
            $oldEvidence = is_array($oldEvidence) ? $oldEvidence : [];
            $previousFinance = is_array($oldEvidence['finance_v2'] ?? null) ? $oldEvidence['finance_v2'] : [];
            foreach (['monthly_amount', 'one_time_amount'] as $key) {
                $oldValue = $m->$key === null ? null : (string) $m->$key;
                if ($oldValue !== null && $oldValue !== $meta[$key] && ! ($bundle && $key === 'monthly_amount' && $meta[$key] === null)) {
                    throw new RuntimeException('Valor histórico já preenchido e divergente: '.$id.'/'.$key);
                }
            }

            $oldEvidence['finance_v2'] = $f;
            $oldEvidence['finance_v10'] = $evidence['finance_v10'];
            $oldEvidence['audit_v11'] = $audit;
            $blank = static fn ($v): bool => $v === null || in_array(mb_strtolower(trim((string) $v)), ['', 'não identificado', 'não identificada', 'não informado', 'não informada', 'pendente', '—', '-'], true);
            if (array_key_exists('responsible_name', $meta) || array_key_exists('responsible_email', $meta) || array_key_exists('sales_responsible', $core)) {
                $signature = $evidence['identity_v10'] ?? null;
                if (! is_array($signature) || ($signature['source'] ?? '') !== 'assinatura_final_e_contato_comercial_da_capa' || ! is_string($signature['name'] ?? null) || mb_strlen($signature['name']) > 160 || ! is_string($signature['email'] ?? null) || ! filter_var($signature['email'], FILTER_VALIDATE_EMAIL) || ! str_ends_with(strtolower($signature['email']), '@exportcontrol.com.br')) {
                    throw new RuntimeException('Evidência da assinatura inválida: '.$id);
                }
                foreach (['responsible_name' => 'name', 'responsible_email' => 'email'] as $field => $key) {
                    if (array_key_exists($field, $meta) && (! $blank($m->$field) || $meta[$field] !== $signature[$key])) {
                        throw new RuntimeException('Responsável já informado ou divergente: '.$id);
                    }
                }
                if (array_key_exists('sales_responsible', $core) && (! $blank($p->sales_responsible) || $core['sales_responsible'] !== $signature['name'])) {
                    throw new RuntimeException('Responsável do cadastro já preenchido: '.$id);
                }
                $oldEvidence['identity_v10'] = $signature;
            }
            foreach ($core as $key => $value) {
                if (in_array($key, ['monthly_final', 'one_time_final'], true)) {
                    if (! v10Money($value) || ($retro && $key === 'monthly_final' && $value !== null)) {
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
                } elseif ($key === 'sales_responsible') {
                    // Validated together with signature evidence above.
                    continue;
                } elseif ($key === 'service_codes') {
                    $previous = json_decode((string) ($p->service_codes ?? 'null'), true, 512, JSON_THROW_ON_ERROR);
                    if (! empty($previous) || $value !== $meta['services']) {
                        throw new RuntimeException('Serviço já preenchido ou divergente: '.$id);
                    }
                    $core[$key] = v10Json($value);
                } elseif (! is_int($value) || $value < 0 || $value > 2147483647 || $p->$key !== null) {
                    throw new RuntimeException('Quantidade inválida ou já preenchida: '.$id);
                }
            }
            $meta = array_merge($meta, $dateMeta);
            $core = array_merge($core, $dateCore);
            $meta['services'] = v10Json($meta['services']);
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
        echo "V11 concluída. Propostas originais, CRM, aceite e numeração preservados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
