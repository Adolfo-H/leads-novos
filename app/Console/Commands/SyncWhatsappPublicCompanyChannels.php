<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappPublicCompanyChannels;
use Illuminate\Console\Command;

/** Reprocessa evidências já salvas SEM visitar sites ou conectar WhatsApp. */
final class SyncWhatsappPublicCompanyChannels extends Command
{
    protected $signature = 'contacts:wa-public:register-channels';

    protected $description = 'Cadastra canais de WhatsApp publicados, ligados à empresa HubSpot do domínio (sem criar pessoas ou negócios).';

    public function handle(WhatsappPublicCompanyChannels $channels): int
    {
        $result = $channels->backfill();
        $this->info('Canais empresariais: '.$result['channels'].'. Domínios: '
            .$result['domains'].'. Novos vínculos: '.$result['created'].'.');

        return self::SUCCESS;
    }
}
