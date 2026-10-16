<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignQueueHealth;
use Illuminate\Console\Command;

/** Diagnostico somente de leitura: nao reserva, nao dispara, nao reenvia. */
final class CampaignQueueStatus extends Command
{
    protected $signature = 'campaigns:queue-status
        {--check : Retorna erro se alguma reserva ou tentativa parecer travada}';

    protected $description = 'Diagnostica a fila de campanhas sem enviar ou modificar mensagens';

    public function handle(CampaignQueueHealth $health): int
    {
        $snapshot = $health->snapshot();

        $this->line('Modo de envio: '.($snapshot['safe_mode'] ? 'SEGURO (envio comercial bloqueado)' : 'ENVIO COMERCIAL HABILITADO'));
        $this->line('Fila configurada: '.$snapshot['queue']);
        $this->line('Pendentes: '.$snapshot['pending']);
        $this->line('Reservados: '.$snapshot['reserved']);
        $this->line('Em envio: '.$snapshot['sending']);
        $this->line('Aceitos pelo SMTP: '.$snapshot['sent']);
        $this->line('Resultado incerto (NAO reenviar automaticamente): '.$snapshot['uncertain']);
        $this->line('Descadastrados/bloqueados: '.$snapshot['suppressed']);
        $this->line('Cancelados: '.$snapshot['canceled']);
        $this->line('Reservas antigas (mais de '.$snapshot['reservation_timeout_minutes'].' min): '.$snapshot['stale_reserved']);
        $this->line('Envios sem conclusao (mais de '.$snapshot['sending_timeout_minutes'].' min): '.$snapshot['stale_sending']);
        $this->line('Nenhum e-mail foi enviado, reenfileirado ou alterado.');

        if ($snapshot['stale_reserved'] > 0 || $snapshot['stale_sending'] > 0) {
            $this->warn('Ha registros possivelmente travados. Nao reinicie mensagens em envio sem confirmar a situacao no servidor SMTP.');

            return $this->option('check') ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
