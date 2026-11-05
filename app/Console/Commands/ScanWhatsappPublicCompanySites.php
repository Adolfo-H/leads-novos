<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Console\Command;

final class ScanWhatsappPublicCompanySites extends Command
{
    protected $signature = 'contacts:wa-public:scan {--limit=5 : Sites no lote (1 a 20)} {--manager-email= : E-mail do gestor} {--apply : Colocar pesquisa na fila}';

    protected $description = 'Pesquisa links públicos de WhatsApp em domínios registrados no HubSpot; nunca consulta WhatsApp.';

    public function handle(WhatsappPublicSiteResearch $service): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 20) {
            $this->error('Use --limit entre 1 e 20.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info("Prévia: pesquisa de até {$limit} site(s). Nenhuma requisição, fila ou alteração.");
            $this->line('Use --apply e --manager-email=EMAIL para agendar os jobs.');

            return self::SUCCESS;
        }
        $user = User::query()->where('email', (string) $this->option('manager-email'))->first();
        if ($user === null || ! $user->isCommercialManager()) {
            $this->error('Gestor não encontrado/autorizado.');

            return self::FAILURE;
        }
        $id = $service->queueRun($user, $limit);
        $this->info("Pesquisa #{$id} colocada na fila. Veja o progresso em /contatos/verificacao-whatsapp.");

        return self::SUCCESS;
    }
}
