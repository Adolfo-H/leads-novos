<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappWebConfirmationService;
use Illuminate\Console\Command;

/** Compatibilidade para leitura de resultados positivos; não altera confirmações. */
final class ReconcileWhatsappWebFound extends Command
{
    protected $signature = 'contacts:wa-web:reconcile-found {--apply : Opção legada, aceita mas não altera confirmações} {--limit=10000}';

    protected $description = 'Conta resultados positivos do WhatsApp Web sem gravar confirmação factual.';

    public function handle(WhatsappWebConfirmationService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10000) {
            $this->error('Limite deve estar entre 1 e 10000.');

            return self::FAILURE;
        }
        $result = $service->reconcile($limit);
        $this->info("Indícios positivos técnicos avaliados: {$result['found']}; confirmações manuais alteradas: {$result['manual_confirmations_written']} (operação somente leitura).");

        return self::SUCCESS;
    }
}
