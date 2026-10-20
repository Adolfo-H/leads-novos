<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/** Resposta humana para uma conversa comercial existente; não é campanha em massa. */
final class ProspectorHumanReplyMessage extends Mailable
{
    public function __construct(
        public string $subjectText,
        public string $bodyText,
        public ?string $replyMessageId,
        public ?string $originalMessageId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('campaign_mail.from_address'), (string) config('campaign_mail.from_name', 'ExportControl')),
            replyTo: [(string) config('campaign_mail.reply_to')],
            subject: $this->subjectText,
            using: [function (Email $email): void {
                $reply = $this->validMessageId($this->replyMessageId);
                $original = $this->validMessageId($this->originalMessageId);
                if ($reply !== null) {
                    $email->getHeaders()->addIdHeader('In-Reply-To', $reply);
                }
                $references = array_values(array_unique(array_filter([$original, $reply])));
                if ($references !== []) {
                    $email->getHeaders()->addIdHeader('References', $references);
                }
                $email->getHeaders()->addTextHeader('X-ExportControl-Prospector', 'human-reply');
            }],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.prospector-human-reply-text', with: ['messageBody' => $this->bodyText]);
    }

    private function validMessageId(?string $input): ?string
    {
        $id = trim((string) $input, '<> ');

        return preg_match('/^[A-Za-z0-9._+\-]+@[A-Za-z0-9.\-]+$/D', $id) === 1 ? $id : null;
    }
}
