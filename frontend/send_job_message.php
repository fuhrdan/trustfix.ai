<?php
require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_jobs.php');
    exit;
}

requireValidCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_jobs.php');
    exit;
}

$jobId = (int)($_POST['job_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if (!$jobId || $message === '') {
    $_SESSION['flash_error'] = 'Message and job are required.';
    header('Location: my_jobs.php');
    exit;
}

$response = apiRequest('POST', '/jobs/' . $jobId . '/messages', [
    'message' => $message,
]);

if (is_array($response) && empty($response['error']) && (($response['_http_code'] ?? 0) < 400)) {
    $_SESSION['flash_success'] = 'Message sent.';
} else {
    $_SESSION['flash_error'] = $response['message'] ?? $response['error'] ?? 'Message could not be sent.';
}

header('Location: job_workspace.php?id=' . $jobId);
exit;
