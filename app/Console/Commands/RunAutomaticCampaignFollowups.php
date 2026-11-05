<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignAutomaticFollowups;
use Illuminate\Console\Command;

final class RunAutomaticCampaignFollowups extends Command
{
    protected $signature = 'campaigns:run-automatic-followups';

    protected $description = 'Autoriza próximas rodadas configuradas pelo gestor, respeitando todas as travas de segurança';

    public function handle(CampaignAutomaticFollowups $service): int
    {
        if (! $service->isReady()) {
            $this->warn('Follow-up automático inativo: requer SMTP e IMAP saudáveis, HTTPS e ativação explícita.');

            return self::SUCCESS;
        }

        $this->info('Campanhas de novas rodadas autorizadas: '.$service->tick());

        return self::SUCCESS;
    }
}
