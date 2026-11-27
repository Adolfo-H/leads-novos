<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;

/** Presentation only: does not select a transport or change reply routing. */
final class CampaignInstitutionalSignature
{
    public const DISPLAY_NAME = 'ExportControl | Comercial';

    public const TEAM = 'Equipe Comercial';

    public const COMPANY = 'ExportControl';

    public const DESCRIPTION = 'Soluções para Controle das Exportações';

    public const SITE = 'https://exportcontrol.com.br';

    public const SITE_LABEL = 'exportcontrol.com.br';

    public const LOGO = 'images/brand/pngexportcontrol.png';

    public const DEDICATED_ADDRESSES = [
        'publicidade@exportcontrol.com.br',
        'propaganda@exportcontrol.com.br',
    ];

    public static function dedicatedAddress(?string $address): ?string
    {
        $normalized = strtolower(trim((string) $address));

        return in_array($normalized, self::DEDICATED_ADDRESSES, true) ? $normalized : null;
    }

    /** Only used for previews; historical campaign metadata stays unchanged. */
    public static function usesDedicatedPreview(?EmailCampaign $campaign = null): bool
    {
        if ($campaign !== null && $campaign->getAttribute('sender_mode') === 'dual') {
            return true;
        }

        return ($campaign === null || $campaign->status === 'draft')
            && DualCampaignSenders::state()['enabled'];
    }

    /** Label, never an SMTP destination. A preview cannot know the individual assignment. */
    public static function previewFrom(?EmailCampaign $campaign = null): string
    {
        if (self::usesDedicatedPreview($campaign)) {
            return self::DISPLAY_NAME.' — Publicidade ou Propaganda';
        }

        $address = $campaign === null
            ? (string) config('campaign_mail.from_address', '')
            : (string) $campaign->from_address;

        return $address !== '' ? $address : 'Conta de envio não configurada';
    }
}
