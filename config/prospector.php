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

    'prospecting' => [
        /*
         * Somente uma execução do motor pode
         * reservar prospects por vez.
         *
         * O lock protege apenas:
         *
         * preview definitivo
         * +
         * gravação do lote.
         *
         * Os jobs são enviados depois.
         */
        'execution_lock_seconds' => (int) env(
            'PROSPECTING_EXECUTION_LOCK_SECONDS',
            300
        ),

        /*
         * Tempo máximo que uma segunda
         * execução aguarda a primeira.
         */
        'execution_lock_wait_seconds' => (int) env(
            'PROSPECTING_EXECUTION_LOCK_WAIT_SECONDS',
            30
        ),
    ],

    'imports' => [
        'max_rows' => (int) env(
            'IMPORT_MAX_ROWS',
            5000
        ),

        'dispatch_chunk_size' => (int) env(
            'IMPORT_DISPATCH_CHUNK_SIZE',
            50
        ),

        'dispatch_max_per_run' => (int) env(
            'IMPORT_DISPATCH_MAX_PER_RUN',
            500
        ),

        'ready_batches_per_run' => (int) env(
            'IMPORT_READY_BATCHES_PER_RUN',
            100
        ),

        'recovery_items_per_batch' => (int) env(
            'IMPORT_RECOVERY_ITEMS_PER_BATCH',
            100
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
