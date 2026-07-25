<?php
require 'config.php';
requireLogin();

if (!empty($_GET['refresh'])) {
    $link = apiRequest('POST', '/contractor/payout-account');
    if (!empty($link['url'])) {
        header('Location: ' . $link['url']);
        exit;
    }
}

$result = apiRequest('POST', '/contractor/payout-account/refresh');

if (empty($result['stripe_details_submitted'])) {
    $_SESSION['flash_error'] = 'Payout setup is not complete yet. Please finish the required Stripe steps.';
} else {
    $_SESSION['flash_success'] = !empty($result['stripe_payouts_enabled'])
        ? 'Your payout account is ready.'
        : 'Payout information was saved. Stripe is still reviewing the account.';
}

header('Location: contractor_dashboard.php');
exit;
