<?php

// Cadastro preparatório. O motor ativo continua usando campaign_mail.
return [
    'publicidade' => [
        'address' => env('CAMPAIGN_PUBLICIDADE_ADDRESS', 'publicidade@exportcontrol.com.br'),
        'smtp_host' => env('CAMPAIGN_PUBLICIDADE_SMTP_HOST', ''),
        'smtp_port' => (int) env('CAMPAIGN_PUBLICIDADE_SMTP_PORT', 0),
        'smtp_security' => env('CAMPAIGN_PUBLICIDADE_SMTP_SECURITY', ''),
        'smtp_username' => env('CAMPAIGN_PUBLICIDADE_SMTP_USERNAME', ''),
        'smtp_password' => env('CAMPAIGN_PUBLICIDADE_SMTP_PASSWORD', ''),
        'imap_host' => env('CAMPAIGN_PUBLICIDADE_IMAP_HOST', ''),
        'imap_port' => (int) env('CAMPAIGN_PUBLICIDADE_IMAP_PORT', 0),
        'imap_username' => env('CAMPAIGN_PUBLICIDADE_IMAP_USERNAME', ''),
        'imap_password' => env('CAMPAIGN_PUBLICIDADE_IMAP_PASSWORD', ''),
    ],
    'propaganda' => [
        'address' => env('CAMPAIGN_PROPAGANDA_ADDRESS', 'propaganda@exportcontrol.com.br'),
        'smtp_host' => env('CAMPAIGN_PROPAGANDA_SMTP_HOST', ''),
        'smtp_port' => (int) env('CAMPAIGN_PROPAGANDA_SMTP_PORT', 0),
        'smtp_security' => env('CAMPAIGN_PROPAGANDA_SMTP_SECURITY', ''),
        'smtp_username' => env('CAMPAIGN_PROPAGANDA_SMTP_USERNAME', ''),
        'smtp_password' => env('CAMPAIGN_PROPAGANDA_SMTP_PASSWORD', ''),
        'imap_host' => env('CAMPAIGN_PROPAGANDA_IMAP_HOST', ''),
        'imap_port' => (int) env('CAMPAIGN_PROPAGANDA_IMAP_PORT', 0),
        'imap_username' => env('CAMPAIGN_PROPAGANDA_IMAP_USERNAME', ''),
        'imap_password' => env('CAMPAIGN_PROPAGANDA_IMAP_PASSWORD', ''),
    ],
];
