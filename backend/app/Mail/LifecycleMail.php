<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LifecycleMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $recipient,
        public string $subjectLine,
        public string $headline,
        public string $intro,
        public string $preheader,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        public array $details = [],
        public ?string $notice = null,
    ) {
        $this->afterCommit();
    }

    public function build()
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.lifecycle');
    }

    public function attachments(): array
    {
        return [];
    }
}
