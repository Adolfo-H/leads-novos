<?php

namespace Database\Seeders;

use App\Models\CustomerRegistryEntry;
use App\Support\TextNormalizer;
use Illuminate\Database\Seeder;

class ExportControlCustomerRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $customers = [
            ['07903169', '07903169000109', 'ADECOAGRO VALE DO IVINHEMA S.A'],
            ['78473360', '78473360000106', 'COOPERATIVA AGROINDUSTRIAL BOM JESUS'],
            ['02077618', '02077618000185', 'COOPERATIVA AGROINDUSTRIAL DOS PRODUTORES RURAIS DO SUDOESTE GOIANO'],
            ['05528196', '05528196000105', 'COOPERATIVA AGROPECUARIA TRADICAO'],
            ['95821310', '95821310000183', 'COOPERATIVA TRITICOLA SANTA ROSA LTDA'],
            ['34656444', '34656444000100', 'USINA ENERSUGAR S/A ACUCAR E ALCOOL'],
            ['15009178', '15009178000170', 'USINAS ITAMARATI S/A'],
            ['04854422', '04854422000185', 'AGRICOLA ALVORADA S.A.'],
            ['00293663', '00293663000141', 'AGRONORTE LOGISTICA E AGRONEGOCIOS LTDA'],

            /*
             * Nosso caso de validação:
             */
            ['77863223', '77863223000107', 'C.VALE - COOPERATIVA AGROINDUSTRIAL'],

            ['79114450', '79114450000165', 'COCAMAR COOPERATIVA AGROINDUSTRIAL'],
            ['78956968', '78956968000183', 'COCARI - COOPERATIVA AGROPECUARIA E INDUSTRIAL'],
            ['02966548', '02966548000116', 'COOPERATIVA MISTA AGRO INDUSTRIAL DA AMAZONIA LTDA'],
            ['38161752', '38161752000107', 'DARROS COMMODITIES AGRICOLAS LTDA'],
            ['27989159', '27989159000137', 'FUTURO CEREAIS S/A'],
            ['12923609', '12923609000111', 'ITAHUM EXPORT COMERCIO DE CEREAIS LTDA.'],
            ['77752293', '77752293000198', 'LAR COOPERATIVA AGROINDUSTRIAL'],
            ['27490581', '27490581000143', 'LAVORO AGRO HOLDING S.A. - SOMENTE RETROATIVAS'],
            ['11696178', '11696178000135', 'MCR COMERCIO DE CEREAIS LTDA'],
            ['00933640', '00933640000154', 'MMSG COMERCIO IMPORTACAO E EXPORTACAO DE CEREAIS LTDA'],
            ['04409153', '04409153000148', 'SAFRAS AGROINDUSTRIA S/A'],
            ['23499753', '23499753000199', 'TAGUI BRASIL CEREAIS LTDA'],
            ['31616559', '31616559000174', 'TERRA ROXA COMERCIO DE CEREAIS LTDA'],
            ['44330975', '44330975000153', 'COLOMBO AGROINDUSTRIA S.A'],
            ['47902283', '47902283000120', 'SONORA ESTANCIA S/A'],
            ['28026036', '28026036000163', 'YUKAER AGRO LTDA'],
            ['30578053', '30578053000155', 'CEREALISTA GAMELAO LTDA'],
            ['13142597', '13142597000150', 'BR AGRO AGRONEGOCIOS S.A'],
            ['06044758', '06044758000108', 'CAMPOFERT COMERCIO E REPRESENTACOES DE PRODUTOS AGRICOLAS LTDA'],
            ['45236791', '45236791000191', 'COOPERCITRUS COOPERATIVA DE PRODUTORES RURAIS'],
            ['38850314', '38850314000156', 'GGF AGRO LTDA'],
            ['45483450', '45483450000110', 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERACAO JUDICIAL'],
            ['26288346', '26288346000120', 'ACP BIOENERGIA LTDA'],
            ['07742097', '07742097000157', 'SELVINO & OTILIA GONZATTI ARMAZENAGENS LTDA'],
            ['37216363', '37216363000250', 'ENERGETICA SANTA HELENA S/A'],
            ['13324895', '13324895000161', 'BM INDUSTRIA E COMERCIO DE CEREAIS LTDA'],
            ['31332061', '31332061000180', 'CEREALISTA AGRO OCS LTDA'],
            ['26796361', '26796361000180', 'SAO PEDRO COMERCIALIZACAO DE GRAOS LTDA'],
            ['11636261', '11636261000119', 'AGROINDUSTRIAL CAMPO REAL LTDA'],

            /*
             * A planilha atual possui estes dois
             * clientes sem CNPJ informado.
             *
             * O matcher só permitirá nome exato
             * normalizado nesses casos.
             */
            [null, null, 'GAMA - Pertence MMSG'],
            [null, null, 'COOPERATIVA AGRO-INDUSTRIAL HOLAMBRA'],
        ];

        foreach (
            $customers as [
                $root,
                $cnpj,
                $name,
            ]
        ) {
            CustomerRegistryEntry::query()
                ->updateOrCreate(
                    [
                        'source' => 'exportcontrol-clientes',

                        'normalized_name' => TextNormalizer::companyName(
                            $name
                        ),
                    ],
                    [
                        'cnpj_root' => $root,

                        'cnpj' => $cnpj,

                        'corporate_name' => $name,

                        'enabled' => true,

                        'metadata' => [
                            'source_file' => 'ExportControl - Clientes.xlsx',

                            'source_sheet' => 'Cliente',
                        ],
                    ]
                );
        }
    }
}
