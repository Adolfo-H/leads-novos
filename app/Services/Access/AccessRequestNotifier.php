<?php

namespace App\Services\Access;

use App\Mail\AccessRequestSubmitted;
use App\Models\AccountAccessRequest;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class AccessRequestNotifier
{
    public function send(AccountAccessRequest $record): string
    {
        try {
            $address = (string) config('access_requests.notification_email');

            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('ACCESS_NOTIFICATION_EMAIL inválido.');
            }

            Mail::to($address)->send(new AccessRequestSubmitted($record));

            $status = self::usesLocalCapture() ? 'local_only' : 'accepted_by_mailer';
        } catch (Throwable $exception) {
            report($exception);
            $status = 'failed';
        }

        $record->forceFill([
            'notification_status' => $status,
            'notification_attempted_at' => now(),
        ])->save();

        return $status;
    }

    public static function usesLocalCapture(): bool
    {
        $mailer = strtolower(trim((string) config('mail.default')));

        if (in_array($mailer, ['log', 'array', 'mailpit'], true)) {
            return true;
        }

        if ($mailer === 'smtp') {
            $host = strtolower(trim((string) config('mail.mailers.smtp.host')));

            return in_array($host, ['mailpit', 'localhost', '127.0.0.1'], true)
                || str_contains($host, 'mailpit');
        }

        return false;
    }
}
