<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class EcProposalAdditionalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $folder = resource_path('proposals/official');
            $adjustments = json_decode((string) file_get_contents($folder.'/26_CONTROLE_REAJUSTES_ANUAIS.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($adjustments['reajustes'] as $event) {
                $existing = DB::table('ec_proposal_adjustments')->where('event_key', $event['id'])->first();
                if ($existing !== null && ((float) $existing->percentage !== (float) $event['percentual'] || (string) $existing->effective_from !== $event['vigencia_inicio'])) {
                    throw new \RuntimeException('Reajuste local diverge da fonte oficial. Não sobrescrito.');
                }
                if ($existing === null) {
                    if (DB::table('ec_proposal_adjustments')->where('effective_from', $event['vigencia_inicio'])->where('percentage', $event['percentual'])->exists()) {
                        throw new \RuntimeException('Reajuste equivalente já cadastrado com outro ID; confira antes de duplicar.');
                    }
                    DB::table('ec_proposal_adjustments')->insert(['event_key' => $event['id'], 'effective_from' => $event['vigencia_inicio'], 'percentage' => $event['percentual'], 'reason' => $event['motivo'], 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach (['DFE' => ['27_PRECOS_DFE.json', '27_MODELO_PROPOSTA_DFE.html', 'Documentos Fiscais Eletrônicos (DF-e)'], 'NFSE' => ['28_PRECOS_NFSE.json', '28_MODELO_PROPOSTA_NFSE.html', 'Notas Fiscais de Serviço (NFS-e)']] as $service => [$file, $template, $name]) {
                $cfg = json_decode((string) file_get_contents($folder.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
                if (! is_file($folder.'/'.$template)) {
                    throw new \RuntimeException('Modelo ausente: '.$template);
                }
                $existing = DB::table('ec_proposal_price_versions')->where('service', $service)->where('version', $cfg['versao'])->first();
                if ($existing !== null && (json_decode((string) $existing->configuration, true, 512, JSON_THROW_ON_ERROR) != $cfg || ! $existing->approved)) {
                    throw new \RuntimeException('Versão existente não corresponde ao catálogo aprovado: '.$service);
                }
                if ($existing === null) {
                    DB::table('ec_proposal_price_versions')->insert(['service' => $service, 'version' => $cfg['versao'], 'effective_from' => '2026-11-01', 'configuration' => json_encode($cfg, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'approved' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                $row = DB::table('ec_proposal_templates')->where('service', $service)->first();
                $description = 'Modelo Clean Teal conforme proposta comercial de 23/11/2026. Preços líquidos versionados; gross-up na cotação.';
                if ($row === null) {
                    DB::table('ec_proposal_templates')->insert(['service' => $service, 'name' => $name, 'source_file' => $template, 'description' => $description, 'ready_to_issue' => true, 'created_at' => now(), 'updated_at' => now()]);
                } elseif (! $row->ready_to_issue && $row->source_file === null) {
                    $update = ['source_file' => $template, 'ready_to_issue' => true, 'updated_at' => now()];
                    if (in_array($row->description, [null, '', 'Catálogo e histórico disponíveis. Modelos e precificação aguardam aprovação.', 'Catálogo, histórico, modelo oficial e tabela comercial aprovados.'], true)) {
                        $update['description'] = $description;
                    }
                    DB::table('ec_proposal_templates')->where('id', $row->id)->update($update);
                } elseif (! $row->ready_to_issue || $row->source_file !== $template) {
                    throw new \RuntimeException('Modelo local personalizado; preservado: '.$service);
                }
            }
        });
    }
}
