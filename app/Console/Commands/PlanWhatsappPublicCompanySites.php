<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Console\Command;

final class PlanWhatsappPublicCompanySites extends Command
{
    protected $signature = 'contacts:wa-public:daily';

    protected $description = 'Agenda pesquisa diária opt-in de links públicos, sem conexão WhatsApp.';

    public function handle(WhatsappPublicSiteResearch $service): int
    {
        $this->line($service->planDaily() ? 'Lote público agendado.' : 'Nenhum lote agendado.');

        return self::SUCCESS;
    }
}
