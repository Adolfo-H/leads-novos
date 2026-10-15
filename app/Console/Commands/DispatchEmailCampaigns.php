<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Console\Command;

class DispatchEmailCampaigns extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Reserva poucos e-mails aprovados e os envia para a fila exclusiva';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        $count = $dispatcher->tick();
        $this->info('Destinatários encaminhados à fila: '.$count);

        return self::SUCCESS;
    }
}
