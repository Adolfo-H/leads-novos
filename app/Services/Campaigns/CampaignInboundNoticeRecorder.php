<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Registra cabecalhos de respostas automaticas/nao confirmadas vinculadas com seguranca.
 * NAO cria resposta humana, NAO altera replied_at, NAO atualiza CRM, NAO envia SMTP.
 * Sem corpo ou anexos. Nao vincula por semelhanca vaga de assunto ou dominio.
 */
final class CampaignInboundNoticeRecorder
{
    /** @param array<string, mixed> $message */
    public function record(string $mailbox, string $validity, int $uid, array $message): bool
    {
        if ($uid < 1 || $validity === '' || ! ctype_digit($validity)) {
            return false;
        }
        $from = CampaignAudience::normalizedEmail((string) ($message['from_email'] ?? ''));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $subject = mb_substr(trim((string) ($message['subject'] ?? '')), 0, 255);
        // EC_INBOUND_V21: nao deixar falhas tecnicas na area de retornos.
        // Auto-generated com assunto generico ainda pode ser um aviso institucional;
        // exige vinculo defensavel e assunto identico ao enviado.
        if (CampaignInboundNoticeFilter::isTechnical($message)) {
            return true;
        }
        $genericAutoIgnored = CampaignInboundNoticeFilter::shouldIgnore($message);
        $kind = $this->kind($message);
        if ($kind === 'automatic' && ! CampaignInboundNoticeFilter::hasUsefulSubject($subject)) {
            // Evidencia de auto-reply sem texto explicito no assunto.
            // Diferencia de registros legados classificados genericamente como automatic.
            $kind = 'auto_reply';
        }
        $references = (string) ($message['in_reply_to'] ?? '').' '.(string) ($message['references'] ?? '');
        preg_match_all('/<([A-Za-z0-9.\-]+@[A-Za-z0-9.\-]+)>/', $references, $hits);
        $ids = array_values(array_unique($hits[1]));

        // A Frisa e outros canais podem devolver avisos mantendo o assunto
        // original, SEM "Re:". So aceitar quando houver remetente e envio unicos.
        if ($ids !== [] && EmailCampaignTestMessage::query()->whereIn('message_id', $ids)->exists()) {
            return false; // Testes rastreados permanecem fora das metricas comerciais.
        }

        $received = $this->receivedAt($message['received_at'] ?? null);
        $recipient = null;
        $method = '';

        if ($ids !== []) {
            $candidates = EmailCampaignRecipient::query()
                ->whereIn('message_id', $ids)->whereNotNull('attempted_at')->limit(50)->get();
            $sameSender = $candidates->filter(static fn (EmailCampaignRecipient $r): bool => $r->normalized_email === $from);
            if ($sameSender->count() === 1) {
                $recipient = $sameSender->first();
                $method = 'reference_exact';
            } elseif ($candidates->count() === 1) {
                // Message-ID unico, mas o remetente mudou: apenas revisao, nunca resposta humana.
                $recipient = $candidates->first();
                $method = 'reference_mismatch';
            }
        }

        if ($recipient === null && $ids === []) {
            // Fallback limitado: mesmo remetente + UNICO envio aceito nas 72h anteriores.
            // Evita relacionar uma caixa pessoal ou duas campanhas do mesmo destinatario.
            $candidates = EmailCampaignRecipient::query()
                ->where('normalized_email', $from)->whereNotNull('sent_at')
                ->where('sent_at', '<=', $received->format('Y-m-d H:i:s'))
                ->where('sent_at', '>=', $received->subHours(72)->format('Y-m-d H:i:s'))
                ->orderByDesc('sent_at')->limit(2)->get();
            if ($candidates->count() === 1) {
                $candidate = $candidates->first();
                if ($candidate !== null && (
                    $this->sameSubject($subject, $candidate)
                    || ($kind !== null && ! $genericAutoIgnored)
                )) {
                    $recipient = $candidate;
                    $method = 'sender_only';
                }
            }
        }

        if ($recipient === null) {
            // NOVO E-MAIL Re: <assunto exato>: registro SEM destinatario vinculado.
            // Mostra somente na revisao global (ou na campanha se unica),
            // nunca como resposta humana nem como prova de novo endereco.
            if ($ids === [] && ! $genericAutoIgnored
                && app(CampaignInboundUnlinkedNoticeRecorder::class)->record(
                    $mailbox, $validity, $uid, $message
                )) {
                return true;
            }

            return false; // Sem vinculo: nao inferir por assunto comum.
        }
        if ($genericAutoIgnored && (
            preg_match('/^\s*(?:re|res)\s*:/iu', $subject) === 1
            || ! $this->sameSubject($subject, $recipient)
        )) {
            return true; // Mantem filtro V2 para o generico com Re:.
        }
        if ($kind === null && $method === 'reference_exact') {
            // Respostas humanas normais com Re: e referencia exata continuam normais.
            if (preg_match('/^\s*(?:re|res)\s*:/iu', $subject) === 1
                || ! $this->sameSubject($subject, $recipient)) {
                return false;
            }
            // Assunto sem Re: igual ao enviado: pode ser caixa institucional.
            // Nao classificar automaticamente como humano.
            $kind = 'reply_needs_review';
        }
        if ($genericAutoIgnored && $this->sameSubject($subject, $recipient)) {
            $kind = 'reply_needs_review';
        }
        if ($kind === null) {
            $kind = 'reply_needs_review';
        }
        if (EmailCampaignReply::query()->where('mailbox', $mailbox)
            ->where('uid_validity', $validity)->where('imap_uid', $uid)->exists()) {
            return false; // Ja foi registrada como resposta humana; nao duplicar.
        }

        $requiresReview = $method !== 'reference_exact'
            || in_array($kind, ['address_change', 'routing', 'delivery_notice', 'reply_needs_review'], true);
        DB::table('email_campaign_inbound_notices')->insertOrIgnore([
            'email_campaign_id' => (int) $recipient->email_campaign_id,
            'email_campaign_recipient_id' => (int) $recipient->id,
            'mailbox' => $mailbox,
            'uid_validity' => $validity,
            'imap_uid' => $uid,
            'from_email' => $from,
            'subject' => $subject,
            'notice_type' => $kind,
            'link_method' => $method,
            'requires_review' => $requiresReview,
            'received_at' => $received,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        // Inclui entradas que ja existiam: nao voltar a tentar como resposta humana.
        return true;
    }

    /** @param array<string, mixed> $message */
    private function kind(array $message): ?string
    {
        $subject = mb_strtolower(trim((string) ($message['subject'] ?? '')));
        $auto = mb_strtolower(trim((string) ($message['auto_submitted'] ?? '')));
        $autoHeader = trim((string) ($message['x_autoreply'] ?? '')) !== ''
            || trim((string) ($message['x_autorespond'] ?? '')) !== '';
        $routingHeader = trim((string) ($message['x_ms_exchange_inbox_rules_loop'] ?? '')) !== '';

        if (preg_match('/f[eé]rias|out.of.office|aus[eê]ncia|ausente|vacation|fora do escrit[oó]rio|automatic reply/iu', $subject)) {
            return 'absence';
        }
        if (preg_match('/e-?mail (?:novo|alterado)|novo (?:endere[cç]o|e-?mail)|mudan[cç]a (?:de|do) (?:e-?mail|endere[cç]o)|endere[cç]o (?:alterado|novo)|altera[cç][aã]o (?:de|do) (?:e-?mail|endere[cç]o)/iu', $subject)) {
            return 'address_change';
        }
        if (preg_match('/redirecion|encaminhament|forwarding|redirect/iu', $subject) || $routingHeader) {
            return 'routing';
        }
        if (preg_match('/falha (?:na |de )?entrega|delivery (?:failure|failed)|undeliver|delivery status notification/iu', $subject)) {
            return 'delivery_notice'; // DSNs estruturados passam pelo processador de devolucoes ANTES.
        }
        if (preg_match('/mensagem autom[aá]tica|resposta autom[aá]tica|automatic response|recebemos sua mensagem|recebemos seu contato/iu', $subject)) {
            return 'automatic';
        }
        if (($auto !== '' && $auto !== 'no') || $autoHeader) {
            return 'automatic';
        }

        return null;
    }

    private function sameSubject(string $incoming, EmailCampaignRecipient $recipient): bool
    {
        $original = (string) ($recipient->campaign->subject ?? '');
        if ($original === '') {
            return false;
        }
        $original = app(CampaignContent::class)->render($original, $recipient);
        $trimmed = preg_replace('/^(?:(?:re|res|fw|fwd|enc)\s*:\s*)+/iu', '', $incoming) ?? $incoming;

        return mb_strtolower(trim($trimmed)) === mb_strtolower(trim($original));
    }

    private function receivedAt(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)->setTimezone('UTC')
                : CarbonImmutable::now('UTC');
        } catch (\Throwable) {
            return CarbonImmutable::now('UTC');
        }
    }
}
