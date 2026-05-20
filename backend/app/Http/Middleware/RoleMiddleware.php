<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user('api');

        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated',
            ], 401);
        }

        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'error' => 'Forbidden',
            ], 403);
        }

        return $next($request);
    }
}