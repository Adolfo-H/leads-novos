<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * IMAP sob demanda: apenas UIDs de respostas JÁ associadas pelo sincronizador.
 * Nunca pesquisa a caixa pessoal, salva anexos ou renderiza HTML remoto.
 */
final class CampaignReplyReader
{
    public function readCampaignReply(int $id): string
    {
        $this->authorize();
        $reply = EmailCampaignReply::query()->findOrFail($id);
        $existing = $reply->getAttribute('body_text');
        if (is_string($existing)) {
            return $existing;
        }
        $body = $this->fetch((string) $reply->mailbox, (string) $reply->uid_validity, (int) $reply->imap_uid, (string) $reply->from_email);
        $reply->update(['body_text' => $body, 'body_loaded_at' => now('UTC')]);

        return $body;
    }

    public function readPilotReply(int $id): string
    {
        $this->authorize();
        $test = EmailCampaignTestMessage::query()->findOrFail($id);
        abort_unless($test->replied_at !== null && $test->reply_imap_uid !== null
            && $test->reply_uid_validity !== null, 404);
        $existing = $test->getAttribute('reply_body_text');
        if (is_string($existing)) {
            return $existing;
        }
        // EC_REPLY_MAILBOX_V2_3: old diagnostics encode their intended account in
        // Message-ID. The Python reader must verify sender and reference BEFORE caching.
        $mailbox = CampaignReplyMailbox::testMailboxForRead($test);
        $body = $this->fetch($mailbox, (string) $test->reply_uid_validity, (int) $test->reply_imap_uid, (string) $test->test_to, (string) $test->message_id);
        $fields = ['reply_body_text' => $body, 'reply_body_loaded_at' => now('UTC')];
        if (Schema::hasColumn('email_campaign_test_messages', 'reply_mailbox')) {
            $fields['reply_mailbox'] = $mailbox;
        }
        $test->update($fields);

        return $body;
    }

    /** Read only a linked, visible useful notice; never promote or send it. */
    public function readNotice(int $id): string
    {
        $this->authorize();
        $notice = CampaignInboundNoticeFilter::query()->where('id', $id)
            ->whereNotNull('email_campaign_recipient_id')->first();
        abort_unless($notice !== null, 404);

        return $this->fetch((string) $notice->mailbox, (string) $notice->uid_validity, (int) $notice->imap_uid, (string) $notice->from_email);
    }

    private function authorize(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    private function fetch(string $mailbox, string $validity, int $uid, string $expectedFrom, string $expectedReference = ''): string
    {
        if ($uid < 1 || ! ctype_digit($validity)) {
            throw ValidationException::withMessages(['reply' => 'Mensagem sem UID ou identificação IMAP válida.']);
        }
        $connection = CampaignReplyMailbox::imap($mailbox);
        if ($connection === null) {
            throw ValidationException::withMessages(['reply' => 'Caixa IMAP não configurada ou não autorizada para leitura: confira as configurações locais.']);
        }
        $args = $connection + [
            'uidvalidity' => $validity,
            'uid' => $uid,
            'expected_from' => $expectedFrom,
            'expected_reference' => $expectedReference,
        ];
        $process = new Process([
            (string) config('campaign_mail.python', 'python3'),
            base_path('tools/email/imap_fetch_body.py'),
        ], base_path());
        $process->setTimeout(40);
        $process->setInput(json_encode($args, JSON_THROW_ON_ERROR));
        try {
            $process->run();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['reply' => 'O servidor IMAP não respondeu à leitura desta mensagem. Confira a conexão da caixa indicada.']);
        }
        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $reason = match ($error) {
                'IMAP_BODY_ERROR:MailboxIdentityMismatch' => 'A mensagem não corresponde ao remetente ou à referência registrada. Sincronize novamente a caixa, sem alterar o UID manualmente.',
                'IMAP_BODY_ERROR:MailboxValidityMismatch' => 'A identificação da caixa mudou (UIDVALIDITY). A mensagem precisa de nova conciliação.',
                'IMAP_BODY_ERROR:MessageUnavailable' => 'A mensagem não está mais disponível no INBOX com o UID registrado. Confira se foi movida no Outlook.',
                'IMAP_BODY_ERROR:NoTextBody' => 'A mensagem não contém texto recuperável no limite de leitura.',
                default => 'Não foi possível recuperar o texto pela caixa indicada. Confira a conexão IMAP ou se a mensagem ainda está no INBOX.',
            };
            throw ValidationException::withMessages(['reply' => $reason]);
        }
        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['body']) || ! is_string($decoded['body'])) {
            throw ValidationException::withMessages(['reply' => 'O servidor IMAP retornou um formato inesperado.']);
        }
        $text = trim(mb_substr($decoded['body'], 0, 32000));
        if ($text === '') {
            throw ValidationException::withMessages(['reply' => 'Esta mensagem não contém texto disponível para leitura.']);
        }

        return $text;
    }
}
