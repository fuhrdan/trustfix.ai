<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user('api') ?? Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'error' => 'Unauthenticated',
            ], 401);
        }

        if (! $user->hasAnyRole($roles)) {
            return response()->json([
                'error' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}
