<?php

namespace App\Services;

use App\Models\BlockedIp;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LoginSecurityService
{
    public function clientIp(Request $request): string
    {
        $fallback = (string) $request->ip();
        $forwardedIp = trim((string) $request->input('_client_ip', ''));
        $timestamp = (int) $request->input('_client_ip_ts', 0);
        $signature = trim((string) $request->input('_client_ip_sig', ''));
        $secret = (string) config('login_security.proxy_secret', '');

        if (
            $secret === ''
            || filter_var($forwardedIp, FILTER_VALIDATE_IP) === false
            || $timestamp <= 0
            || abs(now()->timestamp - $timestamp) > 300
            || $signature === ''
        ) {
            return $fallback;
        }

        $expected = hash_hmac('sha256', $forwardedIp.'|'.$timestamp, $secret);

        return hash_equals($expected, $signature)
            ? $forwardedIp
            : $fallback;
    }

    public function activeBlock(string $ipAddress): ?BlockedIp
    {
        try {
            $block = BlockedIp::where('ip_address', $ipAddress)
                ->where('active', true)
                ->where(function ($query): void {
                    $query->whereNull('blocked_until')
                        ->orWhere('blocked_until', '>', now());
                })
                ->first();

            if ($block) {
                return $block;
            }

            BlockedIp::where('ip_address', $ipAddress)
                ->where('active', true)
                ->whereNotNull('blocked_until')
                ->where('blocked_until', '<=', now())
                ->update([
                    'active' => false,
                    'unblocked_at' => now(),
                ]);
        } catch (Throwable $exception) {
            // Fail open if deployment has not completed the migrations yet.
            Log::warning('Login security block lookup failed.', [
                'ip_address' => $ipAddress,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    public function recordAttempt(
        Request $request,
        ?User $user,
        ?string $email,
        bool $successful,
        string $outcome
    ): ?LoginAttempt {
        try {
            $ipAddress = $this->clientIp($request);
            $windowStart = now()->subMinutes(max(1, (int) config('login_security.risk_window_minutes', 15)));
            $normalizedEmail = $email !== null
                ? Str::lower(trim($email))
                : null;

            $recentFailures = LoginAttempt::where('ip_address', $ipAddress)
                ->where('successful', false)
                ->where('created_at', '>=', $windowStart)
                ->count();

            if (!$successful) {
                $recentFailures++;
            }

            $targetedQuery = LoginAttempt::where('ip_address', $ipAddress)
                ->where('created_at', '>=', $windowStart)
                ->whereNotNull('email');

            $targetedAccounts = $targetedQuery
                ->distinct()
                ->count('email');

            if ($normalizedEmail !== null) {
                $alreadyCounted = LoginAttempt::where('ip_address', $ipAddress)
                    ->where('created_at', '>=', $windowStart)
                    ->where('email', $normalizedEmail)
                    ->exists();

                if (!$alreadyCounted) {
                    $targetedAccounts++;
                }
            }

            [$riskLevel, $riskScore] = $this->calculateRisk(
                $successful,
                $outcome,
                $user,
                $recentFailures,
                $targetedAccounts
            );

            return LoginAttempt::create([
                'user_id' => $user?->id,
                'email' => $normalizedEmail,
                'ip_address' => $ipAddress,
                'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
                'successful' => $successful,
                'outcome' => $outcome,
                'risk_level' => $riskLevel,
                'risk_score' => $riskScore,
                'recent_ip_failures' => min($recentFailures, 65535),
                'targeted_accounts' => min($targetedAccounts, 65535),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // A logging failure must never make authentication unavailable.
            Log::warning('Login security attempt logging failed.', [
                'email' => $email,
                'ip_address' => $request->ip(),
                'outcome' => $outcome,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function calculateRisk(
        bool $successful,
        string $outcome,
        ?User $user,
        int $recentFailures,
        int $targetedAccounts
    ): array {
        if ($outcome === 'blocked_ip') {
            return ['high', 100];
        }

        if ($outcome === 'rate_limited') {
            return ['high', 90];
        }

        $score = $successful ? 0 : 10;

        if (!$successful && $user === null) {
            $score += 15;
        }

        $highFailures = max(1, (int) config('login_security.high_failures', 5));
        $elevatedFailures = max(1, (int) config('login_security.elevated_failures', 3));
        $targetThreshold = max(2, (int) config('login_security.credential_stuffing_accounts', 3));

        if ($recentFailures >= $highFailures) {
            $score += 50;
        } elseif ($recentFailures >= $elevatedFailures) {
            $score += 25;
        }

        if ($targetedAccounts >= $targetThreshold) {
            $score += 35;
        }

        $score = min(100, $score);

        if ($score >= 60) {
            return ['high', $score];
        }

        if ($score >= 30) {
            return ['elevated', $score];
        }

        return ['normal', $score];
    }
}
