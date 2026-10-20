<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignQueueSimulator;
use Illuminate\Console\Command;

/** Diagnóstico exclusivamente local: não libera ou envia mensagens. */
final class SimulateEmailCampaignQueue extends Command
{
    protected $signature = 'campaigns:simulate {--recipients=50 : Quantidade virtual (1 a 500)}';

    protected $description = 'Projeta a capacidade de envio sem escrever no banco, disparar jobs ou acessar SMTP/IMAP';

    public function handle(CampaignQueueSimulator $simulator): int
    {
        $option = $this->option('recipients');
        $input = is_scalar($option) ? (string) $option : '';
        if (preg_match('/^[0-9]{1,3}$/D', $input) !== 1 || (int) $input < 1 || (int) $input > 500) {
            $this->error('Use --recipients=N com N entre 1 e 500.');

            return self::FAILURE;
        }

        $report = $simulator->report((int) $input);
        $actual = $report['actual'];
        $this->info('SIMULAÇÃO — NENHUM ENVIO REAL');
        $this->line('Modo comercial: '.($report['safe_mode'] ? 'BLOQUEADO (seguro)' : 'HABILITADO no ambiente; simulação continua sem envio'));
        $this->line('Destinatários hipotéticos: '.$report['requested']);
        $this->line('Limites: '.$report['hourly_limit'].' por hora; '.$report['daily_limit'].' por 24 horas corridas; '.$report['per_tick_limit'].' por minuto');
        $this->line('Início virtual: '.$report['forecast_starts_at']);
        $this->line('1ª hora: '.$report['first_hour']);
        $this->line('Até meia-noite do dia de início (informativo): '.$report['first_calendar_day']);
        $this->line('Primeiras 24h: '.$report['first_24_hours']);
        $this->line('Até 7 dias: '.$report['within_seven_days']);
        $this->line('Restantes após 7 dias: '.$report['remaining']);
        $this->line('Fila real (somente leitura): pendentes='.$actual['pending'].' reservados='.$actual['reserved'].' enviando='.$actual['sending'].' incertos='.$actual['uncertain']);
        $this->line('Auditoria agregada: tentativas='.$actual['attempted'].' respostas='.$actual['replies'].' bounces='.$actual['bounces'].' opt-outs globais='.$actual['global_suppressions']);
        $this->line('Alertas reais: reservas antigas='.$actual['stale_reserved'].' envios sem conclusão='.$actual['stale_sending']);
        $this->warn('Cenário teórico: parte de uma fila virtual vazia e não considera concorrência, SMTP ou outras campanhas.');
        $this->info('Nenhum destinatário, e-mail, job, banco ou cursor IMAP foi alterado.');

        return self::SUCCESS;
    }
}
