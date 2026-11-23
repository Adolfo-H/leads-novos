<?php

// EC_PROPOSALS_OD_IMPORT_V1 - importacao historica sem SMTP.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Exporta exclusivamente o espelho local do HubSpot. Nenhuma chamada nem escrita no CRM. */
final class ExportProposalHubSpotDeals extends Command
{
    protected $signature = 'proposals:onedrive-deals {--output=proposals/import-inventory/negocios-hubspot.csv}';

    protected $description = 'Exportar negócios e empresas do HubSpot local para conciliar PDFs do OneDrive';

    public function handle(): int
    {
        $out = $this->option('output');
        if (! is_string($out) || preg_match('~^proposals/import-inventory/[A-Za-z0-9_.-]+\.csv$~D', $out) !== 1) {
            $this->error('O arquivo de saída deve ficar em proposals/import-inventory/<nome>.csv.');

            return self::FAILURE;
        }

        Storage::disk('local')->makeDirectory('proposals/import-inventory');
        $fp = fopen(Storage::disk('local')->path($out), 'wb');
        if ($fp === false) {
            $this->error('Não foi possível criar o CSV privado.');

            return self::FAILURE;
        }

        try {
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, ['id_externo', 'id_local', 'negocio', 'etapa', 'pipeline', 'empresa', 'id_empresa_externo', 'id_empresa_local', 'vinculo_principal'], ';', '"', '');
            $rows = DB::table('hubspot_deals as d')
                ->leftJoin('hubspot_company_deal as cd', 'cd.hubspot_deal_id', '=', 'd.id')
                ->leftJoin('hubspot_companies as c', 'c.id', '=', 'cd.hubspot_company_id')
                ->select('d.hubspot_id as id_externo', 'd.id as id_local', 'd.name as negocio',
                    'd.stage_label as etapa', 'd.pipeline_label as pipeline', 'c.name as empresa',
                    'c.hubspot_id as id_empresa_externo', 'c.id as id_empresa_local',
                    'cd.is_primary as vinculo_principal')
                ->orderBy('d.id')->orderBy('c.id')->get();
            foreach ($rows as $row) {
                fputcsv($fp, [
                    (string) $row->id_externo, (string) $row->id_local, (string) $row->negocio,
                    (string) $row->etapa, (string) $row->pipeline, (string) ($row->empresa ?? ''),
                    (string) ($row->id_empresa_externo ?? ''), (string) ($row->id_empresa_local ?? ''),
                    $row->vinculo_principal ? 'SIM' : 'NAO',
                ], ';', '"', '');
            }
        } finally {
            fclose($fp);
        }

        $this->info('Exportação somente leitura concluída: '.count($rows).' relações de negócios/empresas.');
        $this->line('Arquivo privado: storage/app/private/'.$out);
        $this->warn('Etapa comercial do HubSpot NÃO confirma aceite de proposta.');

        return self::SUCCESS;
    }
}
