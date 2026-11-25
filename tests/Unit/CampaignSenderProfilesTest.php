<?php

use App\Services\Campaigns\CampaignSenderProfiles;

it('exibe somente dados públicos e não ativa transportes', function (): void {
    $summary = CampaignSenderProfiles::summary([
        'publicidade' => [
            'address' => 'publicidade@exportcontrol.com.br',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_security' => 'starttls',
            'smtp_username' => 'dummy',
            'smtp_password' => 'secret-test-value',
        ],
    ]);

    expect($summary[0]['smtp_ready'])->toBeTrue()
        ->and($summary[0]['imap_ready'])->toBeFalse()
        ->and($summary[1]['smtp_ready'])->toBeFalse()
        ->and(json_encode($summary))->not->toContain('secret-test-value');
});

it('rejeita endereço fora do domínio e caixa duplicada', function (): void {
    $summary = CampaignSenderProfiles::summary([
        'publicidade' => ['address' => 'publicidade@exportcontrol.combr'],
        'propaganda' => ['address' => 'propaganda@exportcontrol.com.br'],
    ]);
    expect($summary[0]['address'])->toBe('Endereço inválido ou não confirmado');
    $same = CampaignSenderProfiles::summary([
        'publicidade' => ['address' => 'publicidade@exportcontrol.com.br'],
        'propaganda' => ['address' => 'publicidade@exportcontrol.com.br'],
    ]);
    expect($same[0]['duplicate'])->toBeTrue()
        ->and($same[1]['duplicate'])->toBeTrue()
        ->and($same[0]['smtp_ready'])->toBeFalse();
});
