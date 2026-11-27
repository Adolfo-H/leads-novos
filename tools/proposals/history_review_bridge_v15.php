<?php

// Correção documental pontual. CLI local; não envia e-mails nem acessa CRM.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function v15Json(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function v15Hash(mixed $value): string
{
    return hash('sha256', v15Json($value));
}

function v15Read($disk, string $path): array
{
    $data = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($data)) {
        throw new RuntimeException('JSON inválido: '.$path);
    }

    return $data;
}

function v15Write($disk, string $path, array $data): void
{
    if ($disk->exists($path) || ! $disk->put($path, v15Json($data))) {
        throw new RuntimeException('Não foi possível criar '.$path);
    }
    chmod($disk->path($path), 0600);
}

function v15Amount(mixed $amount): bool
{
    return $amount === null || (is_string($amount) && preg_match('/^\d{1,9}\.\d{2}$/D', $amount) === 1);
}

function v15Catalog(array $catalog): array
{
    if (($catalog['version'] ?? null) !== 15 || ! is_array($catalog['records'] ?? null) || count($catalog['records']) !== 15) {
        throw new RuntimeException('Catálogo V15 inválido.');
    }
    $out = [];
    $newCount = 0;
    foreach ($catalog['records'] as $r) {
        $hash = $r['sha256'] ?? '';
        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || isset($out[$hash]) || ($r['source'] ?? null) !== 'conferencia_documental_sha256' || ($r['version'] ?? null) !== 15
            || ! in_array($r['kind'] ?? '', ['fixo', 'periodos', 'simulacoes', 'divergencia', 'condicoes'], true) || ! is_array($r['scenarios'] ?? null) || $r['scenarios'] === [] || ! v15Amount($r['principal'] ?? null) || ! v15Amount($r['setup_amount'] ?? null)) {
            throw new RuntimeException('Registro inválido no catálogo.');
        }
        if (! in_array($r['batch'] ?? '', ['V14', 'V15'], true) || ! in_array($r['source_format'] ?? '', ['PDF', 'Word (DOCX)'], true) || ! in_array($r['setup_mode'] ?? '', ['conditional', 'additional', 'not_informed'], true) || ! is_string($r['filename'] ?? null)) {
            throw new RuntimeException('Origem documental inválida.');
        }
        if ($r['batch'] === 'V15') {
            $newCount++;
        }
        if (($r['setup_mode'] === 'not_informed') !== ($r['setup_amount'] === null)) {
            throw new RuntimeException('Implantação inconsistente.');
        }
        if (in_array($r['kind'], ['simulacoes', 'divergencia', 'condicoes'], true) && $r['principal'] !== null) {
            throw new RuntimeException('Não se pode presumir preço único para alternativas.');
        }
        foreach ($r['scenarios'] as $s) {
            if (! is_string($s['label'] ?? null) || ! v15Amount($s['service'] ?? null) || ! v15Amount($s['setup'] ?? null) || ! v15Amount($s['total'] ?? null)) {
                throw new RuntimeException('Cenário inválido.');
            }
        }
        foreach ($r['scenarios'] as $scenario) {
            if (! is_string($scenario['locator'] ?? null) || ! is_string($scenario['service'] ?? null)) {
                throw new RuntimeException('Fonte ou preço inválido.');
            }
            if ($scenario['total'] !== null && (int) str_replace('.', '', $scenario['total']) !== (int) str_replace('.', '', $scenario['service']) + (int) str_replace('.', '', $scenario['setup'] ?? '0.00')) {
                throw new RuntimeException('Total do cenário inconsistente.');
            }
        }
        $out[$hash] = $r;
    }

    if ($newCount !== 7) {
        throw new RuntimeException('O catálogo deve conter sete documentos novos.');
    }

    return $out;
}

