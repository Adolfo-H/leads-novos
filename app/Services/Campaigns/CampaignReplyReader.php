<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignReply;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
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
        $body = $this->fetch((string) $reply->mailbox, (string) $reply->uid_validity, (int) $reply->imap_uid);
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
        $mailbox = (string) config('campaign_mail.imap_username');
        $body = $this->fetch($mailbox, (string) $test->reply_uid_validity, (int) $test->reply_imap_uid);
        $test->update(['reply_body_text' => $body, 'reply_body_loaded_at' => now('UTC')]);

        return $body;
    }

    private function authorize(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isCommercialManager(), 403);
    }

    private function fetch(string $mailbox, string $validity, int $uid): string
    {
        if (! config('campaign_mail.imap_enabled') || $uid < 1 || $validity === '') {
            throw ValidationException::withMessages(['reply' => 'IMAP desabilitado ou mensagem sem identificador válido.']);
        }
        $configuredMailbox = (string) config('campaign_mail.imap_username', '');
        if ($mailbox !== $configuredMailbox) {
            throw ValidationException::withMessages(['reply' => 'Esta resposta não pertence à caixa IMAP autorizada.']);
        }
        $args = [
            'host' => (string) config('campaign_mail.imap_host'),
            'port' => (int) config('campaign_mail.imap_port', 993),
            'username' => $configuredMailbox,
            'password' => (string) config('campaign_mail.imap_password'),
            'folder' => (string) config('campaign_mail.imap_folder', 'INBOX'),
            'uidvalidity' => $validity,
            'uid' => $uid,
        ];
        $process = new Process([
            (string) config('campaign_mail.python', 'python3'),
            base_path('tools/email/imap_fetch_body.py'),
        ], base_path());
        $process->setTimeout(40);
        $process->setInput(json_encode($args, JSON_THROW_ON_ERROR));
        $process->run();
        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages(['reply' => 'Não foi possível recuperar o texto desta resposta. Confira o IMAP e tente novamente.']);
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
