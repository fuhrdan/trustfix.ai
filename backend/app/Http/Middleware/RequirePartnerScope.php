<?php

namespace App\Http\Middleware;

use App\Models\PartnerApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePartnerScope
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $client = $request->attributes->get('partner_client');
        if (! $client instanceof PartnerApiClient) {
            return response()->json(['message' => 'Invalid partner API credentials.'], 401);
        }

        foreach ($requiredScopes as $scope) {
            if (! $client->hasScope($scope)) {
                return response()->json([
                    'message' => 'This partner credential does not have the required permission.',
                    'required_scope' => $scope,
                ], 403);
            }
        }

        return $next($request);
    }
}
