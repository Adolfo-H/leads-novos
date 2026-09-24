<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'brasilapi' => [
        'base_url' => env(
            'BRASILAPI_BASE_URL',
            'https://brasilapi.com.br/api'
        ),
    ],

    'apibrasil' => [
        'token' => env('APIBRASIL_BEARER_TOKEN'),

        'base_url' => env(
            'APIBRASIL_BASE_URL',
            'https://gateway.apibrasil.io/api/v2'
        ),

        'cnpj_type' => env(
            'APIBRASIL_CNPJ_TYPE',
            'cnpj-cadastral'
        ),
    ],
    'receita_local' => [
        'base_url' => env(
            'RECEITA_LOCAL_BASE_URL',
            'http://receita-data:8000'
        ),
    ],

    'tavily' => [
        'api_key' => env(
            'TAVILY_API_KEY'
        ),

        'base_url' => env(
            'TAVILY_BASE_URL',
            'https://api.tavily.com'
        ),

        'max_results' => (int) env(
            'TAVILY_MAX_RESULTS',
            5
        ),
    ],

    'openai' => [
        'api_key' => env(
            'OPENAI_API_KEY'
        ),

        'base_url' => env(
            'OPENAI_BASE_URL',
            'https://api.openai.com/v1'
        ),

        'export_research_model' => env(
            'OPENAI_EXPORT_RESEARCH_MODEL',
            'gpt-5.6-terra'
        ),

        'export_research_search_context' => env(
            'OPENAI_EXPORT_RESEARCH_SEARCH_CONTEXT',
            'medium'
        ),
    ],

    'hubspot' => [
        'access_token' => env(
            'HUBSPOT_ACCESS_TOKEN'
        ),

        'base_url' => env(
            'HUBSPOT_BASE_URL',
            'https://api.hubapi.com'
        ),

        'portal_id' => env(
            'HUBSPOT_PORTAL_ID'
        ),

        'webhook_secret' => env(
            'HUBSPOT_WEBHOOK_SECRET'
        ),

        'webhook_public_url' => env(
            'HUBSPOT_WEBHOOK_PUBLIC_URL'
        ),

        'webhook_verify_signature' => (bool) env(
            'HUBSPOT_WEBHOOK_VERIFY_SIGNATURE',
            true
        ),

        'lead_sync_enabled' => (bool) env(
            'HUBSPOT_LEAD_SYNC_ENABLED',
            false
        ),

        'lead_min_score' => (int) env(
            'HUBSPOT_LEAD_MIN_SCORE',
            60
        ),

        'lead_pipeline' => env(
            'HUBSPOT_LEAD_PIPELINE',
            'default'
        ),

        'lead_initial_stage' => env(
            'HUBSPOT_LEAD_INITIAL_STAGE',
            'appointmentscheduled'
        ),

        'lead_discarded_stages' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'HUBSPOT_LEAD_DISCARDED_STAGES',
                            '13185627,13185628'
                        )
                    )
                ),
                static fn (
                    string $stage
                ): bool => $stage !== ''
            )
        ),

        'lead_future_stages' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'HUBSPOT_LEAD_FUTURE_STAGES',
                            '13185626'
                        )
                    )
                ),
                static fn (
                    string $stage
                ): bool => $stage !== ''
            )
        ),

        'lead_refused_stages' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'HUBSPOT_LEAD_REFUSED_STAGES',
                            'closedlost'
                        )
                    )
                ),
                static fn (
                    string $stage
                ): bool => $stage !== ''
            )
        ),

        'lead_converted_stages' => array_values(
            array_filter(
                array_map(
                    'trim',
                    explode(
                        ',',
                        (string) env(
                            'HUBSPOT_LEAD_CONVERTED_STAGES',
                            'closedwon'
                        )
                    )
                ),
                static fn (
                    string $stage
                ): bool => $stage !== ''
            )
        ),

    ],

];
