<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignBounce;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignSuppression;
use Illuminate\Support\Facades\DB;

/**
 * Protecao ADITIVA para rodadas automaticas.
 * Nao altera/substitui CampaignSequenceGuard (que pode conter correcoes locais).
 * A checagem e feita novamente no worker, imediatamente antes de iniciar SMTP.
 */
final class CampaignAutomaticFollowupGuard
{
    public function suppressIfIneligible(EmailCampaignRecipient $candidate): bool
    {
        $link = DB::table('email_campaign_sequence_links')
            ->where('followup_recipient_id', $candidate->id)
            ->first(['sequence_id']);

        if ($link === null) {
            // Campanhas iniciais e envios manuais mantem somente a guarda atual.
            return false;
        }

        $automatic = DB::table('email_campaign_sequences')
            ->where('id', (int) $link->sequence_id)
            ->value('automatic');

        if (! (bool) $automatic) {
            return false;
        }

        // Falha de monitoramento ou desativacao de envios: libera a reserva,
        // SEM classificar como erro/bounce e SEM criar nova tentativa SMTP.
        if (! app(CampaignAutomaticFollowups::class)->isReady()) {
            $candidate->forceFill([
                'status' => 'pending',
                'reserved_at' => null,
                'reservation_token' => null,
            ])->save();

            return true;
        }

        if (EmailCampaignSuppression::query()
            ->where('normalized_email', $candidate->normalized_email)
            ->exists()) {
            return $this->block($candidate);
        }

        $currentId = (int) $candidate->id;
        $visited = [$currentId => true];
        $previousIds = [];

        // Até quatro novas rodadas; permite folga para legado e bloqueia ciclos.
        for ($depth = 0; $depth < 8; $depth++) {
            $parent = DB::table('email_campaign_sequence_links')
                ->where('followup_recipient_id', $currentId)
                ->first(['original_recipient_id']);

            if ($parent === null) {
                break;
            }

            $id = (int) $parent->original_recipient_id;
            if ($id <= 0 || isset($visited[$id])) {
                return $this->block($candidate);
            }
            $visited[$id] = true;

            $previous = EmailCampaignRecipient::query()->find($id);
            if ($previous === null
                || $previous->normalized_email !== $candidate->normalized_email
                || $previous->status !== 'sent'
                || $previous->sent_at === null
                || $previous->replied_at !== null
                || $previous->excluded_from_sequences_at !== null) {
                return $this->block($candidate);
            }

            $previousIds[] = $id;
            $currentId = $id;
        }

        // Mais de oito niveis significa historico anormal: falhar fechado.
        if (DB::table('email_campaign_sequence_links')
            ->where('followup_recipient_id', $currentId)->exists()) {
            return $this->block($candidate);
        }

        if ($previousIds !== [] && (
            EmailCampaignReply::query()
                ->whereIn('email_campaign_recipient_id', $previousIds)->exists()
            || EmailCampaignBounce::query()
                ->whereIn('email_campaign_recipient_id', $previousIds)->exists()
        )) {
            return $this->block($candidate);
        }

        return false;
    }

    private function block(EmailCampaignRecipient $candidate): bool
    {
        $candidate->forceFill([
            'status' => 'suppressed',
            'reserved_at' => null,
            'reservation_token' => null,
        ])->save();

        return true;
    }
}
