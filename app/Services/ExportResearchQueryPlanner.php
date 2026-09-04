<?php

namespace App\Services;

use App\Models\Company;

final class ExportResearchQueryPlanner
{
    /**
     * @return list<string>
     */
    public function queries(
        Company $company
    ): array {
        $name =
            trim(
                str_replace(
                    '"',
                    '',
                    $company->corporate_name
                )
            );

        $quoted =
            '"'.$name.'"';

        $queries = [
            /*
             * Exportação direta.
             */
            $quoted
                .' exportação exportações exporta',

            $quoted
                .' exportador exportadora '
                .'comércio exterior',

            $quoted
                .' Siscomex exportação',

            /*
             * Exportação indireta.
             */
            $quoted
                .' "fim específico de exportação"',

            $quoted
                .' venda trading exportação',

            $quoted
                .' "comercial exportadora" '
                .'venda exportação',

            /*
             * Relação com trading.
             */
            $quoted
                .' trading comercial exportadora',

            $quoted
                .' ADM Bunge Cargill '
                .'Louis Dreyfus COFCO',
        ];

        return array_values(
            array_unique(
                $queries
            )
        );
    }
}
