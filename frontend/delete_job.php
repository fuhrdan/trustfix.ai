<?php
require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $jobId = $_POST['job_id'] ?? null;

    if (!$jobId) {
        die('Missing job id');
    }

    $result = apiRequest('DELETE', "/jobs/$jobId");

    header("Location: list_jobs.php");
    exit;
}