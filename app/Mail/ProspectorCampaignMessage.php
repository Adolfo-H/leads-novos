<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

class ProspectorCampaignMessage extends Mailable
{
    public function __construct(
        public string $mailSubject,
        public string $mailBody,
        public ?string $unsubscribeUrl = null,
        public ?string $trackingMessageId = null,
        public bool $isTest = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('campaign_mail.from_address'),
                (string) config('campaign_mail.from_name', 'ExportControl'),
            ),
            replyTo: [(string) config('campaign_mail.reply_to')],
            subject: $this->mailSubject,
            using: [function (Email $email): void {
                if ($this->trackingMessageId !== null) {
                    // ID próprio para identificar respostas via In-Reply-To no IMAP.
                    $email->getHeaders()->addIdHeader('Message-ID', $this->trackingMessageId);
                }
                if ($this->unsubscribeUrl !== null) {
                    $email->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                    // EC_CAMPAIGN_RELEASE_V8: RFC 8058. O endpoint POST dispensa cookie/sessão.
                    $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }
                $email->getHeaders()->addTextHeader('X-ExportControl-Prospector', $this->isTest ? 'test' : 'campaign');
            }],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.prospector-campaign-html',
            text: 'mail.prospector-campaign-plain',
            with: [
                'messageBody' => $this->mailBody,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'isTest' => $this->isTest,
            ],
        );
    }
}
