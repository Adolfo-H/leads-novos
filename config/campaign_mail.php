<?php

return [
    // As campanhas nunca enviam automaticamente ao instalar este pacote.
    'enabled' => (bool) env('CAMPAIGN_MAIL_ENABLED', false),
    'live_enabled' => (bool) env('CAMPAIGN_MAIL_LIVE_ENABLED', false),
    'mailer' => env('CAMPAIGN_MAIL_MAILER', 'smtp'),
    'from_address' => env('CAMPAIGN_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', '')),
    'from_name' => env('CAMPAIGN_MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'ExportControl')),
    'reply_to' => env('CAMPAIGN_MAIL_REPLY_TO', env('MAIL_FROM_ADDRESS', '')),
    'test_to' => env('CAMPAIGN_MAIL_TEST_TO', env('MAIL_FROM_ADDRESS', '')),
    'tracked_test_to' => env('CAMPAIGN_TRACKED_TEST_TO', ''),
    // Conservadores: alterar só depois de aprovar limites com o administrador do SMTP.
    'per_hour' => (int) env('CAMPAIGN_MAIL_PER_HOUR', 10),
    'per_day' => (int) env('CAMPAIGN_MAIL_PER_DAY', 30),
    'per_tick' => (int) env('CAMPAIGN_MAIL_PER_TICK', 1),
    'max_recipients' => (int) env('CAMPAIGN_MAIL_MAX_RECIPIENTS', 100),
    'queue' => env('CAMPAIGN_MAIL_QUEUE', 'campaigns'),
    'imap_enabled' => (bool) env('CAMPAIGN_IMAP_ENABLED', false),
    'imap_host' => env('IMAP_HOST', ''),
    'imap_port' => (int) env('IMAP_PORT', 993),
    'imap_username' => env('IMAP_USERNAME', ''),
    'imap_password' => env('IMAP_PASSWORD', ''),
    'imap_folder' => env('CAMPAIGN_IMAP_FOLDER', 'INBOX'),
    'python' => env('CAMPAIGN_IMAP_PYTHON', 'python3'),
];
