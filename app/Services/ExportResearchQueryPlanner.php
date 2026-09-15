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

        /*
         * Não colocamos o CNPJ obrigatoriamente
         * na consulta.
         *
         * Muitas páginas oficiais falam apenas
         * da marca/grupo e não exibem o CNPJ.
         *
         * A validação da identidade acontece
         * depois, no ExportResearchEntityMatcher.
         */
        $identity =
            $quoted;

        $queries = [
            /*
             * Exportação direta.
             */
            $identity
                .' exportação exportações exporta',

            $identity
                .' exportador exportadora '
                .'comércio exterior',

            $identity
                .' Siscomex exportação',

            /*
             * Exportação indireta.
             */
            $identity
                .' "fim específico de exportação"',

            $identity
                .' venda trading exportação',

            $identity
                .' "comercial exportadora" '
                .'venda exportação',

            /*
             * Relação com trading.
             */
            $identity
                .' trading comercial exportadora',

            $identity
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
