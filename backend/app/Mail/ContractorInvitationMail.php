<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContractorInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $inviteUrl,
        public string $expiresAt,
    ) {
        $this->afterCommit();
    }

    public function build()
    {
        return $this
            ->subject('You are invited to join TrustFix as a contractor')
            ->view('emails.contractor-invitation');
    }
}
