<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class EcProposalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $folder = resource_path('proposals/official');
        $services = [
            'LICENCIAMENTO' => ['06_PRECOS_LICENCIAMENTO.json', '10_MODELO_PROPOSTA_LICENCIAMENTO.html', 'Licenciamento da Plataforma'],
            'ACOMPANHAMENTO' => ['07_PRECOS_ACOMPANHAMENTO.json', '12_MODELO_PROPOSTA_ACOMPANHAMENTO.html', 'Acompanhamento Especializado'],
            'RETROATIVAS' => ['08_PRECOS_RETROATIVAS.json', '14_MODELO_PROPOSTA_RETROATIVAS.html', 'Consultas Retroativas'],
        ];
        foreach ($services as $service => [$priceFile, $templateFile, $label]) {
            $data = json_decode((string) file_get_contents($folder.'/'.$priceFile), true, 512, JSON_THROW_ON_ERROR);
            DB::table('ec_proposal_price_versions')->updateOrInsert(
                ['service' => $service, 'version' => (string) $data['versao']],
                // Atualizar APENAS se inexistente: nunca sobrescrever uma tabela aprovada após ajustes locais.
                ['service' => $service, 'version' => (string) $data['versao']] + (
                    DB::table('ec_proposal_price_versions')->where('service', $service)->where('version', $data['versao'])->exists()
                        ? [] : [
                            'effective_from' => '2020-01-01', 'configuration' => json_encode($data, JSON_THROW_ON_ERROR),
                            'approved' => true, 'created_at' => now(), 'updated_at' => now(),
                        ]
                ),
            );
            DB::table('ec_proposal_templates')->insertOrIgnore([
                'service' => $service, 'name' => $label, 'source_file' => $templateFile,
                'description' => 'Modelo oficial Clean Teal, fornecido pelo gerador ExportControl.',
                'ready_to_issue' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['DFE' => 'Documentos Fiscais Eletrônicos (DF-e)', 'NFSE' => 'Notas Fiscais de Serviço (NFS-e)'] as $service => $label) {
            DB::table('ec_proposal_templates')->insertOrIgnore([
                'service' => $service, 'name' => $label, 'source_file' => null,
                'description' => 'Catálogo e histórico disponíveis. Modelos e precificação aguardam aprovação.',
                'ready_to_issue' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $event = json_decode((string) file_get_contents($folder.'/26_CONTROLE_REAJUSTES_ANUAIS.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($event['reajustes'] as $row) {
            DB::table('ec_proposal_adjustments')->insertOrIgnore([
                'event_key' => $row['id'], 'effective_from' => $row['vigencia_inicio'],
                'percentage' => $row['percentual'], 'reason' => $row['motivo'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $tax = json_decode((string) file_get_contents($folder.'/05_TRIBUTOS_ATUAIS.json'), true, 512, JSON_THROW_ON_ERROR);
        DB::table('ec_proposal_tax_profiles')->insertOrIgnore([
            'version' => $tax['versao'], 'effective_from' => '2020-01-01',
            'rates' => json_encode($tax['aliquotas_percentuais'], JSON_THROW_ON_ERROR),
            'approved' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
