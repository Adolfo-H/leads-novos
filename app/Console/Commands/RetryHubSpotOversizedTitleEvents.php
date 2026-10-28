<?php

namespace App\Console\Commands;

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\HubSpotWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recuperação EXCEPCIONAL de eventos SQLSTATE[22001] esgotados.
 * Não altera a política de dez tentativas do recuperador normal.
 * --apply é necessário: sem ele, comando é 100% somente leitura.
 */
final class RetryHubSpotOversizedTitleEvents extends Command
{
    protected $signature = 'hubspot:webhooks-title-retry
        {--ids= : IDs dos eventos específicos, separados por vírgula}
        {--apply : Enfileira uma única tentativa extra, sem zerar a contagem histórica}';

    protected $description = 'Prévia/recuperação controlada de webhooks de tarefas falhados por título longo (Pacote 39).';

    private const MIGRATION = '2026_10_23_151000_widen_hubspot_activity_titles';

    public function handle(): int
    {
        $raw = trim((string) $this->option('ids'));
        $tokens = array_map('trim', explode(',', $raw));

        if (
            $raw === ''
            || count($tokens) > 20
            || count($tokens) !== count(array_unique($tokens))
            || count(array_filter($tokens, static fn (string $id): bool => ctype_digit($id) && (int) $id > 0)) !== count($tokens)
        ) {
            $this->error('Informe de 1 a 20 IDs distintos e numéricos com --ids=ID1,ID2.');

            return self::FAILURE;
        }

        if (! DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
            $this->error('A migration 39 não foi aplicada. Corrija o banco antes de reenfileirar.');

            return self::FAILURE;
        }

        $ids = array_map('intval', $tokens);
        $apply = (bool) $this->option('apply');
        $queued = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $event = HubSpotWebhookEvent::query()->find($id);
            if ($event === null) {
                $this->warn("Evento {$id}: inexistente. Ignorado.");
                $skipped++;

                continue;
            }

            if (! $this->eligible($event)) {
                $this->warn("Evento {$id}: não é uma falha de título longo esgotada ou já mudou de estado. Ignorado.");
                $skipped++;

                continue;
            }

            if (! $apply) {
                $this->line("APTO (prévia) {$id} | tipo=task | status=failed | tentativas={$event->attempts}");

                continue;
            }

            // Reservamos atomicamente. A transação termina ANTES da fila receber o job.
            $reserved = DB::transaction(function () use ($id): bool {
                $locked = HubSpotWebhookEvent::query()->whereKey($id)->lockForUpdate()->first();
                if ($locked === null || ! $this->eligible($locked)) {
                    return false;
                }

                // Mantém attempts=10 e error original até o job executar.
                $locked->forceFill([
                    'status' => 'queued',
                    'processed_at' => null,
                ])->save();

                return true;
            });

            if (! $reserved) {
                $this->warn("Evento {$id}: outro processo o alterou. Ignorado.");
                $skipped++;

                continue;
            }

            try {
                ProcessHubSpotWebhookEvent::dispatch($id);
                $this->info("Evento {$id}: reenfileirado para UMA nova tentativa.");
                $queued++;
            } catch (Throwable $exception) {
                // Em indisponibilidade do Redis, restaurar o estado para permitir nova tentativa explícita.
                HubSpotWebhookEvent::query()
                    ->whereKey($id)
                    ->where('status', 'queued')
                    ->update(['status' => 'failed']);
                $this->error("Evento {$id}: Redis indisponível (".get_class($exception).').');
                $skipped++;
            }
        }

        if (! $apply) {
            $this->comment('PRÉVIA: nenhum evento ou fila foi alterado. Acrescente --apply somente depois de conferir os IDs.');
        } else {
            $this->line("RESULTADO: reenfileirados={$queued}, ignorados={$skipped}. Acompanhe processed/failed no banco.");
        }

        return self::SUCCESS;
    }

    private function eligible(HubSpotWebhookEvent $event): bool
    {
        $error = (string) ($event->error ?? '');

        return $event->status === 'failed'
            && $event->object_type === 'task'
            && (int) $event->attempts >= 10
            && str_contains($error, 'SQLSTATE[22001]')
            && str_contains($error, 'company_lead_activities');
    }
}
