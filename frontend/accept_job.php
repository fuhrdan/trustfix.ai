<?php
require 'config.php';
requireLogin();

$jobId = (int)($_POST['job_id'] ?? 0);

if (!$jobId) {
    $_SESSION['flash_error'] = 'Missing job ID.';
    header('Location: available_jobs.php');
    exit;
}

$result = apiRequest('POST', '/jobs/' . $jobId . '/accept');

if (($result['_http_code'] ?? 500) >= 200 && ($result['_http_code'] ?? 500) < 300) {
    $_SESSION['flash_success'] = 'Job accepted successfully. It has been moved to My Jobs.';
    header('Location: list_jobs.php');
    exit;
}

$_SESSION['flash_error'] = $result['error'] ?? $result['message'] ?? 'Unable to accept this job.';
header('Location: job_detail.php?id=' . $jobId);
exit;
