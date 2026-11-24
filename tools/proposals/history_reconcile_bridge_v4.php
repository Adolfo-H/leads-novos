<?php

// CLI administrativo local. Nunca atualiza o HubSpot remoto nem o fluxo de emissão.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function v4Json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function v4Hash($value): string
{
    return hash('sha256', v4Json($value));
}
function v4Object($value): array
{
    if (is_string($value)) {
        $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    return is_array($value) ? $value : [];
}
function v4Crm(): array
{
    $companyFields = ['id', 'hubspot_id', 'name'];
    $identityKeys = ['cnpj', 'tax_id', 'document_number', 'cnpj_number', 'document', 'cnpj_da_empresa', 'cnpj_empresa'];
    foreach (array_merge($identityKeys, ['raw_properties']) as $key) {
        if (Schema::hasColumn('hubspot_companies', $key)) {
            $companyFields[] = $key;
        }
    }
    $companies = DB::table('hubspot_companies')->orderBy('id')->get($companyFields)->map(function ($c) use ($identityKeys) {
        $raw = v4Object($c->raw_properties ?? null);
        $props = v4Object($raw['properties'] ?? $raw);
        $c->raw_properties = array_intersect_key($props, array_flip($identityKeys));

        return $c;
    })->all();
    $dealFields = ['id', 'hubspot_id', 'name', 'stage_label'];
    foreach (['hubspot_created_at', 'closed_at', 'archived', 'stage_id', 'pipeline_label'] as $key) {
        if (Schema::hasColumn('hubspot_deals', $key)) {
            $dealFields[] = $key;
        }
    }
    $deals = DB::table('hubspot_deals')->orderBy('id')->get($dealFields)->all();
    $links = DB::table('hubspot_company_deal')->orderBy('hubspot_company_id')->orderBy('hubspot_deal_id')->get(['hubspot_company_id', 'hubspot_deal_id', 'is_primary'])->all();
    $contacts = [];
    if (Schema::hasTable('hubspot_company_contact') && Schema::hasTable('hubspot_contacts') && Schema::hasColumn('hubspot_contacts', 'email')) {
        $contacts = DB::table('hubspot_company_contact as cc')->join('hubspot_contacts as c', 'c.id', '=', 'cc.hubspot_contact_id')
            ->whereNotNull('c.email')->orderBy('cc.hubspot_company_id')->orderBy('c.id')->get(['cc.hubspot_company_id', 'c.email'])->all();
    }
    $crm = compact('companies', 'deals', 'links', 'contacts');

    return $crm + ['crm_fingerprint' => v4Hash($crm)];
}
function v4Write($disk, string $relative, $data): void
{
    if ($disk->exists($relative) || ! $disk->put($relative, v4Json($data))) {
        throw new RuntimeException('Não foi possível guardar novo relatório privado: '.$relative);
    }
    chmod($disk->path($relative), 0600);
}
function v4Current(string $table, string $idKey, array $ids, bool $lock): Collection
{
    $q = DB::table($table)->whereIn($idKey, $ids)->orderBy($idKey);
    if ($lock) {
        $q->lockForUpdate();
    }

    return $q->get()->keyBy($idKey);
}

$mode = $argv[1] ?? '';
$relative = $argv[2] ?? '';
$confirm = $argv[3] ?? '';
if (preg_match('~^proposals/history-reconcile-v4/[A-Za-z0-9_-]+/(export|review)\.json$~D', $relative) !== 1) {
    fwrite(STDERR, "Caminho privado inválido.\n");
    exit(1);
}
$disk = Storage::disk('local');
$folder = dirname($relative);
$metaTable = 'ec_proposal_history_metadata';
$coreTable = 'ec_proposals';
$coreFields = ['client_contact', 'client_email', 'client_cnpj', 'sales_responsible', 'issued_on', 'service_codes', 'monthly_final', 'one_time_final', 'recurring_total', 'retro_total'];
try {
    if (! Schema::hasTable($metaTable)) {
        throw new RuntimeException('Enriquecimento V3 necessário.');
    }
    if ($mode === 'export') {
        $proposals = DB::table($coreTable)->where('origin', 'imported')->orderBy('id')->get();
        $ids = $proposals->pluck('id');
        $metadata = DB::table($metaTable)->whereIn('proposal_id', $ids)->orderBy('proposal_id')->get();
        foreach ($proposals as $p) {
            $p->fingerprint = v4Hash($p);
        }
        foreach ($metadata as $m) {
            $m->fingerprint = v4Hash($m);
        }
        $documents = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'sha256', 'storage_path']);
        v4Write($disk, $relative, compact('proposals', 'metadata', 'documents') + v4Crm());
        echo 'Históricos exportados: '.$proposals->count()."\n";
        exit(0);
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_CONCILIACAO_V4') {
            throw new RuntimeException('Confirmação de restauração necessária.');
        }
        $before = v4Object($disk->get($folder.'/before.json'));
        $after = v4Object($disk->get($folder.'/after.json'));
        if (! $before || count($before) !== count($after)) {
            throw new RuntimeException('Backup incompleto.');
        }
        DB::transaction(function () use ($before, $after, $metaTable, $coreTable): void {
            $ids = array_keys($before);
            $ps = v4Current($coreTable, 'id', $ids, true);
            $ms = v4Current($metaTable, 'proposal_id', $ids, true);
            foreach ($before as $id => $old) {
                if (! $ps->has($id) || ! $ms->has($id) || ($after[$id]['core'] ?? null) !== v4Hash($ps->get($id)) || ($after[$id]['meta'] ?? null) !== v4Hash($ms->get($id))) {
                    throw new RuntimeException('Registro alterado após V4: '.$id.'. Restauração cancelada.');
                }
            }
            foreach ($before as $id => $old) {
                if ($old['core']) {
                    DB::table($coreTable)->where('id', $id)->update($old['core']);
                }
                DB::table($metaTable)->where('proposal_id', $id)->update($old['meta']);
            }
        });
        echo "Metadados e campos anteriores restaurados.\n";
        exit(0);
    }
    if (! in_array($mode, ['check', 'apply'], true) || ($mode === 'apply' && $confirm !== 'CONCILIAR_HISTORICO_V4')) {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $plan = v4Object($disk->get($relative));
    if (($plan['version'] ?? null) !== 4 || empty($plan['rows']) || ! is_array($plan['rows'])) {
        throw new RuntimeException('Plano vazio ou inválido.');
    }
    DB::transaction(function () use ($mode, $plan, $disk, $folder, $metaTable, $coreTable, $coreFields): void {
        $crm = v4Crm();
        if ($crm['crm_fingerprint'] !== ($plan['crm_fingerprint'] ?? null)) {
            throw new RuntimeException('Espelho HubSpot mudou após a simulação. Gere novo lote.');
        }
        $company = collect($crm['companies'])->keyBy('id');
        $deal = collect($crm['deals'])->keyBy('id');
        $pairs = [];
        foreach ($crm['links'] as $l) {
            $pairs[(int) $l->hubspot_deal_id][(int) $l->hubspot_company_id] = (bool) $l->is_primary;
        }
        $ids = array_column($plan['rows'], 'proposal_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('Proposta duplicada no plano.');
        }
        $ps = v4Current($coreTable, 'id', $ids, $mode === 'apply');
        $ms = v4Current($metaTable, 'proposal_id', $ids, $mode === 'apply');
        $docs = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->get(['proposal_id', 'sha256', 'storage_path'])->groupBy('proposal_id');
        $before = [];
        $updates = [];
        foreach ($plan['rows'] as $r) {
            $id = (int) $r['proposal_id'];
            $p = $ps->get($id);
            $m = $ms->get($id);
            $dd = $docs->get($id);
            if (! $p || ! $m || $p->origin !== 'imported' || v4Hash($p) !== $r['expected_core'] || v4Hash($m) !== $r['expected_meta'] || ! $dd || $dd->count() !== 1) {
                throw new RuntimeException('Registro alterado após a simulação: '.$id);
            }
            $doc = $dd->first();
            if ($m->document_sha256 !== $r['document_sha256'] || $doc->sha256 !== $r['document_sha256'] || ! $disk->exists($doc->storage_path) || hash_file('sha256', $disk->path($doc->storage_path)) !== $doc->sha256) {
                throw new RuntimeException('Documento ausente ou alterado: '.$id);
            }
            $cid = $r['company_id'];
            $did = $r['deal_id'];
            $match = $r['match'];
            if (! is_string($match) || strlen($match) > 48 || ($cid !== null && (! is_int($cid) || ! $company->has($cid)))) {
                throw new RuntimeException('Empresa ou critério inválido: '.$id);
            }
            if ($did !== null) {
                if (! is_int($did) || ! $deal->has($did) || $cid === null || ! array_key_exists($cid, $pairs[$did] ?? [])) {
                    throw new RuntimeException('Negócio não associado à empresa: '.$id);
                }
                if (! in_array($match, ['manual_confirmed', 'existing_core'], true) && count($pairs[$did]) !== 1 && ! ($pairs[$did][$cid] && count(array_filter($pairs[$did])) === 1)) {
                    throw new RuntimeException('Negócio sem associação comercial principal: '.$id);
                }
                if (($deal->get($did)->stage_label ?? null) !== $r['stage']) {
                    throw new RuntimeException('Etapa alterada: '.$id);
                }
            }
            $status = $r['commercial_status'];
            if (! in_array($status, ['sent_history', 'accepted_crm'], true) || ($status === 'accepted_crm' && ($did === null || ! in_array(mb_strtoupper(Str::ascii((string) $r['stage'])), ['ACEITE DA PROPOSTA', 'CONTRATO ASSINADO', 'INICIO DO TESTE', 'NEGOCIO FECHADO'], true)))) {
                throw new RuntimeException('Situação CRM inválida: '.$id);
            }
            $fields = $r['fields'];
            if (! is_array($fields) || array_diff(array_keys($fields), $coreFields)) {
                throw new RuntimeException('Campos de cadastro inválidos.');
            }
            foreach ($fields as $k => $v) {
                if ($k === 'service_codes') {
                    if (v4Object($p->service_codes) !== [] || ! is_array($v) || array_diff($v, ['LICENCIAMENTO', 'ACOMPANHAMENTO', 'RETROATIVAS', 'DFE', 'NFSE'])) {
                        throw new RuntimeException('Serviços já preenchidos ou inválidos: '.$id);
                    }
                    $fields[$k] = v4Json($v);

                    continue;
                }
                if ($p->$k !== null && $p->$k !== '') {
                    throw new RuntimeException('Tentativa de sobrescrever campo preenchido: '.$id.'/'.$k);
                }
                if (in_array($k, ['monthly_final', 'one_time_final'], true) && (! is_string($v) || preg_match('/^\d{1,9}\.\d{2}$/D', $v) !== 1)) {
                    throw new RuntimeException('Valor financeiro inválido: '.$id);
                }
                if ($k === 'issued_on' && (! is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
                    throw new RuntimeException('Data inválida: '.$id);
                }
                if ($k === 'client_email' && (! is_string($v) || strlen($v) > 200 || ! filter_var($v, FILTER_VALIDATE_EMAIL))) {
                    throw new RuntimeException('E-mail inválido: '.$id);
                }
                if (in_array($k, ['client_contact', 'sales_responsible'], true) && (! is_string($v) || mb_strlen($v) > 160 || trim($v) === '')) {
                    throw new RuntimeException('Nome inválido: '.$id);
                }
                if ($k === 'client_cnpj' && (! is_string($v) || preg_match('/^\d{14}$/D', $v) !== 1)) {
                    throw new RuntimeException('CNPJ inválido: '.$id);
                }
                if (in_array($k, ['retro_total', 'recurring_total'], true) && (! is_int($v) || $v < 0 || $v > 2147483647)) {
                    throw new RuntimeException('Quantidade inválida: '.$id);
                }
            }
            if ($cid !== null && $p->hubspot_company_id === null) {
                $fields['hubspot_company_id'] = $cid;
            }
            if ($did !== null && $p->hubspot_deal_id === null) {
                $fields['hubspot_deal_id'] = $did;
            }
            if (($cid !== null && $p->hubspot_company_id !== null && (int) $p->hubspot_company_id !== $cid) || ($did !== null && $p->hubspot_deal_id !== null && (int) $p->hubspot_deal_id !== $did)) {
                throw new RuntimeException('Vínculo existente divergente: '.$id);
            }
            $ev = v4Object($m->evidence);
            if (isset($r['payment_evidence'])) {
                if (! is_string($r['payment_evidence']) || mb_strlen($r['payment_evidence']) > 2400) {
                    throw new RuntimeException('Condições comerciais inválidas: '.$id);
                }
                $ev['conditions_v4'] = ['payment' => $r['payment_evidence'], 'source' => 'document_original'];
            }
            $ev['crm_v4'] = ['version' => 4, 'company_reason' => $r['company_reason'], 'match' => $match, 'company_candidates' => $r['company_candidates'], 'deal_candidates' => $r['deal_candidates'], 'generic_candidate' => $r['generic_candidate'], 'manual' => $match === 'manual_confirmed'];
            // Keep existing extraction and previous source evidence intact.
            $hist = ['crm_company_id' => $cid, 'crm_deal_id' => $did, 'crm_stage' => $r['stage'], 'crm_match' => $match, 'commercial_status' => $status, 'evidence' => v4Json($ev), 'updated_at' => now('UTC')];
            if ($fields !== []) {
                $fields['updated_at'] = now('UTC');
            }
            $before[$id] = ['core' => array_intersect_key((array) $p, $fields), 'meta' => array_intersect_key((array) $m, $hist)];
            $updates[$id] = ['core' => $fields, 'meta' => $hist];
        }
        echo 'Validados: '.count($updates)." registros.\n";
        if ($mode === 'check') {
            echo "SIMULAÇÃO: banco e documentos intactos.\n";

            return;
        }
        v4Write($disk, $folder.'/before.json', $before);
        foreach ($updates as $id => $u) {
            if ($u['core'] !== []) {
                DB::table($coreTable)->where('id', $id)->update($u['core']);
            }
            DB::table($metaTable)->where('proposal_id', $id)->update($u['meta']);
        }
        $currentP = v4Current($coreTable, 'id', $ids, false);
        $currentM = v4Current($metaTable, 'proposal_id', $ids, false);
        $after = [];
        foreach ($ids as $id) {
            $after[$id] = ['core' => v4Hash($currentP->get($id)), 'meta' => v4Hash($currentM->get($id))];
        }
        v4Write($disk, $folder.'/after.json', $after);
    });
    if ($mode === 'apply') {
        echo "CONCILIAÇÃO V4 CONCLUÍDA. Somente cadastro histórico e projeção CRM atualizados.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
