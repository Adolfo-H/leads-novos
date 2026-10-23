<?php

namespace App\Mail;

use App\Models\AccountAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccessRequestSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AccountAccessRequest $accessRequest) {}

    public function build(): static
    {
        return $this->subject('Prospector: nova solicitação de acesso')
            ->view('mail.access-request-submitted')
            ->with(['managementUrl' => route('access.requests.index')]);
    }
}
