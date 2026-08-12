<?php

require 'config.php';

requireLogin();

$user = apiRequest('GET', '/me');
$jobs = apiRequest('GET', '/jobs/my');

if (!is_array($jobs) || isset($jobs['error']) || isset($jobs['message'])) {
    $jobs = [];
}

$role = $user['role'] ?? ($_SESSION['user']['role'] ?? '');
$isContractorRole = in_array($role, ['handyman', 'company', 'admin'], true);

function jobText($value, $fallback = 'Not provided')
{
    if ($value === null || $value === '') {
        return $fallback;
    }

    return htmlspecialchars((string)$value);
}

function jobMoney($value)
{
    if ($value === null || $value === '') {
        return 'Budget not listed';
    }

    return '$' . number_format((float)$value, 2);
}

function jobStatusLabel($status)
{
    $labels = [
        'posted' => 'Open',
        'requested' => 'Requested',
        'accepted' => 'Accepted',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'disputed' => 'Disputed',
    ];

    return $labels[$status] ?? ucwords(str_replace('_', ' ', (string)$status));
}

function shortDescription($text, $limit = 170)
{
    $text = trim((string)$text);

    if ($text === '') {
        return 'No description provided.';
    }

    if (strlen($text) <= $limit) {
        return $text;
    }

    return substr($text, 0, $limit - 3) . '...';
}

$buckets = [
    'active' => [
        'title' => 'Active Work',
        'statuses' => ['accepted', 'scheduled', 'in_progress'],
        'jobs' => [],
    ],
    'waiting' => [
        'title' => $isContractorRole ? 'Waiting / Open' : 'Posted / Waiting',
        'statuses' => ['posted', 'requested'],
        'jobs' => [],
    ],
    'completed' => [
        'title' => 'Completed',
        'statuses' => ['completed'],
        'jobs' => [],
    ],
    'closed' => [
        'title' => 'Cancelled / Disputed',
        'statuses' => ['cancelled', 'disputed'],
        'jobs' => [],
    ],
];

foreach ($jobs as $job) {
    $status = $job['status'] ?? '';
    $placed = false;

    foreach ($buckets as $key => $bucket) {
        if (in_array($status, $bucket['statuses'], true)) {
            $buckets[$key]['jobs'][] = $job;
            $placed = true;
            break;
        }
    }

    if (!$placed) {
        $buckets['active']['jobs'][] = $job;
    }
}

$totalJobs = count($jobs);
$activeCount = count($buckets['active']['jobs']);
$waitingCount = count($buckets['waiting']['jobs']);
$completedCount = count($buckets['completed']['jobs']);

$pageTitle = 'My Jobs';
include 'header.php';
?>

<h1>My Jobs</h1>

<p style="font-size:16px;color:#555;max-width:850px;">
    Track the jobs you have posted or accepted, move contractor work forward, and jump back into job details from one dashboard.
</p>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin:25px 0;">
    <div style="background:white;border-radius:10px;padding:18px;border:1px solid #ddd;">
        <div style="font-size:28px;font-weight:bold;">
            <?= (int)$totalJobs ?>
        </div>
        <div style="color:#666;">Total Jobs</div>
    </div>

    <div style="background:white;border-radius:10px;padding:18px;border:1px solid #ddd;">
        <div style="font-size:28px;font-weight:bold;">
            <?= (int)$activeCount ?>
        </div>
        <div style="color:#666;">Active</div>
    </div>

    <div style="background:white;border-radius:10px;padding:18px;border:1px solid #ddd;">
        <div style="font-size:28px;font-weight:bold;">
            <?= (int)$waitingCount ?>
        </div>
        <div style="color:#666;">Waiting</div>
    </div>

    <div style="background:white;border-radius:10px;padding:18px;border:1px solid #ddd;">
        <div style="font-size:28px;font-weight:bold;">
            <?= (int)$completedCount ?>
        </div>
        <div style="color:#666;">Completed</div>
    </div>
</div>

<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:25px;">
    <?php if ($isContractorRole): ?>
        <a class="tf-button tf-button-success" href="available_jobs.php">Find Available Jobs</a>
    <?php else: ?>
        <a class="tf-button" href="add_job.php">Post New Job</a>
    <?php endif; ?>
</div>

