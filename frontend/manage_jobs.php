<?php

require 'config.php';
requireRole('admin');

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');

$queryParts = [];

if ($status !== '') {
    $queryParts['status'] = $status;
}

$result = apiRequest(
    'GET',
    '/admin/jobs' . (!empty($queryParts) ? '?' . http_build_query($queryParts) : '')
);

$jobs = $result['data'] ?? [];

if ($q !== '') {
    $jobs = array_values(array_filter($jobs, function ($job) use ($q) {
        $haystack = strtolower(implode(' ', [
            $job['id'] ?? '',
            $job['status'] ?? '',
            $job['address'] ?? '',
            $job['initial_description'] ?? '',
            $job['customer']['name'] ?? '',
            $job['customer']['email'] ?? '',
            $job['handyman']['name'] ?? '',
            $job['handyman']['email'] ?? '',
        ]));

        return str_contains($haystack, strtolower($q));
    }));
}

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

$pageTitle = 'Manage Jobs';
include 'header.php';

?>

<h1>Manage Jobs</h1>

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
    <tr>
        <th>ID</th>
        <th>Status</th>
        <th>Customer</th>
        <th>Contractor</th>
        <th>Address</th>
        <th>Description</th>
        <th>Price</th>
        <th>Created</th>
        <th>Edit</th>
        <th>Delete</th>
    </tr>

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
                    <button type="submit" style="color:red;width:auto;margin:0;">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</div>

<?php include 'footer.php'; ?>
