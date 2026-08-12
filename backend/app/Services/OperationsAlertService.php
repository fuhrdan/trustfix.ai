<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OperationsAlertService
{
    public function send(string $subject, array $lines): bool
    {
        $recipient = trim((string) config('operations.support.email'));

        if ($recipient === '') {
            Log::warning('TrustFix operations alert was not sent because no recipient is configured.', [
                'subject' => $subject,
            ]);

            return false;
        }

        return $this->sendTo($recipient, $subject, $lines);
    }

    public function sendTo(string $recipient, string $subject, array $lines): bool
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            return false;
        }

        $body = implode("\n", array_filter(array_map(
            static fn ($line): string => trim((string) $line),
            $lines
        ), static fn (string $line): bool => $line !== ''));

        try {
            Mail::raw($body, function ($message) use ($recipient, $subject): void {
                $message->to($recipient)->subject('[TrustFix] '.$subject);
            });

            return true;
        } catch (Throwable $exception) {
            Log::error('TrustFix operations alert could not be sent.', [
                'subject' => $subject,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