<?php foreach ($buckets as $bucketKey => $bucket) { ?>
    <section style="margin-bottom:35px;">
        <h2 style="border-bottom:2px solid #ddd;padding-bottom:8px;">
            <?= htmlspecialchars($bucket['title']) ?>
            <span style="font-size:16px;color:#777;">
                (<?= count($bucket['jobs']) ?>)
            </span>
        </h2>

        <?php if (empty($bucket['jobs'])) { ?>
            <div style="background:white;border:1px dashed #bbb;border-radius:10px;padding:22px;color:#666;">
                No jobs in this bucket yet.
            </div>
        <?php } ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:18px;">
            <?php foreach ($bucket['jobs'] as $job) { ?>
                <?php
                    $jobId = (int)($job['id'] ?? 0);
                    $status = $job['status'] ?? '';
                    $customer = $job['customer']['name'] ?? '';
                    $contractor = $job['handyman']['name'] ?? '';
                    $isAssignedContractor = (int)($job['handyman_id'] ?? 0) === (int)($user['id'] ?? 0);
                    $isJobCustomer = (int)($job['customer_id'] ?? 0) === (int)($user['id'] ?? 0);
                    $images = $job['images'] ?? [];
                    $firstImage = $images[0]['image_path'] ?? '';
                ?>

                <article style="background:white;border:1px solid #ddd;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                    <?php if (!empty($firstImage)) { ?>
                        <img src="<?= htmlspecialchars(storageUrl($firstImage)) ?>" alt="Job image" style="width:100%;height:150px;object-fit:cover;display:block;">
                    <?php } ?>

                    <div style="padding:18px;">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                            <h3 style="margin-top:0;margin-bottom:8px;">
                                Job #<?= $jobId ?>
                            </h3>

                            <span style="background:#eef2ff;color:#1f3a8a;border-radius:999px;padding:5px 10px;font-size:13px;white-space:nowrap;">
                                <?= htmlspecialchars(jobStatusLabel($status)) ?>
                            </span>
                        </div>

                        <p style="color:#555;min-height:52px;">
                            <?= htmlspecialchars(shortDescription($job['initial_description'] ?? '')) ?>
                        </p>

                        <div style="font-weight:bold;margin-bottom:8px;">
                            <?= jobMoney($job['agreed_price'] ?? null) ?>
                        </div>

                        <?php if (!empty($job['estimate'])) { ?>
                            <div style="background:#edf8f2;color:#126b49;border-radius:7px;padding:9px;margin-bottom:10px;">
                                <strong>TrustFix range:</strong>
                                <?= jobMoney($job['estimate']['estimate_low'] ?? null) ?>–<?= jobMoney($job['estimate']['estimate_high'] ?? null) ?>
                            </div>
                        <?php } ?>

                        <div style="color:#555;margin-bottom:8px;">
                            <strong>Address:</strong>
                            <?= jobText($job['address'] ?? '') ?>
                        </div>

                        <?php if (!empty($customer)) { ?>
                            <div style="color:#555;margin-bottom:8px;">
                                <strong>Customer:</strong>
                                <?= htmlspecialchars($customer) ?>
                            </div>
                        <?php } ?>

                        <?php if (!empty($contractor)) { ?>
                            <div style="color:#555;margin-bottom:8px;">
                                <strong>Contractor:</strong>
                                <?= htmlspecialchars($contractor) ?>
                            </div>
                        <?php } ?>

                        <div style="color:#777;font-size:13px;margin-bottom:15px;">
                            Updated <?= jobText($job['updated_at'] ?? '', 'recently') ?>
                        </div>

                        <a href="job_workspace.php?id=<?= $jobId ?>" style="display:block;text-align:center;background:#0d6efd;color:white;padding:10px;border-radius:8px;text-decoration:none;margin-bottom:10px;">
                            Open Workspace
                        </a>

                        <a href="job_detail.php?id=<?= $jobId ?>" style="display:block;text-align:center;background:#333;color:white;padding:10px;border-radius:8px;text-decoration:none;margin-bottom:10px;">
                            View Details
                        </a>

                        <a href="estimate_job.php?id=<?= $jobId ?>" style="display:block;text-align:center;background:#13764f;color:white;padding:10px;border-radius:8px;text-decoration:none;margin-bottom:10px;">
                            Smart Estimate
                        </a>

                        <?php if ($isAssignedContractor && $status === 'accepted') { ?>
                            <form method="POST" action="job_status_action.php" style="margin:0;">
                                <?= csrfField() ?>
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <input type="hidden" name="action" value="start">
                                <button type="submit" style="background:#0d6efd;color:white;border:0;border-radius:8px;cursor:pointer;">
                                    Start Job
                                </button>
                            </form>
                        <?php } ?>

                        <?php if ($isAssignedContractor && $status === 'in_progress') { ?>
                            <form method="POST" action="job_status_action.php" style="margin:0;">
                                <?= csrfField() ?>
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" style="background:#198754;color:white;border:0;border-radius:8px;cursor:pointer;">
                                    Mark Complete
                                </button>
                            </form>
                        <?php } ?>

                        <?php if ($isJobCustomer && in_array($status, ['posted', 'requested', 'accepted', 'scheduled'], true)) { ?>
                            <form method="POST" action="job_status_action.php" style="margin:0;" onsubmit="return confirm('Cancel this job?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="job_id" value="<?= $jobId ?>">
                                <input type="hidden" name="action" value="cancel">
                                <button type="submit" style="background:#dc3545;color:white;border:0;border-radius:8px;cursor:pointer;">
                                    Cancel Job
                                </button>
                            </form>
                        <?php } ?>
                    </div>
                </article>
            <?php } ?>
        </div>
    </section>
<?php } ?>

<?php include 'footer.php'; ?>
