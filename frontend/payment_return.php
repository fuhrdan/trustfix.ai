<?php
require 'config.php';
requireLogin();

$jobId = (int)($_GET['job_id'] ?? 0);
$_SESSION['flash_success'] = 'Payment was submitted. Trustfix will update the job as soon as Stripe confirms it.';
header('Location: job_workspace.php?id=' . $jobId);
exit;
