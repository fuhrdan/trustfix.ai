<?php

require 'config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contractor_dashboard.php', true, 303);
    exit;
}

requireValidCsrf();

$result = apiRequest('POST', '/contractor/payout-account');
$url = is_string($result['url'] ?? null)
    ? trim($result['url'])
    : '';

if ($url !== '') {
    $parts = parse_url($url);

    $isStripeOnboardingUrl =
        is_array($parts)
        && strtolower((string)($parts['scheme'] ?? '')) === 'https'
        && strtolower((string)($parts['host'] ?? ''))
            === 'connect.stripe.com'
        && !isset($parts['user'], $parts['pass'], $parts['port'])
        && str_starts_with((string)($parts['path'] ?? ''), '/');

    if ($isStripeOnboardingUrl) {
        header('Location: ' . $url, true, 303);
        exit;
    }

    $_SESSION['flash_error'] =
        'Stripe returned an invalid payout-onboarding destination.';
} else {
    $_SESSION['flash_error'] =
        $result['message']
        ?? $result['error']
        ?? 'Unable to start payout setup.';
}

header('Location: contractor_dashboard.php', true, 303);
exit;