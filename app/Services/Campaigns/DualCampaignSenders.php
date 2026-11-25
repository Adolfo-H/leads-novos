<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Fixed, approved sender allocation. No failover, retry or quota multiplier. */
final class DualCampaignSenders
{
    public const KEYS = ['publicidade', 'propaganda'];

    /** @return array{enabled:bool,blocked:bool} */
    public static function state(): array
    {
        if (! Schema::hasTable('email_campaign_sender_control')) {
            return ['enabled' => false, 'blocked' => false];
        }
        $row = DB::table('email_campaign_sender_control')->where('id', 1)->first();
        if ($row === null) {
            throw new RuntimeException('Controle de remetentes ausente.');
        }

        return ['enabled' => (bool) $row->dual_enabled, 'blocked' => $row->blocked_at !== null];
    }

    /** @return array{address:string,smtp_host:string,smtp_port:int,smtp_username:string,smtp_password:string,imap_host:string,imap_port:int,imap_username:string,imap_password:string}|null */
    public static function profile(string $key): ?array
    {
        if (! in_array($key, self::KEYS, true)) {
            return null;
        }
        $p = config('campaign_sender_profiles.'.$key);
        if (! is_array($p)) {
            return null;
        }
        $address = $key.'@exportcontrol.com.br';
        foreach (['address', 'smtp_username', 'imap_username'] as $field) {
            if (strtolower(trim((string) ($p[$field] ?? ''))) !== $address) {
                return null;
            }
        }
        if ((int) ($p['smtp_port'] ?? 0) !== 465 || (string) ($p['smtp_security'] ?? '') !== 'tls'
            || (int) ($p['imap_port'] ?? 0) !== 993) {
            return null;
        }
        foreach (['smtp_host', 'imap_host'] as $field) {
            if (strtolower(trim((string) ($p[$field] ?? ''))) !== 'mail.exportcontrol.com.br') {
                return null;
            }
        }
        $smtpPassword = $p['smtp_password'] ?? null;
        $imapPassword = $p['imap_password'] ?? null;
        if (! is_string($smtpPassword) || $smtpPassword === '' || ! is_string($imapPassword) || $imapPassword === '') {
            return null;
        }

        return [
            'address' => $address,
            'smtp_host' => 'mail.exportcontrol.com.br',
            'smtp_port' => 465,
            'smtp_username' => $address,
            'smtp_password' => $smtpPassword,
            'imap_host' => 'mail.exportcontrol.com.br',
            'imap_port' => 993,
            'imap_username' => $address,
            'imap_password' => $imapPassword,
        ];
    }

    public static function inboxReady(string $key): bool
    {
        $p = self::profile($key);
        if ($p === null || ! config('campaign_mail.imap_enabled')) {
            return false;
        }
        $cursor = EmailCampaignInboxCursor::query()->where('mailbox', $p['address'])->first();
        // Eloquent serializes datetimes without their offset by default. Read the
        // raw, explicitly zoned timestamp recorded by CampaignInbox for these boxes.
        if ($cursor === null) {
            return false;
        }
        $last = $cursor->getRawOriginal('last_synced_at');
        if (! is_string($last) || preg_match('/(?:Z|[+-]\d{2}(?::?\d{2})?)$/iD', $last) !== 1
            || ! ctype_digit((string) $cursor->uid_validity)) {
            return false;
        }
        try {
            $seconds = now('UTC')->getTimestamp() - Carbon::parse($last, 'UTC')->getTimestamp();
        } catch (\Throwable) {
            return false;
        }

        return $seconds >= -30 && $seconds <= 15 * 60;
    }

    public static function ready(): bool
    {
        return self::inboxReady('publicidade') && self::inboxReady('propaganda');
    }

