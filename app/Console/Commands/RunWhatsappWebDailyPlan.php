<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Console\Command;

/** Apenas solicita lotes; os agentes Ubuntu precisam estar online. */
final class RunWhatsappWebDailyPlan extends Command
{
    protected $signature = 'contacts:wa-web:daily-plan';

    protected $description = 'Agenda lotes WhatsApp Web somente quando o gestor habilitar a rotina diária.';

    public function handle(WhatsappWebDailyCoordinator $coordinator): int
    {
        $result = $coordinator->planDaily();
        $this->line('WhatsApp diário: '.$result['reason'].'; lotes: '.$result['requested']);

        return self::SUCCESS;
    }
}
