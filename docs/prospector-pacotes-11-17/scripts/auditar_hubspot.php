<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Diagnóstico agregador SOMENTE LEITURA, sem nomes/IDs externos/tokens. */
if (! Schema::hasTable('company_hubspot_leads')) {
    echo "HubSpot: tabela de sincronização não encontrada nesta conexão.\n";

    return;
}

$table = DB::table('company_hubspot_leads');
echo "==== HUBSPOT — SOMENTE LEITURA ====\n";
echo 'Driver: '.DB::connection()->getDriverName()."\n";
echo 'Leads locais: '.(clone $table)->count()."\n";
echo 'Sem empresa remota: '.(clone $table)->whereNull('hubspot_company_id')->count()."\n";
echo 'Sem negócio remoto: '.(clone $table)->whereNull('hubspot_deal_id')->count()."\n";
echo 'Com negócio remoto: '.(clone $table)->whereNotNull('hubspot_deal_id')->count()."\n";
echo 'Com erro local: '.(clone $table)->whereNotNull('sync_error')->where('sync_error', '<>', '')->count()."\n";

$dupQuery = DB::table('company_hubspot_leads')
    ->whereNotNull('hubspot_deal_id')
    ->groupBy('hubspot_deal_id')
    ->havingRaw('COUNT(*) > 1')
    ->selectRaw('hubspot_deal_id');
echo 'IDs remotos repetidos no cadastro local: '.DB::query()->fromSub($dupQuery, 'duplicados')->count()."\n";

if (Schema::hasTable('hubspot_webhook_events')) {
    echo 'Eventos webhook locais: '.DB::table('hubspot_webhook_events')->count()."\n";
}
echo "Nenhuma chamada à API HubSpot foi realizada.\n";
