<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Console\Command;

final class SyncWhatsappPhoneRegistry extends Command
{
    protected $signature = 'contacts:sync-whatsapp-phones {--apply : Reconstrói o índice local (nunca consulta WhatsApp)}';

    protected $description = 'Reconcilia números únicos de HubSpot, manuais e Receita sem alterar contatos nem disparar mensagens.';

    public function handle(WhatsappPhoneRegistry $registry): int
    {
        if (! $this->option('apply')) {
            $this->warn('Somente consulta de capacidade. Para reconstruir o índice LOCAL execute com --apply.');

            return self::SUCCESS;
        }

        $this->info('Reconciliando telefones locais (sem API externa e sem mensagens)...');
        $result = $registry->sync();
        $this->line('Únicos: '.$result['numbers'].' | ocorrências: '.$result['references']);
        $this->line('Celulares: '.$result['mobile'].' | fixos: '.$result['landline'].' | revisar: '.$result['review']);
        $this->info('Concluído; verificações já registradas foram preservadas.');

        return self::SUCCESS;
    }
}
