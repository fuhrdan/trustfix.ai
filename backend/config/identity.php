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

$csv = static function (string $key, array $default = []): array {
    $value = trim((string) env($key, implode(',', $default)));

    return array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $value)
    )));
};

return [
    'oidc' => [
        // Keep federated sign-in dormant until an approved provider, client,
        // and exact callback URL have been configured in the environment.
        'enabled' => $boolean('OIDC_ENABLED', false),
        'state_ttl_seconds' => max(60, (int) env('OIDC_STATE_TTL_SECONDS', 600)),
        'completion_ttl_seconds' => max(60, (int) env('OIDC_COMPLETION_TTL_SECONDS', 300)),
        'flow_token_retention_hours' => max(1, (int) env('OIDC_FLOW_TOKEN_RETENTION_HOURS', 24)),
        'clock_skew_seconds' => max(0, (int) env('OIDC_CLOCK_SKEW_SECONDS', 60)),

        'providers' => [
            'crane' => [
                'enabled' => $boolean('OIDC_CRANE_ENABLED', false),
                'display_name' => env('OIDC_CRANE_DISPLAY_NAME', 'Crane'),
                'issuer' => env('OIDC_CRANE_ISSUER'),
                'client_id' => env('OIDC_CRANE_CLIENT_ID'),
                'client_secret' => env('OIDC_CRANE_CLIENT_SECRET'),
                'redirect_uri' => env(
                    'OIDC_CRANE_REDIRECT_URI',
                    rtrim((string) env('APP_URL', ''), '/').'/auth/oidc/crane/callback'
                ),
                'scopes' => $csv('OIDC_CRANE_SCOPES', ['openid', 'profile', 'email']),
                // Leave these blank until Crane specifies the final membership
                // eligibility claim and value for the sandbox.
                'required_claim' => env('OIDC_CRANE_REQUIRED_CLAIM'),
                'required_claim_value' => env('OIDC_CRANE_REQUIRED_CLAIM_VALUE'),
                'member_id_claim' => env('OIDC_CRANE_MEMBER_ID_CLAIM'),
                'partner_slug' => env('OIDC_CRANE_PARTNER_SLUG', 'crane'),
                'require_verified_email' => $boolean(
                    'OIDC_CRANE_REQUIRE_VERIFIED_EMAIL',
                    true
                ),
                // The native verifier deliberately accepts only signed RSA
                // ID tokens. Add an algorithm only after it is agreed with
                // the provider and included in the integration test plan.
                'allowed_signing_algorithms' => $csv(
                    'OIDC_CRANE_ALLOWED_SIGNING_ALGORITHMS',
                    ['RS256']
                ),
                'token_endpoint_auth_method' => env(
                    'OIDC_CRANE_TOKEN_ENDPOINT_AUTH_METHOD',
                    'client_secret_basic'
                ),
            ],
        ],
    ],
];
