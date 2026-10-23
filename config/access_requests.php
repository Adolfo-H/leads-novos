<?php

return [
    // Controle exclusivo do administrador inicial. Não concede privilégios por si só.
    'approver_email' => strtolower((string) env('ACCESS_APPROVER_EMAIL', 'adolfo@exportcontrol.com.br')),
    'notification_email' => strtolower((string) env('ACCESS_NOTIFICATION_EMAIL', 'adolfo@exportcontrol.com.br')),
    'invite_hours' => (int) env('ACCESS_INVITE_HOURS', 48),
];
