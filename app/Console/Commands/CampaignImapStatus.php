<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignImapHealth;
use Illuminate\Console\Command;

/** Status do cursor IMAP: não autentica, não lê mensagens e não dispara e-mail. */
final class CampaignImapStatus extends Command
{
    protected $signature = 'campaigns:imap-status {--check : Retorna falha quando IMAP não está sincronizando}';

    protected $description = 'Mostra saúde da leitura IMAP de campanhas sem acessar e-mails';

    public function handle(CampaignImapHealth $health): int
    {
        $snapshot = $health->snapshot();

        $this->line('Estado IMAP: '.$snapshot['label']);
        $this->line('Última sincronização: '.($snapshot['last_sync_at'] ?? 'Nunca registrada'));
        $this->line('Tempo desde a sincronização: '.(
            $snapshot['minutes_since_sync'] !== null
                ? $snapshot['minutes_since_sync'].' minuto(s)'
                : 'indisponível'
        ));
        $this->line('Intervalo programado: '.$snapshot['expected_interval_minutes'].' minutos');
        $this->line('Respostas de testes rastreados: '.$snapshot['tracked_replies']);
        $this->line('Respostas comerciais registradas: '.$snapshot['campaign_replies']);
        $this->line('Campanhas: nenhuma mensagem enviada por este comando.');

        if ($this->option('check') && $snapshot['state'] !== 'healthy') {
            $this->error('ALERTA: o estado IMAP exige verificação do agendador/configuração.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
