<?php

$boolean = static fn (string $key, bool $default): bool =>
    filter_var(env($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true;

return [
    'enabled' => $boolean('STRIPE_PAYMENTS_ENABLED', false),
    // This controls transfers to connected accounts, not bank payouts.
    'releases_enabled' => $boolean('STRIPE_PAYOUT_RELEASES_ENABLED', false),
    'refunds_enabled' => $boolean('STRIPE_REFUNDS_ENABLED', false),
    'auto_release_enabled' => $boolean('STRIPE_PAYOUT_AUTO_RELEASE_ENABLED', false),
    'test_mode_only' => $boolean('STRIPE_TEST_MODE_ONLY', true),
];
