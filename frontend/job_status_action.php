<?php

require 'config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_jobs.php');
    exit;
}

requireValidCsrf();

$jobId = (int)($_POST['job_id'] ?? 0);
$action = $_POST['action'] ?? '';

$allowedActions = [
    'start' => [
        'endpoint' => '/jobs/%d/start',
        'success' => 'Job moved to In Progress.',
    ],
    'complete' => [
        'endpoint' => '/jobs/%d/complete',
        'success' => 'Job marked complete.',
    ],
    'cancel' => [
        'endpoint' => '/jobs/%d/cancel',
        'success' => 'Job cancelled.',
    ],
];

if ($jobId <= 0 || !isset($allowedActions[$action])) {
    $_SESSION['flash_error'] = 'Invalid job action.';
    header('Location: my_jobs.php');
    exit;
}

$result = apiRequest(
    'POST',
    sprintf($allowedActions[$action]['endpoint'], $jobId),
    []
);

$httpCode = (int)($result['_http_code'] ?? 0);

if ($httpCode >= 200 && $httpCode < 300 && empty($result['error'])) {
    $_SESSION['flash_success'] = $allowedActions[$action]['success'];
} else {
    $_SESSION['flash_error'] = $result['error']
        ?? $result['message']
        ?? 'Unable to update job status.';
}

header('Location: my_jobs.php');
exit;