    /** Authorization must be obtained outside the application; authentication alone is insufficient. */
    public static function authorize(string $reference): void
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 200) {
            throw new RuntimeException('Informe a referência da autorização do provedor.');
        }
        if (! self::ready()) {
            throw new RuntimeException('Sincronize o IMAP das duas contas antes de habilitar.');
        }
        DB::transaction(static function () use ($reference): void {
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw new RuntimeException('Trava global ausente.');
            }
            $state = self::state();
            if ($state['blocked']) {
                throw new RuntimeException('Existe bloqueio registrado. Não há desbloqueio automático.');
            }
            if ($state['enabled']) {
                return;
            }
            if (EmailCampaign::query()->where('status', 'running')->exists()
                || EmailCampaignRecipient::query()->whereIn('status', ['reserved', 'sending'])->exists()) {
                throw new RuntimeException('Conclua ou pause as campanhas antigas e aguarde as tentativas em andamento. Nenhuma delas será transferida.');
            }
            DB::table('email_campaign_sender_control')->where('id', 1)->update([
                'dual_enabled' => true, 'authorized_at' => now('UTC'), 'authorization_reference' => $reference,
            ]);
        });
    }

    public static function checkResume(EmailCampaign $campaign): void
    {
        $state = self::state();
        if ($state['blocked'] || ($state['enabled'] && ($campaign->getAttribute('sender_mode') !== 'dual' || ! self::ready()))) {
            throw ValidationException::withMessages(['campaign' => 'Campanha sem remetente autorizado ou bloqueada para revisão. Nenhuma troca automática de caixa.']);
        }
    }

    public static function checkLegacyTest(): void
    {
        $state = self::state();
        if ($state['enabled'] || $state['blocked']) {
            throw ValidationException::withMessages(['campaign' => 'O envio de teste legado não está disponível no modo de caixas dedicadas.']);
        }
    }

    /** Called within the existing global dispatch gate, only on explicit draft approval. */
    public static function prepare(EmailCampaign $campaign): void
    {
        $state = self::state();
        if ($state['blocked']) {
            throw ValidationException::withMessages(['campaign' => 'Envios bloqueados para revisão do provedor SMTP.']);
        }
        if (! $state['enabled']) {
            return;
        }
        if (DedicatedCampaignProbe::inProgress()) {
            throw ValidationException::withMessages(['campaign' => 'Aguarde a conclusão ou revisão do diagnóstico SMTP.']);
        }
        if (! self::ready()) {
            throw ValidationException::withMessages(['campaign' => 'As duas caixas precisam de sincronização IMAP recente.']);
        }
        $id = (int) $campaign->id;
        $pending = DB::table('email_campaign_recipients')->where('email_campaign_id', $id)->where('status', 'pending');
        // Never transfer an attempted or existing legacy campaign to another mailbox.
        if (DB::table('email_campaign_recipients')->where('email_campaign_id', $id)->whereNotNull('attempted_at')->exists()) {
            throw ValidationException::withMessages(['campaign' => 'Não é permitido trocar remetente de campanha já iniciada.']);
        }
        $conflict = DB::table('email_campaign_recipients as prior')
            ->join('email_campaigns as c', 'c.id', '=', 'prior.email_campaign_id')
            ->join('email_campaign_recipients as candidate', 'candidate.normalized_email', '=', 'prior.normalized_email')
            ->where('candidate.email_campaign_id', $id)->where('candidate.status', 'pending')
            ->where('prior.email_campaign_id', '<>', $id)
            ->where(static function ($q): void {
                $q->where('prior.status', 'uncertain')->orWhere(static function ($q): void {
                    $q->where('c.sender_mode', 'legacy')->whereIn('c.status', ['running', 'paused'])
                        ->whereIn('prior.status', ['pending', 'reserved', 'sending']);
                });
            })->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['campaign' => 'Há destinatários em campanha anterior não concluída ou com envio incerto. Revise antes de iniciar.']);
        }
        $index = 0;
        $isFollowup = $campaign->getAttribute('followup_root_campaign_id') !== null
            || DB::table('email_campaign_sequence_links as l')
                ->join('email_campaign_recipients as r', 'r.id', '=', 'l.followup_recipient_id')
                ->where('r.email_campaign_id', $id)->exists();
        foreach ($pending->orderBy('id')->cursor() as $row) {
            if ($isFollowup) {
                $previous = DB::table('email_campaign_sequence_links as l')
                    ->join('email_campaign_recipients as r', 'r.id', '=', 'l.original_recipient_id')
                    ->where('l.followup_recipient_id', $row->id)
                    ->first(['r.sender_profile', 'r.sender_address', 'r.normalized_email']);
                $key = (string) ($previous->sender_profile ?? '');
                if ($previous === null || $previous->normalized_email !== $row->normalized_email
                    || ! in_array($key, self::KEYS, true)
                    || $previous->sender_address !== $key.'@exportcontrol.com.br') {
                    throw ValidationException::withMessages(['campaign' => 'Rodada de campanha antiga ou sem remetente comprovado: nenhuma troca automática.']);
                }
            } else {
                $key = self::keyForPosition($index++);
            }
            DB::table('email_campaign_recipients')->where('id', $row->id)->update([
                'sender_profile' => $key, 'sender_address' => $key.'@exportcontrol.com.br',
            ]);
        }
        $campaign->forceFill(['sender_mode' => 'dual'])->save();
    }

    public static function keyForPosition(int $index): string
    {
        if ($index < 0) {
            throw new RuntimeException('Posição inválida.');
        }

        return self::KEYS[$index % 2];
    }

    public static function canSend(EmailCampaignRecipient $recipient, EmailCampaign $campaign): bool
    {
        $state = self::state();
        if ($state['blocked']) {
            return false;
        }
        $key = (string) $recipient->getAttribute('sender_profile');
        if (! $state['enabled']) {
            return $key === '' && $campaign->getAttribute('sender_mode') !== 'dual';
        }
        if (DedicatedCampaignProbe::inProgress()) {
            return false;
        }
        $profile = self::profile($key);
        if ($campaign->getAttribute('sender_mode') !== 'dual' || $profile === null
            || $recipient->getAttribute('sender_address') !== $profile['address'] || ! self::ready()) {
            return false;
        }

        // New replies, opt-outs, bounces and uncertain attempts from either account are global.
        return ! EmailCampaignRecipient::query()->where('normalized_email', $recipient->normalized_email)
            ->where('id', '<>', $recipient->id)->where('status', 'uncertain')->exists();
    }

    /** Configures an isolated Laravel SMTP mailer, not the application default. */
    public static function mailer(string $key): string
    {
        $p = self::profile($key);
        if ($p === null) {
            throw new RuntimeException('Remetente não configurado.');
        }
        $name = 'campaign_dedicated_'.$key;
        config()->set('mail.mailers.'.$name, [
            'transport' => 'smtp', 'scheme' => 'smtps', 'host' => $p['smtp_host'],
            'port' => $p['smtp_port'], 'username' => $p['smtp_username'],
            'password' => $p['smtp_password'], 'timeout' => 30,
        ]);

        return $name;
    }

    /** Persistent global hold. In-flight SMTP operations may already have been accepted. */
    public static function halt(): void
    {
        DB::transaction(static function (): void {
            DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            DB::table('email_campaign_sender_control')->where('id', 1)->whereNull('blocked_at')->update([
                'blocked_at' => now('UTC'), 'blocked_reason' => 'smtp_uncertain',
            ]);
        });
    }
}
