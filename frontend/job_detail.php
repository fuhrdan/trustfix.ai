<?php
require 'config.php';
requireLogin();
include 'header.php';

$jobId = (int)($_GET['id'] ?? 0);

if (!$jobId) {
    die('Missing job ID');
}

$job = apiRequest('GET', '/jobs/' . $jobId);

if (!is_array($job) || !empty($job['error'])) {
    die('Job not found or you do not have permission to view it.');
}

$skills = $job['skills'] ?? [];
if (!is_array($skills)) {
    $skills = [];
}

$images = $job['images'] ?? [];
if (!is_array($images)) {
    $images = [];
}

$property = $job['property'] ?? [];
if (!is_array($property)) {
    $property = [];
}

$customer = $job['customer'] ?? [];
if (!is_array($customer)) {
    $customer = [];
}

function statusLabel($status)
{
    $labels = [
        'posted' => 'Open',
        'requested' => 'Requested',
        'accepted' => 'Accepted',
        'in_progress' => 'In Progress',
        'change_requested' => 'Change Requested',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'disputed' => 'Disputed',
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function formatMoney($amount)
{
    if ($amount === null || $amount === '') {
        return 'Not listed';
    }

    return '$' . number_format((float)$amount, 2);
}

function formatDateTime($value)
{
    if (empty($value)) {
        return 'Not listed';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
}

function firstLineTitle($text)
{
    $title = trim(strtok(trim($text ?: 'Available Job'), "\n."));

    if (!$title) {
        $title = 'Available Job';
    }

    return strlen($title) > 85
        ? substr($title, 0, 82) . '...'
        : $title;
}

$canAccept = empty($job['handyman_id'])
    && in_array($job['status'] ?? '', ['posted', 'requested'], true);
?>

<style>
    .tf-detail-grid {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
        gap: 24px;
        align-items: start;
    }

    .tf-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        margin-bottom: 20px;
    }

    .tf-status-pill {
        display: inline-block;
        background: #e7f1ff;
        color: #084298;
        padding: 6px 12px;
        border-radius: 999px;
        font-weight: bold;
        font-size: 13px;
    }

    .tf-meta-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        margin-top: 18px;
    }

    .tf-meta-box {
        background: #f8f9fa;
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 14px;
    }

    .tf-meta-box span {
        display: block;
        color: #666;
        font-size: 13px;
        margin-bottom: 5px;
    }

    .tf-image-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
    }

    .tf-image-grid img {
        width: 100%;
        height: 170px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #ddd;
    }

    .tf-action-button {
        background: #198754;
        color: white;
        font-weight: bold;
        border: none;
        border-radius: 8px;
        cursor: pointer;
    }

    .tf-secondary-link {
        display: inline-block;
        margin-top: 12px;
    }

    @media (max-width: 800px) {
        .tf-detail-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<p>
    <a href="available_jobs.php">&larr; Back to Available Jobs</a>
    &nbsp; | &nbsp;
    <a href="my_jobs.php">My Jobs</a>
    &nbsp; | &nbsp;
    <a href="job_workspace.php?id=<?= (int)$job['id'] ?>">Open Workspace</a>
    &nbsp; | &nbsp;
    <a href="estimate_job.php?id=<?= (int)$job['id'] ?>">Smart Estimate</a>
</p>

<div class="tf-detail-grid">
    <div>
        <div class="tf-card">
            <span class="tf-status-pill"><?= htmlspecialchars(statusLabel($job['status'] ?? '')) ?></span>

            <h1 style="margin-bottom:8px;">
                <?= htmlspecialchars(firstLineTitle($job['initial_description'] ?? '')) ?>
            </h1>

            <p style="color:#666;margin-top:0;">
                Posted <?= htmlspecialchars(formatDateTime($job['created_at'] ?? null)) ?>
            </p>

            <div class="tf-meta-grid">
                <div class="tf-meta-box">
                    <span>Budget</span>
                    <strong><?= htmlspecialchars(formatMoney($job['agreed_price'] ?? null)) ?></strong>
                </div>

                <div class="tf-meta-box">
                    <span>Location</span>
                    <strong><?= htmlspecialchars($job['address'] ?? 'Not listed') ?></strong>
                </div>

                <div class="tf-meta-box">
                    <span>Requested Skills</span>
                    <strong><?= htmlspecialchars(!empty($skills) ? implode(', ', $skills) : 'Not listed') ?></strong>
                </div>
            </div>
        </div>

        <div class="tf-card">
            <h2>Description</h2>
            <p style="white-space:pre-wrap;line-height:1.5;">
                <?= htmlspecialchars($job['initial_description'] ?? '') ?>
            </p>
        </div>

        <div class="tf-card">
            <h2>Pictures</h2>

            <?php if (!empty($images)) { ?>
                <div class="tf-image-grid">
                    <?php foreach ($images as $image) { ?>
                        <?php if (!empty($image['image_path'])) { ?>
                            <a href="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" target="_blank">
                                <img src="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" alt="Job image">
                            </a>
                        <?php } ?>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p>No pictures have been added to this job yet.</p>
            <?php } ?>
        </div>
    </div>

    <aside>
        <div class="tf-card">
            <h2>Accept Job</h2>

            <?php if ($canAccept) { ?>
                <p>
                    Accepting this job assigns it to you and removes it from the available jobs list for other contractors.
                </p>

                <form method="POST" action="accept_job.php" onsubmit="return confirm('Accept this job?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                    <button type="submit" class="tf-action-button">
                        Accept Job
                    </button>
                </form>
            <?php } else { ?>
                <p style="background:#f4f4f4;padding:15px;border-radius:8px;">
                    This job is not currently available to accept.
                </p>
            <?php } ?>
        </div>

        <div class="tf-card">
            <h2>Customer</h2>
            <p>
                <strong><?= htmlspecialchars($customer['name'] ?? 'Customer') ?></strong>
            </p>

            <?php if (!empty($customer['phone'])) { ?>
                <p>
                    <strong>Phone:</strong><br>
                    <?= htmlspecialchars($customer['phone']) ?>
                </p>
            <?php } ?>

            <?php if (!empty($customer['email'])) { ?>
                <p>
                    <strong>Email:</strong><br>
                    <?= htmlspecialchars($customer['email']) ?>
                </p>
            <?php } ?>
        </div>

        <?php if (!empty($job['onsite_contact_name']) || !empty($job['onsite_contact_phone'])) { ?>
            <div class="tf-card">
                <h2>On-site Contact</h2>
                <p>
                    <?= htmlspecialchars($job['onsite_contact_name'] ?? '') ?><br>
                    <?= htmlspecialchars($job['onsite_contact_phone'] ?? '') ?>
                </p>
            </div>
        <?php } ?>

        <div class="tf-card">
            <h2>Property</h2>

            <?php if (!empty($property)) { ?>
                <p>
                    <strong><?= htmlspecialchars($property['street_address'] ?? $job['address'] ?? '') ?></strong><br>
                    <?= htmlspecialchars(trim(($property['city'] ?? '') . ', ' . ($property['state'] ?? '') . ' ' . ($property['zip'] ?? ''))) ?>
                </p>

                <?php if (!empty($property['description'])) { ?>
                    <p><?= htmlspecialchars($property['description']) ?></p>
                <?php } ?>
            <?php } else { ?>
                <p><?= htmlspecialchars($job['address'] ?? 'No property attached.') ?></p>
            <?php } ?>
        </div>
    </aside>
</div>

<?php include 'footer.php'; ?>
