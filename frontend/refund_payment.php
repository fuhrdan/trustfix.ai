<?php

require 'config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

requireValidCsrf();

$jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
$paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
$confirmation = $_POST['confirmation'] ?? '';

if (!$jobId || $jobId < 1 || !$paymentId || $paymentId < 1
    || $confirmation !== 'refund_full_customer_payment') {
    $target = $jobId && $jobId > 0
        ? 'manage_payment.php?job_id=' . (int)$jobId . '&result=refund_failed'
        : 'manage_jobs.php';
    header('Location: ' . $target, true, 303);
    exit;
}

$result = apiRequest(
    'POST',
    '/admin/payments/' . $paymentId . '/refund',
    ['confirmation' => $confirmation]
);
$http = (int)($result['_http_code'] ?? 500);
$state = (string)($result['status'] ?? '');

$notice = match (true) {
    $http === 200 && $state === 'refunded' => 'refund_completed',
    $http === 200 && $state === 'awaiting_webhook' => 'refund_awaiting',
    $http === 202 && in_array($state, ['pending', 'busy'], true) => 'refund_pending',
    default => 'refund_failed',
};

header(
    'Location: manage_payment.php?job_id=' . (int)$jobId
        . '&result=' . rawurlencode($notice),
    true,
    303
);
exit;
