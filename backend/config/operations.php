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
    'backups' => [
        'enabled' => $boolean('TRUSTFIX_BACKUP_ENABLED', true),
        'disk' => env('TRUSTFIX_BACKUP_DISK', 'private'),
        'directory' => trim(env('TRUSTFIX_BACKUP_DIRECTORY', 'backups/database'), '/'),
        'schedule' => env('TRUSTFIX_BACKUP_SCHEDULE', '30 6 * * *'),
        'retention_days' => max(1, (int) env('TRUSTFIX_BACKUP_RETENTION_DAYS', 14)),
        'history_days' => max(30, (int) env('TRUSTFIX_OPERATION_HISTORY_DAYS', 90)),
        'binary' => env('TRUSTFIX_MYSQLDUMP_BINARY', 'mysqldump'),
        'no_tablespaces' => $boolean('TRUSTFIX_BACKUP_NO_TABLESPACES', true),
        'timeout_seconds' => max(30, (int) env('TRUSTFIX_BACKUP_TIMEOUT_SECONDS', 300)),
    ],

    'monitoring' => [
        'enabled' => $boolean('TRUSTFIX_UPTIME_MONITORING_ENABLED', true),
        'schedule' => env('TRUSTFIX_UPTIME_SCHEDULE', '*/5 * * * *'),
        'timeout_seconds' => max(2, (int) env('TRUSTFIX_UPTIME_TIMEOUT_SECONDS', 8)),
        'connect_timeout_seconds' => max(1, (int) env('TRUSTFIX_UPTIME_CONNECT_TIMEOUT_SECONDS', 4)),
        'failure_threshold' => max(1, (int) env('TRUSTFIX_UPTIME_FAILURE_THRESHOLD', 2)),
        'retention_days' => max(1, (int) env('TRUSTFIX_UPTIME_RETENTION_DAYS', 30)),
        'targets' => [
            'frontend' => [
                'name' => 'TrustFix web application',
                'url' => env('TRUSTFIX_MONITOR_FRONTEND_URL', 'https://trustfix.lakehousesoftware.com/login.php'),
            ],
            'api' => [
                'name' => 'TrustFix API',
                'url' => env('TRUSTFIX_MONITOR_API_URL', 'https://api.lakehousesoftware.com/up'),
            ],
        ],
    ],

    'audit' => [
        'retention_days' => max(30, (int) env('TRUSTFIX_AUDIT_RETENTION_DAYS', 365)),
        'sensitive_fields' => [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'authorization',
            'secret',
            'api_key',
            'description',
            'details',
            'admin_notes',
            'message',
        ],
    ],

    'support' => [
        'email' => env(
            'TRUSTFIX_OPERATIONS_EMAIL',
            env('TRUSTFIX_SUPPORT_EMAIL', 't.tyler@trustfixai.com')
        ),
        'schedule' => env('TRUSTFIX_SUPPORT_ESCALATION_SCHEDULE', '*/15 * * * *'),
        'normal_response_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_NORMAL_RESPONSE_HOURS', 24)),
        'normal_resolution_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_NORMAL_RESOLUTION_HOURS', 72)),
        'high_response_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_HIGH_RESPONSE_HOURS', 4)),
        'high_resolution_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_HIGH_RESOLUTION_HOURS', 24)),
        'urgent_response_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_URGENT_RESPONSE_HOURS', 1)),
        'urgent_resolution_hours' => max(1, (int) env('TRUSTFIX_SUPPORT_URGENT_RESOLUTION_HOURS', 4)),
    ],
];
