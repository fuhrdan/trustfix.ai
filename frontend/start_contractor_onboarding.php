<?php
require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contractor_dashboard.php');
    exit;
}

requireValidCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contractor_dashboard.php');
    exit;
}

$result = apiRequest('POST', '/contractor/payout-account');

if (!empty($result['url'])) {
    header('Location: ' . $result['url']);
    exit;
}

$_SESSION['flash_error'] = $result['message'] ?? $result['error'] ?? 'Unable to start payout setup.';
header('Location: contractor_dashboard.php');
exit;
