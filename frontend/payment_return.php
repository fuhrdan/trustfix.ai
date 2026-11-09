<?php

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

// Keep the provider's return parameters out of the page and the next URL.
$jobId = filter_var($_GET['job_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
header('Location: ' . ($jobId === false ? 'my_jobs.php' : 'pay_job.php?id=' . $jobId), true, 303);
exit;
