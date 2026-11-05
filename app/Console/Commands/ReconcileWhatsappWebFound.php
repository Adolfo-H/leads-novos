<?php

namespace App\Console\Commands;

use App\Services\Contacts\WhatsappWebConfirmationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Converter somente observações POSITIVAS preexistentes em confirmação técnica. */
final class ReconcileWhatsappWebFound extends Command
{
    protected $signature = 'contacts:wa-web:reconcile-found {--apply : Gravar confirmações técnicas para indícios positivos} {--limit=10000}';

    protected $description = 'Reconcilia positivos Web com a Central de Contatos, sem sobrescrever decisões manuais.';

    public function handle(WhatsappWebConfirmationService $service): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10000) {
            $this->error('Limite deve estar entre 1 e 10000.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $count = DB::table('whatsapp_web_probe_results as p')
                ->join('whatsapp_phone_numbers as n', 'n.id', '=', 'p.phone_id')
                ->where('p.result', 'found')->where('n.status', 'unknown')
                ->where('n.contacts_count', '>', 0)->count();
            $this->line("Prévias positivas sem confirmação técnica: {$count}. Para aplicar: --apply");

            return self::SUCCESS;
        }
        $result = $service->reconcile($limit);
        $this->info("Consultas positivas avaliadas: {$result['found']}; sincronizadas: {$result['promoted']}; preservadas/ignoradas: {$result['skipped']}.");

        return self::SUCCESS;
    }
}
