<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignTestMessage;

/** Resolves the recorded receiving mailbox, without changing SMTP or sending state. */
final class CampaignReplyMailbox
{
    public static function dedicatedTestAddress(string $messageId, int $campaignId): ?string
    {
        $messageId = trim(trim($messageId), '<>');
        foreach (DualCampaignSenders::KEYS as $key) {
            if ($messageId === 'ec-dedicated-test-'.$campaignId.'-'.$key.'@exportcontrol.com.br') {
                return $key.'@exportcontrol.com.br';
            }
        }

        return null;
    }

    public static function recordedTestMailbox(EmailCampaignTestMessage $test): ?string
    {
        $mailbox = trim((string) $test->getAttribute('reply_mailbox'));

        return $mailbox !== '' ? $mailbox : null;
    }

    public static function testMailboxForRead(EmailCampaignTestMessage $test): string
    {
        return self::recordedTestMailbox($test)
            ?? self::dedicatedTestAddress((string) $test->message_id, (int) $test->email_campaign_id)
            ?? trim((string) config('campaign_mail.imap_username', ''));
    }

    public static function testMailboxLabel(EmailCampaignTestMessage $test): string
    {
        $recorded = self::recordedTestMailbox($test);
        if ($recorded !== null) {
            return $recorded;
        }
        $expected = self::dedicatedTestAddress((string) $test->message_id, (int) $test->email_campaign_id);

        return $expected !== null ? $expected.' (prevista; confirmar pela leitura)' : 'Não registrada (teste legado)';
    }

    /** @return array{host:string,port:int,username:string,password:string,folder:string}|null */
    public static function imap(string $mailbox): ?array
    {
        $mailbox = trim($mailbox);
        if ($mailbox === '' || ! config('campaign_mail.imap_enabled')) {
            return null;
        }
        foreach (DualCampaignSenders::KEYS as $key) {
            $address = $key.'@exportcontrol.com.br';
            if (strtolower($mailbox) !== $address) {
                continue;
            }
            // Read-only access depends on IMAP, not SMTP authorization or a live campaign.
            $profile = config('campaign_sender_profiles.'.$key);
            if (! is_array($profile)
                || strtolower(trim((string) ($profile['address'] ?? ''))) !== $address
                || strtolower(trim((string) ($profile['imap_username'] ?? ''))) !== $address
                || strtolower(trim((string) ($profile['imap_host'] ?? ''))) !== 'mail.exportcontrol.com.br'
                || (int) ($profile['imap_port'] ?? 0) !== 993) {
                return null;
            }
            $password = $profile['imap_password'] ?? null;
            if (! is_string($password) || $password === '') {
                return null;
            }

            return ['host' => 'mail.exportcontrol.com.br', 'port' => 993, 'username' => $address, 'password' => $password, 'folder' => 'INBOX'];
        }
        $username = trim((string) config('campaign_mail.imap_username', ''));
        $host = trim((string) config('campaign_mail.imap_host', ''));
        $password = (string) config('campaign_mail.imap_password', '');
        $port = (int) config('campaign_mail.imap_port', 993);
        $folder = (string) config('campaign_mail.imap_folder', 'INBOX');
        if ($mailbox !== $username || $host === '' || $password === '' || $port !== 993 || $folder === '') {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password, 'folder' => $folder];
    }
}
