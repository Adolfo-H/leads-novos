<?php

namespace App\Services\Campaigns;

use App\Mail\ProspectorHumanReplyMessage;
use App\Models\EmailCampaignReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Mensagem individual, explicitamente confirmada; status incerto NÃO é reenviado. */
final class CampaignHumanReplies
{
    public function send(User $user, EmailCampaignReply $reply, string $body, string $requestId): int
    {
        abort_unless($user->isCommercialManager(), 403);
        if (! config('campaign_mail.live_enabled') || ! config('campaign_mail.reply_send_enabled', false)) {
            throw ValidationException::withMessages(['reply' => 'Envio de respostas bloqueado até homologação comercial e habilitação explícita.']);
        }
        if (preg_match('/^[a-f0-9]{8}\-[a-f0-9]{4}\-[1-8][a-f0-9]{3}\-[89ab][a-f0-9]{3}\-[a-f0-9]{12}$/Di', $requestId) !== 1) {
            throw ValidationException::withMessages(['reply' => 'Identificador da confirmação inválido.']);
        }
        $existing = DB::table('email_campaign_outbound_replies')->where('request_uuid', $requestId)->first();
        if ($existing !== null) {
            if ((int) $existing->email_campaign_reply_id !== (int) $reply->id || (int) $existing->created_by !== (int) $user->id) {
                throw ValidationException::withMessages(['reply' => 'Confirmação já utilizada por outra solicitação.']);
            }

            return (int) $existing->id; // Nunca reenviar, inclusive em status incerto.
        }
        $body = trim($body);
        if (mb_strlen($body) < 5 || mb_strlen($body) > 10000) {
            throw ValidationException::withMessages(['reply' => 'A resposta precisa ter entre 5 e 10.000 caracteres.']);
        }
        $recipient = $reply->recipient;
        if ($recipient === null || $recipient->message_id === null || $reply->from_email !== $recipient->normalized_email) {
            throw ValidationException::withMessages(['reply' => 'Conversa sem correlação segura com um envio anterior.']);
        }
        if (DB::table('email_campaign_suppressions')->where('normalized_email', $recipient->normalized_email)->exists()) {
            throw ValidationException::withMessages(['reply' => 'Este contato solicitou descadastro ou está bloqueado.']);
        }
        if (! filter_var($reply->from_email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['reply' => 'E-mail do remetente inválido.']);
        }
        $key = 'campaign-human-replies:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['reply' => 'Limite de dez respostas por hora.']);
        }
        RateLimiter::hit($key, 3600);

        // Reservar ANTES do SMTP. Não executar novamente após erro de comunicação.
        $inserted = DB::table('email_campaign_outbound_replies')->insertOrIgnore([
            'email_campaign_reply_id' => $reply->id,
            'created_by' => $user->id,
            'recipient_email' => $recipient->normalized_email,
            'body' => encrypt($body),
            'status' => 'sending',
            'request_uuid' => $requestId,
            'attempted_at' => now('UTC'),
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
        $entry = DB::table('email_campaign_outbound_replies')->where('request_uuid', $requestId)->first();
        if ($entry === null) {
            throw new \RuntimeException('Falha ao registrar a resposta antes do SMTP.');
        }
        $id = (int) $entry->id;
        if ($inserted === 0) { // Dupla submissão simultânea: 1 registro/1 tentativa, sem retry.
            return $id;
        }
        try {
            $subject = trim((string) $reply->subject);
            $subject = preg_match('/^Re:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
            $original = (string) $recipient->message_id;
            $incoming = (string) ($reply->incoming_message_id ?? '');
            Mail::mailer((string) config('campaign_mail.mailer', 'smtp'))
                ->to($recipient->normalized_email)
                ->send(new ProspectorHumanReplyMessage(mb_substr($subject, 0, 180), $body, $incoming, $original));
            DB::table('email_campaign_outbound_replies')->where('id', $id)->update([
                'status' => 'sent', 'sent_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            DB::table('email_campaign_outbound_replies')->where('id', $id)->update([
                'status' => 'uncertain',
                'error_class' => mb_substr(class_basename($exception), 0, 120),
                'updated_at' => now('UTC'),
            ]);
        }

        return $id;
    }
}
