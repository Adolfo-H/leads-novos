<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use Illuminate\Validation\ValidationException;

/** URL externa independente do APP_URL local; nunca altera APP_URL no WSL. */
final class CampaignUnsubscribeLink
{
    public function base(): ?string
    {
        $explicit = trim((string) config('campaign_mail.unsubscribe_base_url', ''));
        // Se foi informado explicitamente, falhas não podem cair no fallback silenciosamente.
        $candidate = $explicit !== '' ? $explicit : trim((string) config('app.url', ''));
        $parts = parse_url($candidate);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || isset($parts['port'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host) !== 1
            || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/(?:^|\.)(localhost|local|test|invalid|example|internal)$/D', $host) === 1) {
            return null;
        }

        return 'https://'.$host;
    }

    public function isReady(): bool
    {
        return $this->base() !== null;
    }

    public function forRecipient(EmailCampaignRecipient $recipient, UnsubscribeToken $tokens): string
    {
        $base = $this->base();
        if ($base === null) {
            throw ValidationException::withMessages([
                'campaign' => 'Descadastro público indisponível. Configure CAMPAIGN_UNSUBSCRIBE_BASE_URL com HTTPS válido.',
            ]);
        }

        return $base.route('campaigns.unsubscribe', ['token' => $tokens->forRecipient($recipient)], false);
    }
}
