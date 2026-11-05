<?php

namespace App\Http\Middleware;

use App\Models\PartnerApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePartnerApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('partner_api.enabled')) {
            return response()->json([
                'message' => 'The partner API is not enabled.',
            ], 503);
        }

        $token = $this->bearerToken($request);
        if ($token === null) {
            return $this->unauthorized();
        }

        $client = PartnerApiClient::find($token['client_id']);
        if (! $client || ! $client->active || ! Hash::check($token['raw'], $client->api_token_hash)) {
            return $this->unauthorized();
        }

        $rateLimit = max(1, (int) config('partner_api.rate_limit_per_minute', 120));
        $rateKey = 'partner-api:'.$client->id;
        if (RateLimiter::tooManyAttempts($rateKey, $rateLimit)) {
            return response()->json([
                'message' => 'Partner API rate limit exceeded.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // Persist an operational marker at most once every five minutes, not
        // once per request, so a healthy integration does not create write load.
        if (Cache::add('partner-api-last-used:'.$client->id, true, now()->addMinutes(5))) {
            $client->forceFill(['last_used_at' => now()])->save();
        }

        $request->attributes->set('partner_client', $client);

        return $next($request);
    }

    /** @return array{client_id:string,raw:string}|null */
    private function bearerToken(Request $request): ?array
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (! preg_match('/^Bearer\\s+(tfp_([0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})_([A-Za-z0-9_-]{32,128}))$/i', $authorization, $matches)) {
            return null;
        }

        return [
            'client_id' => strtolower($matches[2]),
            'raw' => $matches[1],
        ];
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'message' => 'Invalid partner API credentials.',
        ], 401);
    }
}
