<?php

namespace App\Services\Campaigns;

/** Cadastro de caixas candidatas. Não seleciona transporte nem libera envio. */
final class CampaignSenderProfiles
{
    /**
     * @param  array<array-key, mixed>  $profiles
     * @return list<array{label: string, address: string, smtp_ready: bool, imap_ready: bool, duplicate: bool}>
     */
    public static function summary(array $profiles): array
    {
        $rows = [];
        $counts = [];
        foreach (['publicidade' => 'Publicidade', 'propaganda' => 'Propaganda'] as $key => $label) {
            $profile = $profiles[$key] ?? [];
            if (! is_array($profile)) {
                $profile = [];
            }
            $address = strtolower(trim(self::value($profile, 'address')));
            $valid = filter_var($address, FILTER_VALIDATE_EMAIL) !== false
                && str_ends_with($address, '@exportcontrol.com.br');
            $smtpReady = $valid
                && self::value($profile, 'smtp_host') !== ''
                && self::value($profile, 'smtp_username') !== ''
                && self::value($profile, 'smtp_password') !== ''
                && in_array(self::value($profile, 'smtp_security'), ['starttls', 'tls'], true)
                && self::port($profile, 'smtp_port');
            $imapReady = $valid
                && self::value($profile, 'imap_host') !== ''
                && self::value($profile, 'imap_username') !== ''
                && self::value($profile, 'imap_password') !== ''
                && self::port($profile, 'imap_port');
            if ($valid) {
                $counts[$address] = ($counts[$address] ?? 0) + 1;
            }
            $rows[] = [
                'label' => $label,
                'address' => $valid ? $address : 'Endereço inválido ou não confirmado',
                'smtp_ready' => $smtpReady,
                'imap_ready' => $imapReady,
                'duplicate' => false,
            ];
        }
        foreach ($rows as &$row) {
            if (($counts[$row['address']] ?? 0) > 1) {
                $row['duplicate'] = true;
                $row['smtp_ready'] = false;
                $row['imap_ready'] = false;
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<array-key, mixed> $profile */
    private static function value(array $profile, string $key): string
    {
        $value = $profile[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<array-key, mixed> $profile */
    private static function port(array $profile, string $key): bool
    {
        $value = $profile[$key] ?? null;

        return is_int($value) && $value > 0 && $value <= 65535;
    }
}
