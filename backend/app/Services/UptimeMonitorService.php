<?php

namespace App\Services;

use App\Models\OperationRun;
use App\Models\UptimeCheck;
use Illuminate\Support\Facades\Http;
use Throwable;

class UptimeMonitorService
{
    public function __construct(
        private readonly OperationsAlertService $alerts,
    ) {
    }

    public function run(): array
    {
        $startedAt = now();
        $clockStartedAt = microtime(true);
        $run = OperationRun::create([
            'operation' => 'uptime_monitor',
            'status' => 'running',
            'started_at' => $startedAt,
            'summary' => 'Uptime checks are running.',
        ]);
        $checks = [];

        try {
            foreach ((array) config('operations.monitoring.targets', []) as $key => $target) {
                $url = trim((string) ($target['url'] ?? ''));

                if ($url === '') {
                    continue;
                }

                $checks[] = $this->checkTarget(
                    (string) $key,
                    (string) ($target['name'] ?? $key),
                    $url
                );
            }

            $upCount = count(array_filter(
                $checks,
                static fn (UptimeCheck $check): bool => $check->status === 'up'
            ));
            $allUp = $upCount === count($checks) && count($checks) > 0;

            $run->update([
                'status' => $allUp ? 'success' : 'failed',
                'finished_at' => now(),
                'duration_ms' => $this->elapsedMilliseconds($clockStartedAt),
                'summary' => sprintf('%d of %d monitored services are available.', $upCount, count($checks)),
                'details' => [
                    'targets_checked' => count($checks),
                    'targets_up' => $upCount,
                ],
            ]);

            UptimeCheck::where(
                'checked_at',
                '<',
                now()->subDays((int) config('operations.monitoring.retention_days', 30))
            )->delete();

            return $checks;
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => $this->elapsedMilliseconds($clockStartedAt),
                'summary' => 'The uptime monitor could not complete.',
                'details' => ['error' => mb_substr($exception->getMessage(), 0, 1000)],
            ]);

            throw $exception;
        }
    }

    private function checkTarget(string $key, string $name, string $url): UptimeCheck
    {
        $previous = UptimeCheck::where('target_key', $key)->latest('id')->first();
        $lastOutageAlertId = (int) UptimeCheck::where('target_key', $key)
            ->where('alert_sent', true)
            ->where('status', 'down')
            ->max('id');
        $lastRecoveryAlertId = (int) UptimeCheck::where('target_key', $key)
            ->where('alert_sent', true)
            ->where('status', 'up')
            ->max('id');
        $outageAlerted = $lastOutageAlertId > $lastRecoveryAlertId;
        $clockStartedAt = microtime(true);
        $status = 'down';
        $statusCode = null;
        $error = null;

        try {
            $response = Http::connectTimeout(
                (int) config('operations.monitoring.connect_timeout_seconds', 4)
            )->timeout(
                (int) config('operations.monitoring.timeout_seconds', 8)
            )->withHeaders([
                'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8',
                'User-Agent' => 'TrustFix-Uptime-Monitor/1.0',
            ])->get($url);

            $statusCode = $response->status();
            $status = $statusCode >= 200 && $statusCode < 400 ? 'up' : 'down';
            $error = $status === 'down' ? 'Server returned HTTP '.$statusCode.'.' : null;
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 1000);
        }

        $failures = $status === 'down'
            ? (($previous?->status === 'down' ? $previous->consecutive_failures : 0) + 1)
            : 0;
        $threshold = (int) config('operations.monitoring.failure_threshold', 2);
        $sendOutageAlert = $status === 'down' && $failures >= $threshold && !$outageAlerted;
        $check = UptimeCheck::create([
            'target_key' => $key,
            'target_name' => $name,
            'target_url' => $this->safeUrl($url),
            'status' => $status,
            'status_code' => $statusCode,
            'response_time_ms' => $this->elapsedMilliseconds($clockStartedAt),
            'error_message' => $error,
            'consecutive_failures' => $failures,
            'alert_sent' => $sendOutageAlert,
            'checked_at' => now(),
        ]);

        if ($sendOutageAlert) {
            $this->alerts->send($name.' appears unavailable', [
                $name.' failed '.$failures.' consecutive uptime checks.',
                'Endpoint: '.$this->safeUrl($url),
                'HTTP status: '.($statusCode ?? 'No response'),
                'Error: '.($error ?? 'Unknown error'),
                'Time: '.now()->toIso8601String(),
            ]);
        } elseif ($status === 'up' && $previous?->status === 'down' && $outageAlerted) {
            $this->alerts->send($name.' has recovered', [
                $name.' is responding again.',
                'Endpoint: '.$this->safeUrl($url),
                'HTTP status: '.$statusCode,
                'Response time: '.$check->response_time_ms.' ms',
                'Time: '.now()->toIso8601String(),
            ]);

            $check->update(['alert_sent' => true]);
        }

        return $check;
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return mb_substr($url, 0, 2048);
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/');
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
