<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api') ?? Auth::guard('api')->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (($user->account_status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'This account is not currently active. Contact TrustFix for assistance.',
            ], 403);
        }

        return $next($request);
    }
}
