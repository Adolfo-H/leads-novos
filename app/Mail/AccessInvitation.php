<?php

namespace App\Mail;

use App\Models\AccountAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccessInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccountAccessRequest $accessRequest,
        public string $invitationUrl,
    ) {}

    public function build(): static
    {
        return $this->subject('Seu convite para o ExportControl Prospector')
            ->view('mail.access-invitation');
    }
}
