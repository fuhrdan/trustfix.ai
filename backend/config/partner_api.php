<?php

$boolean = static function (string $key, bool $default): bool {
    $value = env($key, $default);

    if (is_bool($value)) {
        return $value;
    }

    return match (strtolower(trim((string) $value))) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => $default,
    };
};

return [
    // Partner API routes return 503 until this is explicitly enabled.
    'enabled' => $boolean('PARTNER_API_ENABLED', false),
    'rate_limit_per_minute' => max(1, (int) env('PARTNER_API_RATE_LIMIT_PER_MINUTE', 120)),

    // Webhooks are independently gated because they are outbound requests.
    'webhooks_enabled' => $boolean('PARTNER_WEBHOOKS_ENABLED', false),
    'webhook_timeout_seconds' => max(2, (int) env('PARTNER_WEBHOOK_TIMEOUT_SECONDS', 10)),
    'webhook_allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('PARTNER_WEBHOOK_ALLOWED_HOSTS', ''))
    ))),

    'allowed_scopes' => [
        'service_requests:read',
        'service_requests:write',
        'service_requests:schedule',
        'members:read',
    ],
];
