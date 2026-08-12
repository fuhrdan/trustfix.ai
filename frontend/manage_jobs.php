<?php

require 'config.php';
requireRole('admin');

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

$queryParts = ['page' => $page];

if ($status !== '') {
    $queryParts['status'] = $status;
}

if ($q !== '') {
    $queryParts['q'] = $q;
}

$result = apiRequest(
    'GET', '/admin/jobs?' . http_build_query($queryParts)
);

$jobs = is_array($result['data'] ?? null) ? $result['data'] : [];
$currentPage = max(1, (int)($result['current_page'] ?? $page));
$lastPage = max(1, (int)($result['last_page'] ?? 1));
$totalJobs = max(0, (int)($result['total'] ?? count($jobs)));
$loadError = !isset($result['data'])
    ? apiMessage($result, 'Unable to load jobs right now.')
    : '';

$statuses = [
    '' => 'All Statuses',
    'posted' => 'Posted',
    'requested' => 'Requested',
    'accepted' => 'Accepted',
    'in_progress' => 'In Progress',
    'change_requested' => 'Change Requested',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'disputed' => 'Disputed',
];

function adminJobAddress($job)
{
    if (!empty($job['property'])) {
        $parts = [];

        foreach ([
            'street_address',
            'address_line_2',
            'apartment',
            'city',
            'state',
            'zip'
        ] as $field) {
            if (!empty($job['property'][$field])) {
                $parts[] = $job['property'][$field];
            }
        }

        if (!empty($parts)) {
            return implode(', ', $parts);
        }
    }

    return $job['address'] ?? '';
}

function adminJobListUrl($targetPage, $q, $status)
{
    $params = ['page' => max(1, (int)$targetPage)];

    if ($q !== '') {
        $params['q'] = $q;
    }

    if ($status !== '') {
        $params['status'] = $status;
    }

    return 'manage_jobs.php?' . http_build_query($params);
}

$pageTitle = 'Manage Jobs';
$pageContainerClass = 'tf-container-wide';
include 'header.php';

?>

<div class="tf-page-heading">
    <div>
        <h1>Manage Jobs</h1>
        <p class="tf-page-intro">Search every job, review assignments, and manage project records.</p>
    </div>
    <span class="tf-count-badge"><?= $totalJobs ?> job<?= $totalJobs === 1 ? '' : 's' ?></span>
</div>

<?php if ($loadError !== ''): ?>
    <div class="tf-alert tf-alert-error" role="alert">
        <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<form method="GET" style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
    <label class="tf-sr-only" for="admin_job_search">Search jobs</label>
    <input
        id="admin_job_search"
        type="text"
        name="q"
        placeholder="Search jobs, users, address..."
        value="<?= htmlspecialchars($q) ?>"
        style="max-width:320px;margin:0;"
    >

    <label class="tf-sr-only" for="admin_job_status">Job status</label>
    <select id="admin_job_status" name="status" style="max-width:220px;margin:0;">
        <?php foreach ($statuses as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $status === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button type="submit" style="width:auto;margin:0;">
        Filter
    </button>

    <a href="manage_jobs.php">
        Reset
    </a>
</form>

<div class="tf-table-wrap">
<table>
    <caption class="tf-sr-only">All TrustFix jobs</caption>
    <thead>
        <tr>
            <th scope="col">ID</th>
            <th scope="col">Status</th>
            <th scope="col">Customer</th>
            <th scope="col">Contractor</th>
            <th scope="col">Address</th>
            <th scope="col">Description</th>
            <th scope="col">Price</th>
            <th scope="col">Created</th>
            <th scope="col">Edit</th>
            <th scope="col">Delete</th>
        </tr>
    </thead>
    <tbody>

    <?php if (empty($jobs)): ?>
        <tr>
            <td colspan="10">No jobs found.</td>
        </tr>
    <?php endif; ?>

    <?php foreach ($jobs as $job): ?>
        <tr>
            <td><?= (int)($job['id'] ?? 0) ?></td>

            <td><?= htmlspecialchars($job['status'] ?? '') ?></td>

            <td>
                <?= htmlspecialchars($job['customer']['name'] ?? '') ?><br>
                <small><?= htmlspecialchars($job['customer']['email'] ?? '') ?></small>
            </td>

            <td>
                <?php if (!empty($job['handyman'])): ?>
                    <?= htmlspecialchars($job['handyman']['name'] ?? '') ?><br>
                    <small><?= htmlspecialchars($job['handyman']['email'] ?? '') ?></small>
                <?php else: ?>
                    <em>Unassigned</em>
                <?php endif; ?>
            </td>

            <td><?= htmlspecialchars(adminJobAddress($job)) ?></td>

            <td>
                <?= htmlspecialchars(mb_strimwidth($job['initial_description'] ?? '', 0, 90, '...')) ?>
            </td>

            <td>
                $<?= number_format((float)($job['agreed_price'] ?? 0), 2) ?>
            </td>

            <td><?= htmlspecialchars($job['created_at'] ?? '') ?></td>

            <td>
                <a href="admin_edit_job.php?id=<?= (int)($job['id'] ?? 0) ?>">
                    Edit
                </a>
            </td>

            <td>
                <form
                    method="POST"
                    action="admin_delete_job.php"
                    onsubmit="return confirm('Delete this job? This cannot be undone.');"
                    style="margin:0;"
                >
                    <?= csrfField() ?>
                    <input type="hidden" name="job_id" value="<?= (int)($job['id'] ?? 0) ?>">
                    <input type="hidden" name="return_q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="return_status" value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                    <button type="submit" style="color:red;width:auto;margin:0;">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($lastPage > 1): ?>
    <nav class="tf-pagination" aria-label="Job list pages">
        <div class="tf-pagination-links">
            <?php if ($currentPage > 1): ?>
                <a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(adminJobListUrl($currentPage - 1, $q, $status), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
            <?php endif; ?>

            <span>Page <?= $currentPage ?> of <?= $lastPage ?></span>

            <?php if ($currentPage < $lastPage): ?>
                <a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(adminJobListUrl($currentPage + 1, $q, $status), ENT_QUOTES, 'UTF-8') ?>">Next</a>
            <?php endif; ?>
        </div>
        <span class="tf-muted">
            Showing <?= (int)($result['from'] ?? 0) ?>–<?= (int)($result['to'] ?? 0) ?> of <?= $totalJobs ?>
        </span>
    </nav>
<?php endif; ?>

<?php include 'footer.php'; ?>