/** Match exclusively one imported document by exact SHA; skip ambiguity. */
function v15Plan($disk, array $catalog, bool $lock): array
{
    $rows = [];
    $skipped = [];
    $matchedIds = [];
    foreach ($catalog as $sha => $review) {
        $docs = DB::table('ec_proposal_documents')->where('sha256', $sha)
            ->whereIn('kind', ['historical_pdf', 'historical_original'])->get();
        if ($docs->count() !== 1) {
            $skipped[] = ['file' => $review['filename'], 'batch' => $review['batch'], 'reason' => 'SHA ausente ou associado a mais de um documento', 'matches' => $docs->count()];

            continue;
        }
        $doc = $docs->first();
        $id = (int) $doc->proposal_id;
        if (isset($matchedIds[$id])) {
            throw new RuntimeException('Mais de um documento do catálogo para a mesma proposta: '.$id);
        }
        $pQuery = DB::table('ec_proposals')->where('id', $id);
        $mQuery = DB::table('ec_proposal_history_metadata')->where('proposal_id', $id);
        if ($lock) {
            $pQuery->lockForUpdate();
            $mQuery->lockForUpdate();
        }
        $p = $pQuery->first();
        $m = $mQuery->first();
        $path = (string) $doc->storage_path;
        if (! $p || ! $m || $p->origin !== 'imported' || $m->document_sha256 !== $sha || DB::table('ec_proposal_documents')->where('proposal_id', $id)->whereIn('kind', ['historical_pdf', 'historical_original'])->count() !== 1 || ! $disk->exists($path) || hash_file('sha256', $disk->path($path)) !== $sha) {
            $skipped[] = ['file' => $review['filename'], 'batch' => $review['batch'], 'reason' => 'Vínculo, origem ou integridade do arquivo local não confirmados'];

            continue;
        }
        $matchedIds[$id] = true;
        $evidence = json_decode((string) $m->evidence, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($evidence)) {
            throw new RuntimeException('Evidência anterior inválida: '.$id);
        }
        $core = [];
        $meta = [];
        // Only unequivocal single service or full-scope quoted amount. Do not
        // replace a previously recorded conflicting amount, or infer acceptance.
        $principal = $review['principal'];
        $review['preserved_amounts'] = [];
        if ($principal !== null) {
            $old = [$p->one_time_final, $m->one_time_amount];
            $conflict = false;
            foreach ($old as $v) {
                if ($v !== null && (! is_numeric($v) || number_format((float) $v, 2, '.', '') !== $principal)) {
                    $conflict = true;
                    $review['preserved_amounts'][] = (string) $v;
                }
            }
            if (! $conflict) {
                if ($p->one_time_final === null) {
                    $core['one_time_final'] = $principal;
                }
                if ($m->one_time_amount === null) {
                    $meta['one_time_amount'] = $principal;
                }
            }
        } else {
            foreach ([$p->one_time_final, $m->one_time_amount] as $v) {
                if ($v !== null) {
                    $review['preserved_amounts'][] = (string) $v;
                }
            }
        }
        $review['preserved_amounts'] = array_values(array_unique($review['preserved_amounts']));
        if ($p->monthly_final !== null || $m->monthly_amount !== null) {
            $review['notes'][] = 'Existe valor mensal anterior no cadastro; preservado para auditoria. Este documento descreve prestação de serviço pontual.';
        }
        if (($evidence['review_v15'] ?? null) !== $review) {
            $evidence['review_v15'] = $review;
            $meta['evidence'] = v15Json($evidence);
        }
        $rows[] = ['id' => $id, 'sha' => $sha, 'file' => $review['filename'], 'batch' => $review['batch'], 'core_hash' => v15Hash($p), 'meta_hash' => v15Hash($m), 'core' => $core, 'meta' => $meta, 'review' => $review];
    }

    return ['version' => 15, 'rows' => $rows, 'skipped' => $skipped];
}

