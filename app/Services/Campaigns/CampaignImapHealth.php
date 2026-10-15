<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use Illuminate\Support\Carbon;

/**
 * Indicadores derivados exclusivamente do cursor persistido e de respostas.
 * Nenhuma conexão IMAP é aberta durante o carregamento da interface.
 */
final class CampaignImapHealth
{
    /**
     * @return array{
     *   state:'disabled'|'not_configured'|'not_initialized'|'healthy'|'stale',
     *   label:string,
     *   last_sync_at:string|null,
     *   minutes_since_sync:int|null,
     *   expected_interval_minutes:int,
     *   stale_after_minutes:int,
     *   last_uid:int,
     *   tracked_replies:int,
     *   campaign_replies:int
     * }
     */
    public function snapshot(): array
    {
        $enabled = (bool) config('campaign_mail.imap_enabled', false);
        $mailbox = trim((string) config('campaign_mail.imap_username', ''));
        $host = trim((string) config('campaign_mail.imap_host', ''));
        $hasPassword = trim((string) config('campaign_mail.imap_password', '')) !== '';
        $staleAfter = max(10, min(1440, (int) config('campaign_mail.imap_stale_minutes', 15)));

        $cursor = $mailbox !== ''
            ? EmailCampaignInboxCursor::query()->where('mailbox', $mailbox)->first()
            : null;

        // O cast Eloquent retorna uma data, mas sua inferencia estatica
        // pode continuar sendo string. Carbon::parse aceita ambos os tipos.
        $lastSyncValue = $cursor?->last_synced_at;
        $lastSync = $lastSyncValue === null
            ? null
            : Carbon::parse($lastSyncValue);
        $minutes = $lastSync === null
            ? null
            : max(0, (int) floor((now()->getTimestamp() - $lastSync->getTimestamp()) / 60));

        if (! $enabled) {
            $state = 'disabled';
            $label = 'Desativado';
        } elseif ($mailbox === '' || $host === '' || ! $hasPassword) {
            $state = 'not_configured';
            $label = 'Configuração incompleta';
        } elseif ($lastSync === null) {
            $state = 'not_initialized';
            $label = 'Aguardando primeira sincronização';
        } elseif ($minutes !== null && $minutes > $staleAfter) {
            $state = 'stale';
            $label = 'Sincronização atrasada';
        } else {
            $state = 'healthy';
            $label = 'Sincronização recente';
        }

        return [
            'state' => $state,
            'label' => $label,
            'last_sync_at' => $lastSync === null
                ? null
                : $lastSync->copy()
                    ->setTimezone((string) config('app.timezone', 'America/Sao_Paulo'))
                    ->format('d/m/Y H:i'),
            'minutes_since_sync' => $minutes,
            'expected_interval_minutes' => 5,
            'stale_after_minutes' => $staleAfter,
            'last_uid' => (int) ($cursor->last_uid ?? 0),
            'tracked_replies' => EmailCampaignTestMessage::query()->whereNotNull('replied_at')->count(),
            'campaign_replies' => EmailCampaignReply::query()->count(),
        ];
    }
}
