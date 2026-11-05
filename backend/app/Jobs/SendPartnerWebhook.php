<?php

namespace App\Jobs;

use App\Services\PartnerWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPartnerWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $partnerServiceRequestId,
        public readonly string $event,
    ) {}

    public function handle(PartnerWebhookService $webhooks): void
    {
        $webhooks->send($this->partnerServiceRequestId, $this->event);
    }
}
