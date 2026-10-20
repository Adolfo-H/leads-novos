<?php

use App\Services\IcpScoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Diagnóstico SOMENTE LEITURA para Tinker.
 * Não imprime nomes, CNPJs, contatos nem fatores individuais.
 */
if (! Schema::hasTable('companies') || ! Schema::hasTable('company_icp_scores')) {
    echo "ICP: tabelas ainda não disponíveis nesta conexão.\n";

    return;
}

echo "==== ICP — SOMENTE LEITURA ====\n";
echo 'Driver: '.DB::connection()->getDriverName()."\n";
echo 'Versão do código: '.IcpScoringService::VERSION."\n";
echo 'Empresas: '.DB::table('companies')->count()."\n";
echo 'Scores ICP: '.DB::table('company_icp_scores')->count()."\n";

$versions = DB::table('company_icp_scores')
    ->select('version')
    ->selectRaw('COUNT(*) AS quantidade')
    ->groupBy('version')
    ->orderBy('version')
    ->get();
foreach ($versions as $v) {
    $label = is_string($v->version) && trim($v->version) !== '' ? $v->version : '(sem versão)';
    echo 'Versão '.str_replace(["\n", "\r"], '', $label).': '.(int) $v->quantidade."\n";
}
$missing = DB::table('companies AS c')
    ->leftJoin('company_icp_scores AS s', 'c.id', '=', 's.company_id')
    ->whereNull('s.id')
    ->count();
echo 'Empresas sem score ICP: '.$missing."\n";

if (Schema::hasTable('establishments') && Schema::hasColumn('establishments', 'registration_status_code')) {
    $status = DB::table('establishments')
        ->select('registration_status_code')
        ->selectRaw('COUNT(*) AS quantidade')
        ->groupBy('registration_status_code')
        ->orderBy('registration_status_code')
        ->get();
    foreach ($status as $s) {
        $code = is_scalar($s->registration_status_code) ? trim((string) $s->registration_status_code) : '';
        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $code);
        echo 'Situação '.($safeCode !== '' ? $safeCode : '(vazio)').': '.(int) $s->quantidade."\n";
    }
}
echo "Nenhum registro foi alterado.\n";
