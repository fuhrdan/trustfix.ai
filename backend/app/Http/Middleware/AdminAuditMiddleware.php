<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AdminAuditMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->user('api')?->role !== 'admin') {
            return $next($request);
        }

        $response = null;
        $statusCode = 500;

        try {
            $response = $next($request);
            $statusCode = $response->getStatusCode();

            return $response;
        } finally {
            $this->record($request, $statusCode);
        }
    }

    private function record(Request $request, int $statusCode): void
    {
        try {
            $administrator = $request->user('api');
            $route = $request->route();
            $routePath = $route?->uri() ?? $request->path();
            $resourceType = $this->resourceType($routePath);
            $resourceId = $this->resourceId($route?->parameters() ?? []);
            $actionMethod = $route?->getActionMethod() ?? strtolower($request->method());
            $action = implode('.', array_filter([
                'admin',
                $resourceType ? Str::snake($resourceType) : null,
                Str::snake($actionMethod),
            ]));

            AdminAuditLog::create([
                'request_uuid' => (string) Str::uuid(),
                'admin_user_id' => $administrator?->id,
                'action' => mb_substr($action, 0, 180),
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'http_method' => $request->method(),
                'route_path' => mb_substr($routePath, 0, 512),
                'route_name' => $route?->getName(),
                'status_code' => $statusCode,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'summary' => mb_substr(sprintf(
                    '%s %s %s (HTTP %d)',
                    $administrator?->email ?? 'Unknown administrator',
                    $request->method(),
                    $routePath,
                    $statusCode
                ), 0, 500),
                'metadata' => $this->sanitizedInput($request->all()),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('An administrator action could not be written to the audit log.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function sanitizedInput(array $input): array
    {
        $sensitive = array_map(
            'strtolower',
            (array) config('operations.audit.sensitive_fields', [])
        );

        $sanitize = function (array $values) use (&$sanitize, $sensitive): array {
            $clean = [];

            foreach ($values as $key => $value) {
                if (in_array(strtolower((string) $key), $sensitive, true)) {
                    $clean[$key] = '[redacted]';
                    continue;
                }

                if (is_array($value)) {
                    $clean[$key] = $sanitize($value);
                } elseif (is_scalar($value) || $value === null) {
                    $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
                }
            }

            return $clean;
        };

        return $sanitize($input);
    }

    private function resourceType(string $routePath): ?string
    {
        $segments = explode('/', trim($routePath, '/'));
        $adminIndex = array_search('admin', $segments, true);

        if ($adminIndex !== false && isset($segments[$adminIndex + 1])) {
            return mb_substr(str_replace(['{', '}'], '', $segments[$adminIndex + 1]), 0, 100);
        }

        foreach ($segments as $segment) {
            if ($segment === 'api' || str_starts_with($segment, '{')) {
                continue;
            }

            return mb_substr($segment, 0, 100);
        }

        return null;
    }

    private function resourceId(array $parameters): ?string
    {
        foreach ($parameters as $key => $parameter) {
            if (
                !in_array((string) $key, ['id', 'user', 'job', 'case', 'report', 'document', 'review'], true)
                && !preg_match('/id$/i', (string) $key)
            ) {
                continue;
            }

            if (is_scalar($parameter)) {
                return mb_substr((string) $parameter, 0, 100);
            }

            if (is_object($parameter) && method_exists($parameter, 'getKey')) {
                return mb_substr((string) $parameter->getKey(), 0, 100);
            }
        }

        return null;
    }
}
