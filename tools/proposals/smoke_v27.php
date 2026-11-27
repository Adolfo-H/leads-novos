<?php

// Read-only local smoke check; never issue, reserve or send.
use App\Services\Proposals\ProposalPricing;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pricing = app(ProposalPricing::class);
$before = DB::table('ec_proposal_numbering')->where('id', 1)->first();
$result = $pricing->quote(['service_codes' => ['DFE', 'NFSE'], 'price_date' => now('America/Sao_Paulo')->toDateString(), 'dfe_cnpj_count' => 3, 'dfe_total' => 790, 'nfse_cnpj_count' => 3, 'nfse_total' => 123]);
$tables = $pricing->tables(now('America/Sao_Paulo')->toDateString());
$after = DB::table('ec_proposal_numbering')->where('id', 1)->first();
if ($before != $after || count($tables['effective']) !== 5) {
    throw new RuntimeException('Numeração alterada concorrentemente ou tabela ausente.');
}
echo json_encode(['next_number' => $after->next_number ?? null, 'reconciled' => ! empty($after->reconciled_at), 'services' => array_keys($tables['effective']), 'adjustments' => $tables['adjustment_audit']['reajustes_aplicados'], 'tax_rates' => $tables['tax_rates'], 'example_DFE' => $result['items']['DFE']['final'], 'example_NFSE' => $result['items']['NFSE']['final']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
