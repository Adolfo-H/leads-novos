<?php

return [
    'export_research' => [
        /*
         * A pesquisa externa fica desligada
         * até decidirmos qual provider/custo
         * será usado em produção.
         */
        'enabled' => (bool) env(
            'EXPORT_RESEARCH_ENABLED',
            false
        ),

        /*
         * Evita pesquisar novamente a mesma
         * empresa em intervalos muito curtos.
         */
        'cooldown_days' => (int) env(
            'EXPORT_RESEARCH_COOLDOWN_DAYS',
            30
        ),

        /*
         * Pesquisa automática somente empresas
         * que realmente fazem sentido para SDR.
         */
        'eligible_icp_grades' => [
            'A',
            'B',
        ],

        /*
         * Situações comerciais que bloqueiam
         * pesquisa automática para evitar custo
         * em empresas já trabalhadas.
         */
        'blocked_crm_statuses' => [
            'client',
            'opportunity',
            'prospected',
        ],
    ],
];