$disk = Storage::disk('local');
$mode = $argv[1] ?? '';
$folder = $argv[2] ?? '';
$confirm = $argv[3] ?? '';
try {
    if (preg_match('~^proposals/history-review-v15/[A-Za-z0-9_-]+$~D', $folder) !== 1) {
        throw new RuntimeException('Pasta privada inválida.');
    }
    if ($mode === 'restore') {
        if ($confirm !== 'RESTAURAR_HISTORICO_V15') {
            throw new RuntimeException('Confirmação inválida.');
        }
        $backup = v15Read($disk, $folder.'/before.json');
        $after = v15Read($disk, $folder.'/after.json');
        DB::transaction(function () use ($backup, $after): void {
            foreach ($backup as $id => $old) {
                $p = DB::table('ec_proposals')->where('id', $id)->lockForUpdate()->first();
                $m = DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->lockForUpdate()->first();
                if (! $p || ! $m || ($after[$id]['core'] ?? null) !== v15Hash($p) || ($after[$id]['meta'] ?? null) !== v15Hash($m)) {
                    throw new RuntimeException('Registro alterado desde V15: '.$id.'. Restauração cancelada.');
                }
                if ($old['core'] !== []) {
                    DB::table('ec_proposals')->where('id', $id)->update($old['core']);
                }
                if ($old['meta'] !== []) {
                    DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->update($old['meta']);
                }
            }
        });
        echo "Dados restaurados.\n";
        exit(0);
    }
    $catalog = v15Catalog(v15Read($disk, $folder.'/catalogo.json'));
    if ($mode === 'check') {
        $plan = v15Plan($disk, $catalog, false);
        v15Write($disk, $folder.'/plan.json', $plan);
        foreach ($plan['rows'] as $r) {
            echo 'CONFERIDA '.$r['batch'].' #'.$r['id'].' | '.$r['file'].' | '.$r['review']['principal_label']."\n";
        }
        foreach ($plan['skipped'] as $s) {
            echo 'NÃO APLICADA '.$s['batch'].' | '.$s['file'].' | '.$s['reason']."\n";
        }
        echo count(array_filter($plan['rows'], static fn (array $r): bool => $r['batch'] === 'V15'))."/7 documentos novos localizados.\n";
        echo count($plan['rows'])."/15 localizadas no catálogo acumulado. Simulação: nenhum dado gravado no banco.\n";
        exit(0);
    }
    if ($mode !== 'apply' || $confirm !== 'ATUALIZAR_HISTORICO_V15') {
        throw new RuntimeException('Modo ou confirmação inválida.');
    }
    $expected = v15Read($disk, $folder.'/plan.json');
    DB::transaction(function () use ($disk, $folder, $catalog, $expected): void {
        $plan = v15Plan($disk, $catalog, true);
        if (v15Hash($plan) !== v15Hash($expected)) {
            throw new RuntimeException('Dados alterados desde a simulação. Execute novamente.');
        }
        $before = [];
        $after = [];
        foreach ($plan['rows'] as $r) {
            $id = $r['id'];
            $core = $r['core'];
            $meta = $r['meta'];
            if ($core === [] && $meta === []) {
                continue;
            }
            if ($core !== []) {
                $core['updated_at'] = now('UTC');
            }
            if ($meta !== []) {
                $meta['updated_at'] = now('UTC');
            }
            $p = DB::table('ec_proposals')->where('id', $id)->first();
            $m = DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->first();
            $before[$id] = ['core' => array_intersect_key((array) $p, $core), 'meta' => array_intersect_key((array) $m, $meta)];
        }
        if ($before === []) {
            echo "Nenhuma atualização necessária.\n";

            return;
        }
        v15Write($disk, $folder.'/before.json', $before);
        foreach ($plan['rows'] as $r) {
            $id = $r['id'];
            if (! isset($before[$id])) {
                continue;
            }
            if ($r['core'] !== []) {
                DB::table('ec_proposals')->where('id', $id)->update($r['core'] + ['updated_at' => now('UTC')]);
            }
            if ($r['meta'] !== []) {
                DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->update($r['meta'] + ['updated_at' => now('UTC')]);
            }
            $after[$id] = ['core' => v15Hash(DB::table('ec_proposals')->where('id', $id)->first()), 'meta' => v15Hash(DB::table('ec_proposal_history_metadata')->where('proposal_id', $id)->first())];
        }
        v15Write($disk, $folder.'/after.json', $after);
        echo count($before)." registro(s) atualizado(s), sem alterar aceite, numeração, documentos ou CRM.\n";
    });
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
