<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnóstico real do lock PostgreSQL entre processos independentes.
 * Não seleciona destinatários, não cria jobs, não envia e-mail.
 */
final class ProbeCampaignQuotaGate extends Command
{
    protected $signature = 'campaigns:probe-quota-lock {--hold=0 : Tempo máximo de posse do lock, em segundos (0 a 5)}';

    protected $description = 'Verifica a trava global de cota sem disparar campanhas';

    public function handle(): int
    {
        if (config('campaign_mail.live_enabled')) {
            $this->error('Teste recusado: mantenha CAMPAIGN_MAIL_LIVE_ENABLED=false.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('O diagnóstico de concorrência real exige PostgreSQL.');

            return self::FAILURE;
        }

        $value = $this->option('hold');
        if (! is_scalar($value) || preg_match('/^[0-5]$/D', (string) $value) !== 1) {
            $this->error('--hold deve ser um inteiro entre 0 e 5.');

            return self::FAILURE;
        }

        $hold = (int) $value;

        try {
            $waitMs = DB::transaction(static function () use ($hold): int {
                $started = microtime(true);
                $gate = DB::table('email_campaign_dispatch_gates')
                    ->where('id', 1)->lockForUpdate()->first();
                if ($gate === null) {
                    throw new \RuntimeException('Linha global de cota não existe; execute as migrations.');
                }

                $elapsed = (int) round((microtime(true) - $started) * 1000);
                if ($hold > 0) {
                    sleep($hold);
                }

                return $elapsed;
            });
        } catch (Throwable $error) {
            $this->error('Diagnóstico falhou: '.class_basename($error));

            return self::FAILURE;
        }

        $this->info('Lock PostgreSQL adquirido após '.$waitMs.' ms; mantido por '.$hold.' s.');
        $this->line('Nenhum e-mail, destinatário ou job foi alterado.');

        return self::SUCCESS;
    }
}
