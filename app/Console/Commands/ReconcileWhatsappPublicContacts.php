<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappPublicSiteResearch;
use Illuminate\Console\Command;

final class ReconcileWhatsappPublicContacts extends Command
{
    protected $signature = 'contacts:wa-public:reconcile {--apply : Atualiza apenas os vínculos locais das evidências públicas}';

    protected $description = 'Cruza os links públicos já encontrados com telefones existentes no Prospector (sem acessar sites ou WhatsApp).';

    public function handle(WhatsappPublicSiteResearch $research): int
    {
        $apply = (bool) $this->option('apply');
        $stats = $research->reconcileExisting($apply);
        $this->line(($apply ? 'Atualização concluída' : 'Prévia somente leitura')
            .': '.$stats['seen'].' links; '.$stats['global'].' números no banco; '
            .$stats['direct'].' vinculados à mesma empresa; '.$stats['changed'].' ajustes necessários/aplicados.');

        return self::SUCCESS;
    }
}
