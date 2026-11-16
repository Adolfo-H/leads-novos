<?php

declare(strict_types=1);

use App\Console\Commands\RecoverCampaignReplies;
use App\Console\Commands\RecoverCampaignReplyHeaders;
use Illuminate\Support\Facades\Artisan;

// EC_CI_V69_COMMANDS: o legado V71 e a recuperação limitada V77 coexistem.
it('mantém os comandos V71 e V77 registrados separadamente', function (): void {
    $commands = Artisan::all();

    expect($commands)->toHaveKey('campaigns:recover-replies-v71')
        ->toHaveKey('campaigns:recover-replies');

    expect($commands['campaigns:recover-replies-v71'])
        ->toBeInstanceOf(RecoverCampaignReplyHeaders::class);

    $v77 = $commands['campaigns:recover-replies'];
    expect($v77)->toBeInstanceOf(RecoverCampaignReplies::class)
        ->and($v77->getDefinition()->hasOption('hours'))->toBeTrue()
        ->and($v77->getDefinition()->hasOption('limit'))->toBeTrue()
        ->and($v77->getDefinition()->hasOption('apply'))->toBeTrue();
});
