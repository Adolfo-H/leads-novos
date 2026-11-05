<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Opt-in administrativo no PostgreSQL. Não conecta WhatsApp nem envia mensagens. */
final class EnableWhatsappWeb200PerDay extends Command
{
    protected $signature = 'contacts:wa-web:activate-200
        {--manager-email= : E-mail do gestor responsável, se houver mais de um}
        {--apply : Confirma a ativação, não apenas a prévia}
        {--disable : Desliga o agendamento sem apagar resultados}
        {--rollback-compat : Desliga e restaura limite compatível com V8.1}';

    protected $description = 'Configura até 100 consultas/dia em cada conta, das 08h às 18h.';

    public function handle(WhatsappWebDailyCoordinator $coordinator): int
    {
        $settings = $coordinator->settings();
        if ($this->option('rollback-compat')) {
            DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
                'auto_enabled' => false,
                'daily_limit' => min(100, (int) $settings->daily_limit),
                'updated_at' => now('UTC'),
            ]);
            $this->info('Agendamento parado e limite total reduzido para no máximo 100 (compatível com V8.1).');

            return self::SUCCESS;
        }
        if ($this->option('disable')) {
            DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
                'auto_enabled' => false, 'updated_at' => now('UTC'),
            ]);
            $this->info('Agendamento WhatsApp Web desativado; histórico e sessões preservados.');

            return self::SUCCESS;
        }
        $email = trim((string) $this->option('manager-email'));
        $actor = null;
        if ($email !== '') {
            $actor = User::query()->where('email', $email)->first();
        } elseif ($settings->configured_by !== null) {
            $actor = User::query()->find((int) $settings->configured_by);
        }
        if ($actor === null && $email === '') {
            $lastId = DB::table('whatsapp_web_scan_batches')
                ->whereNotNull('requested_by')->orderByDesc('id')->value('requested_by');
            if ($lastId !== null) {
                $candidate = User::query()->find((int) $lastId);
                if ($candidate?->isCommercialManager()) {
                    $actor = $candidate;
                }
            }
        }
        if ($actor === null && $email === '') {
            $managers = User::query()->where('commercial_role', User::ROLE_MANAGER)->limit(2)->get();
            if ($managers->count() === 1) {
                $actor = $managers->first();
            }
        }
        if ($actor === null || ! $actor->isCommercialManager()) {
            $this->error('Não foi possível identificar um gestor autorizado de forma única.');
            $this->line('Execute novamente com --manager-email=EMAIL_DO_GESTOR --apply.');

            return self::FAILURE;
        }

        $this->line('Configuração: 100 consultas por conta/dia, 200 no total, das 08:00 às 18:00 (São Paulo).');
        $this->line('Gestor responsável: '.$actor->name.' ('.$actor->email.').');
        $this->line('Escopo: '.($settings->scan_scope ?? 'all').'. A integração é não oficial e tem risco de bloqueio.');
        if (! $this->option('apply')) {
            $this->comment('Somente prévia. Nenhum agendamento foi ativado. Use --apply para autorizar.');

            return self::SUCCESS;
        }

        $coordinator->save($actor, 200, '08:00', true, (string) ($settings->scan_scope ?? 'all'), '18:00');
        $fresh = $coordinator->settings();
        if (! $fresh->auto_enabled || (int) $fresh->daily_limit !== 200
            || (string) $fresh->start_time !== '08:00'
            || (string) $fresh->end_time !== '18:00'
            || (int) $fresh->configured_by !== (int) $actor->id) {
            $this->error('Configuração não persistiu como esperado.');

            return self::FAILURE;
        }
        $this->info('AUTOMAÇÃO ATIVA no PostgreSQL. Execução depende dos dois agentes e do scheduler Laravel.');
        $budget = $coordinator->budget($fresh);
        foreach ([1, 2] as $session) {
            $stats = $budget['sessions'][$session];
            $this->line("Conta {$session}: {$stats['checked']} consultadas; {$stats['reserved']} reservadas; {$stats['remaining']} restantes hoje.");
        }

        return self::SUCCESS;
    }
}
