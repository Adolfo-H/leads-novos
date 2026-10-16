<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignReservationReview;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Requer decisão humana explícita; nunca envia e-mail. */
final class ReviewCampaignReservation extends Command
{
    protected $signature = 'campaigns:review-reservation
        {recipient : ID do destinatário}
        {--apply : Libera a reserva para pendente, se nunca foi enviada}
        {--confirm= : Repita o ID do destinatário para confirmar a alteração}
        {--reason= : Motivo de auditoria da liberação}';

    protected $description = 'Audita reservas antigas e permite liberar SOMENTE tentativas não iniciadas';

    public function handle(CampaignReservationReview $review): int
    {
        $argument = $this->argument('recipient');
        if (preg_match('/^[1-9][0-9]*$/D', (string) $argument) !== 1) {
            $this->error('Informe um ID numérico válido.');

            return self::FAILURE;
        }

        $recipientId = (int) $argument;
        $record = $review->inspect($recipientId);
        if ($record === null) {
            $this->error('Destinatário não encontrado.');

            return self::FAILURE;
        }

        $this->line('Campanha: '.$record['campaign_id'].' ('.$record['campaign_status'].')');
        $this->line('Destinatário: '.$record['recipient_id'].' ('.$record['recipient_status'].')');
        $this->line('Tentativas iniciadas: '.$record['attempts']);
        $this->line('Reserva: '.($record['reserved_at'] ?? 'não reservada'));
        $this->line('Liberação manual elegível: '.($record['eligible'] ? 'SIM' : 'NÃO'));

        if (! $this->option('apply')) {
            $this->line('MODO CONSULTA: nenhum registro ou e-mail alterado.');

            return self::SUCCESS;
        }

        if ((string) $this->option('confirm') !== (string) $recipientId) {
            $this->error('Operação recusada: use --confirm='.$recipientId);

            return self::FAILURE;
        }

        try {
            $changed = $review->release($recipientId, (string) $this->option('reason'));
        } catch (ValidationException) {
            $this->error('Informe --reason com 12 a 240 caracteres.');

            return self::FAILURE;
        }

        if (! $changed) {
            $this->error('Reserva não elegível ou alterada por outro processo. Nada foi modificado.');

            return self::FAILURE;
        }

        $this->info('Reserva não iniciada liberada para PENDENTE, com auditoria registrada.');
        $this->warn('Não houve disparo. Se a campanha estiver em execução, um tick futuro poderá reservar novamente o contato.');

        return self::SUCCESS;
    }
}
