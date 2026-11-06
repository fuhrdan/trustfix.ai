<?php

namespace App\Services;

use App\Jobs\SendPartnerWebhook;
use App\Models\PartnerServiceRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PartnerWebhookService
{
    public function dispatchStatus(PartnerServiceRequest $serviceRequest, string $event): void
    {
        if (! config('partner_api.webhooks_enabled')) {
            return;
        }

        $serviceRequest->loadMissing('partnerClient');
        if (! $serviceRequest->partnerClient?->active || empty($serviceRequest->partnerClient->webhook_url)) {
            return;
        }

        SendPartnerWebhook::dispatch($serviceRequest->id, $event);
    }

    public function recordJobStatusChange(int $jobId, string $event = 'service_request.status_changed'): void
    {
        $requests = PartnerServiceRequest::query()
            ->where('job_id', $jobId)
            ->with('partnerClient')
            ->get();

        foreach ($requests as $request) {
            $request->forceFill(['last_status_at' => now()])->save();
            $this->dispatchStatus($request, $event);
        }
    }

    public function send(int $serviceRequestId, string $event): void
    {
        if (! config('partner_api.webhooks_enabled')) {
            return;
        }

        $serviceRequest = PartnerServiceRequest::query()
            ->with(['partnerClient', 'job'])
            ->find($serviceRequestId);
        $client = $serviceRequest?->partnerClient;

        if (! $serviceRequest || ! $client || ! $client->active || ! $client->webhook_url || ! $client->webhook_secret) {
            return;
        }

        if (! $this->isSafeWebhookUrl($client->webhook_url)) {
            Log::warning('Partner webhook was blocked by outbound URL policy.', [
                'partner_api_client_id' => $client->id,
                'partner_service_request_id' => $serviceRequest->id,
            ]);

            return;
        }

        $payload = json_encode([
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'external_request_id' => $serviceRequest->external_request_id,
                'trustfix_job_id' => $serviceRequest->job_id,
                'status' => $serviceRequest->job?->status,
                'scheduled_for' => $serviceRequest->scheduled_for?->toIso8601String(),
                'scheduling_reference' => $serviceRequest->scheduling_reference,
                'status_updated_at' => $serviceRequest->last_status_at?->toIso8601String(),
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $client->webhook_secret);

        $response = Http::acceptJson()
            ->timeout(max(2, (int) config('partner_api.webhook_timeout_seconds', 10)))
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-TrustFix-Event' => $event,
                'X-TrustFix-Timestamp' => $timestamp,
                'X-TrustFix-Signature' => 'sha256='.$signature,
            ])
            ->withBody($payload, 'application/json')
            ->send('POST', $client->webhook_url);

        if (! $response->successful()) {
            throw new RuntimeException('Partner webhook returned HTTP '.$response->status().'.');
        }
    }

    public function isSafeWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $allowedHosts = (array) config('partner_api.webhook_allowed_hosts', []);
        if ($allowedHosts !== [] && ! in_array($host, $allowedHosts, true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        // A deployment-level exact host allow-list is an intentional trust
        // boundary. Otherwise resolve DNS and reject any private/reserved IP.
        if ($allowedHosts !== []) {
            return true;
        }

        $records = array_merge(
            dns_get_record($host, DNS_A) ?: [],
            dns_get_record($host, DNS_AAAA) ?: []
        );
        if ($records === []) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($ip) || ! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
