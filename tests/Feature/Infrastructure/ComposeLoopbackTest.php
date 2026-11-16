<?php

it('restringe as portas de Laravel e Vite ao loopback sem alterar a rede de dados', function (): void {
    $compose = file_get_contents(base_path('compose.yaml'));

    expect($compose)->toBeString()
        ->toContain('127.0.0.1:${APP_PORT:-80}:80')
        ->toContain('127.0.0.1:${VITE_PORT:-5173}:${VITE_PORT:-5173}')
        ->toContain('127.0.0.1:${FORWARD_DB_PORT:-5432}:5432')
        ->toContain('127.0.0.1:${FORWARD_REDIS_PORT:-6379}:6379');
});
