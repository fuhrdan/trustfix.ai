<?php

require 'config.php';
requireRole('admin');

$returnQuery = trim(substr((string)($_POST['return_q'] ?? ''), 0, 255));
$returnStatus = trim(substr((string)($_POST['return_status'] ?? ''), 0, 40));
$returnPage = max(1, (int)($_POST['return_page'] ?? 1));
$returnParams = ['page' => $returnPage];

if ($returnQuery !== '') {
    $returnParams['q'] = $returnQuery;
}

if ($returnStatus !== '') {
    $returnParams['status'] = $returnStatus;
}

$returnUrl = 'manage_jobs.php?' . http_build_query($returnParams);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnUrl);
    exit;
}

requireValidCsrf();

$jobId = (int)($_POST['job_id'] ?? 0);

if ($jobId <= 0) {
    $_SESSION['flash_error'] = 'Missing job id.';
    header('Location: ' . $returnUrl);
    exit;
}

$response = apiRequest(
    'DELETE',
    "/admin/jobs/$jobId"
);

if (($response['success'] ?? false) === true) {
    $_SESSION['flash_success'] = 'Job deleted.';
} else {
    $_SESSION['flash_error'] = 'Job delete failed.';
}

header('Location: ' . $returnUrl);
exit;
