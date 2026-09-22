<?php

return [
    'crm' => [
        /*
         * Até este período consideramos
         * o lead em contato recente.
         */
        'contacting_after_days' => (int) env(
            'CRM_CONTACTING_AFTER_DAYS',
            30
        ),

        /*
         * Depois deste período sem atividade,
         * o lead entra em reprospecção.
         */
        'reprospecting_after_days' => (int) env(
            'CRM_REPROSPECT_AFTER_DAYS',
            90
        ),
    ],

    'sdr' => [
        /*
         * Contatos em andamento sem atividade
         * por este período aparecem como parados.
         */
        'stale_after_days' => (int) env(
            'SDR_STALE_AFTER_DAYS',
            7
        ),
    ],

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
        ],
    ],
];
