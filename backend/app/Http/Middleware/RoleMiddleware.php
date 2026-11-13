<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize a route when the authenticated user has any requested capability.
 *
 * Route declarations remain `role:customer,handyman`; User::hasAnyRole()
 * resolves both additive assignments and the compatibility primary role.
 */
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
