<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Desativa SÓ os lotes automáticos do WhatsApp Web; preserva a execução manual. */
final class WhatsappDisableAutomaticV16 extends Command
{
    protected $signature = 'contacts:wa-web:manual-only-v16 {--apply : Desativar a rotina diária no banco}';

    protected $description = 'Conferência sob demanda: sem rotina diária; sem apagar cotas e históricos.';

    public function handle(): int
    {
        $existing = DB::table('whatsapp_web_scan_settings')->where('id', 1)->first();
        if (! $existing) {
            $this->error('Configuração não encontrada.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->line('Atualmente: auto_enabled='.(int) $existing->auto_enabled.'. Use --apply para desativar.');

            return self::SUCCESS;
        }
        DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
            'auto_enabled' => false, 'updated_at' => now('UTC'),
        ]);
        $this->info('Rotina automática WhatsApp Web desativada. Consultas manuais e sessões preservadas.');

        return self::SUCCESS;
    }
}
