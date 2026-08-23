<?php

return [
    'proxy_secret' => (string) env('LOGIN_SECURITY_PROXY_SECRET', ''),
    'risk_window_minutes' => (int) env('LOGIN_SECURITY_RISK_WINDOW_MINUTES', 15),
    'elevated_failures' => (int) env('LOGIN_SECURITY_ELEVATED_FAILURES', 3),
    'high_failures' => (int) env('LOGIN_SECURITY_HIGH_FAILURES', 5),
    'credential_stuffing_accounts' => (int) env('LOGIN_SECURITY_TARGETED_ACCOUNTS', 3),
    'retention_days' => (int) env('LOGIN_SECURITY_RETENTION_DAYS', 90),
];
