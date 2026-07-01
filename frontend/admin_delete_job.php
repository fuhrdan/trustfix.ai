<?php

require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_jobs.php');
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);

if ($jobId <= 0) {
    $_SESSION['flash_error'] = 'Missing job id.';
    header('Location: manage_jobs.php');
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

header('Location: manage_jobs.php');
exit;
