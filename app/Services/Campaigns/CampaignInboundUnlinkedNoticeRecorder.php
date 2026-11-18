<?php

namespace App\Services\Campaigns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda somente avisos de mudanca de endereco com referencia EXPLICITA ao
 * assunto de um envio real. Sem Message-ID/remetente confirmado, o aviso NAO
 * pertence a destinatario algum nem altera status, respostas ou follow-ups.
 *
 * Nunca le corpo/HTML do Outlook. Revisao comercial obrigatoria.
 */
final class CampaignInboundUnlinkedNoticeRecorder
{
    /** @param array<string, mixed> $message */
    public function record(string $mailbox, string $validity, int $uid, array $message): bool
    {
        if ($uid < 1 || $validity === '' || ! ctype_digit($validity) || strlen($validity) > 40
            || ! Schema::hasTable('email_campaign_unlinked_notices')
            || CampaignInboundNoticeFilter::isTechnical($message)) {
            return false;
        }

        $sender = CampaignAudience::normalizedEmail((string) ($message['from_email'] ?? ''));
        if (filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $subject = mb_substr(trim((string) ($message['subject'] ?? '')), 0, 255);
        // Somente assunto de MUDANCA de endereco + assunto original com RE:
        // Exemplo: ATENCAO - NOVO E-MAIL Re: Vendas para tradings: ...
        if (preg_match('/(?:novo\s+e-?mail|e-?mail\s+(?:novo|alterado)|altera[cç][aã]o\s+(?:de|do)\s+e-?mail|mudan[cç]a\s+de\s+e-?mail)/iu', $subject) !== 1
            || preg_match('/\b(?:re|res)\s*:\s*(.+)$/iu', $subject, $match) !== 1) {
            return false;
        }
        $originalSubject = trim($match[1]);
        if (mb_strlen($originalSubject) < 12) {
            return false;
        }

        $receivedRaw = $message['received_at'] ?? null;
        if (! is_string($receivedRaw) || trim($receivedRaw) === '') {
            return false;
        }
        try {
            $received = CarbonImmutable::parse($receivedRaw)->setTimezone('UTC');
        } catch (\Throwable) {
            return false;
        }
        $since = $received->subDays(7);
        // Correspondencia de assunto so torna a mensagem CANDIDATA, nao resposta.
        // Sem envio real recente com assunto exato, nao coletar mensagem pessoal.
        $campaignIds = DB::table('email_campaigns as c')
            ->where('c.subject', $originalSubject)
            ->whereExists(static function (Builder $q) use ($since, $received): void {
                $q->selectRaw('1')->from('email_campaign_recipients as r')
                    ->whereColumn('r.email_campaign_id', 'c.id')
                    ->whereNotNull('r.sent_at')
                    ->where('r.sent_at', '>=', $since)
                    ->where('r.sent_at', '<=', $received);
            })
            ->orderByDesc('c.id')->limit(3)->pluck('c.id');
        if ($campaignIds->isEmpty()) {
            return false;
        }

        // Uma campanha so aparece vinculada na tela individual se for UNICA.
        // Com varias campanhas de mesmo assunto, mostrar apenas em Respostas.
        $candidateId = $campaignIds->count() === 1 ? (int) $campaignIds->first() : null;
        DB::table('email_campaign_unlinked_notices')->insertOrIgnore([
            'candidate_campaign_id' => $candidateId,
            'mailbox' => $mailbox,
            'uid_validity' => $validity,
            'imap_uid' => $uid,
            'from_email' => $sender,
            'subject' => $subject,
            'notice_type' => 'address_change',
            'received_at' => $received,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return true;
    }
}
