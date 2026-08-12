<?php

require 'config.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: list_jobs.php');
    exit;
}

requireValidCsrf();

$jobId = (int)$_POST['job_id'];

$status = $_POST['status'];

apiRequest(
    'POST',
    '/jobs/' . $jobId . '/status',
    [
        'status' => $status
    ]
);

header('Location: list_jobs.php');
exit;
