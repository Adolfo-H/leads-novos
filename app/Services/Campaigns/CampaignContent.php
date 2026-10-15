<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;

final class CampaignContent
{
    public function render(string $template, EmailCampaignRecipient $recipient): string
    {
        $name = trim((string) $recipient->contact_name);
        $first = $name !== '' ? explode(' ', $name)[0] : 'Olá';

        return strtr($template, [
            '{{nome}}' => $name !== '' ? $name : 'Contato',
            '{{primeiro_nome}}' => $first,
            '{{empresa}}' => trim((string) $recipient->company_name) ?: 'sua empresa',
            '{{cargo}}' => trim((string) $recipient->job_title) ?: 'profissional',
        ]);
    }
}
