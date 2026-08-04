<?php
require 'config.php';
requireLogin();
$currentUser = apiRequest('GET', '/me');
include 'header.php';

$jobId = (int)($_GET['id'] ?? 0);

if (!$jobId) {
    die('Missing job ID');
}

$job = apiRequest('GET', '/jobs/' . $jobId . '/workspace');

if (!is_array($job) || !empty($job['error'])) {
    die('Job workspace not found or you do not have permission to view it.');
}

$messages = $job['messages'] ?? [];
$activities = $job['activities'] ?? [];
$images = $job['images'] ?? [];
$customer = $job['customer'] ?? [];
$handyman = $job['handyman'] ?? [];
$property = $job['property'] ?? [];
$changeOrders = $job['change_orders'] ?? [];
$isCustomer = (int)($job['customer_id'] ?? 0) === (int)($currentUser['id'] ?? 0);
$successfulPayment = false;
foreach (($job['payments'] ?? []) as $workspacePayment) {
    if (($workspacePayment['status'] ?? '') === 'succeeded') {
        $successfulPayment = true;
        break;
    }
}

function wsStatusLabel($status)
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

function wsMoney($value)
{
    if ($value === null || $value === '') {
        return 'Not listed';
    }

    return '$' . number_format((float)$value, 2);
}

function wsDate($value)
{
    if (empty($value)) {
        return 'Not listed';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('M j, Y g:i A', $timestamp) : $value;
}

function wsTitle($text)
{
    $title = trim(strtok(trim($text ?: 'Job Workspace'), "\n."));

    if ($title === '') {
        $title = 'Job Workspace';
    }

    return strlen($title) > 85 ? substr($title, 0, 82) . '...' : $title;
}
?>

<style>
    .workspace-grid {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
        gap: 24px;
        align-items: start;
    }

    .workspace-card {
        background: white;
        border: 1px solid #ddd;
        border-radius: 12px;
        padding: 22px;
        margin-bottom: 20px;
        box-shadow: 0 1px 5px rgba(0,0,0,.07);
    }

    .workspace-pill {
        display: inline-block;
        background: #e7f1ff;
        color: #084298;
        border-radius: 999px;
        padding: 6px 12px;
        font-size: 13px;
        font-weight: bold;
    }

    .message-row {
        border-bottom: 1px solid #eee;
        padding: 14px 0;
    }

    .message-row:last-child {
        border-bottom: 0;
    }

    .system-message {
        background: #f8f9fa;
        border-left: 4px solid #6c757d;
        padding: 10px 12px;
        border-radius: 6px;
        color: #555;
    }

    .activity-item {
        border-left: 3px solid #0d6efd;
        padding-left: 12px;
        margin-bottom: 15px;
    }

    .workspace-images {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 10px;
    }

    .workspace-images img {
        width: 100%;
        height: 110px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #ddd;
    }

    .workspace-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .workspace-actions a,
    .workspace-actions button {
        display: inline-block;
        width: auto;
        padding: 10px 14px;
        border-radius: 8px;
        border: 0;
        text-decoration: none;
        cursor: pointer;
        color: white;
        margin-bottom: 0;
    }

    @media (max-width: 850px) {
        .workspace-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<p>
    <a href="my_jobs.php">&larr; Back to My Jobs</a>
    &nbsp; | &nbsp;
    <a href="job_detail.php?id=<?= (int)$job['id'] ?>">View Job Details</a>
</p>

<div class="workspace-card">
    <span class="workspace-pill"><?= htmlspecialchars(wsStatusLabel($job['status'] ?? '')) ?></span>
    <h1 style="margin-bottom:8px;"><?= htmlspecialchars(wsTitle($job['initial_description'] ?? '')) ?></h1>
    <p style="color:#666;margin-top:0;">
        Job #<?= (int)$job['id'] ?> &bull;
        <?= htmlspecialchars(wsMoney($job['agreed_price'] ?? null)) ?> &bull;
        <?= htmlspecialchars($job['address'] ?? 'Address not listed') ?>
    </p>
</div>

<div class="workspace-grid">
    <main>
        <section class="workspace-card">
            <h2>Conversation</h2>

            <?php if (empty($messages)) { ?>
                <p style="color:#666;">No messages yet. Send the first update below.</p>
            <?php } ?>

            <?php foreach ($messages as $message) { ?>
                <?php
                    $type = $message['message_type'] ?? 'user';
                    $sender = $message['sender']['name'] ?? 'TrustFix';
                ?>
                <div class="message-row">
                    <?php if ($type === 'system') { ?>
                        <div class="system-message">
                            <strong>TrustFix</strong><br>
                            <?= nl2br(htmlspecialchars($message['message'] ?? '')) ?>
                            <div style="font-size:12px;color:#888;margin-top:5px;">
                                <?= htmlspecialchars(wsDate($message['created_at'] ?? null)) ?>
                            </div>
                        </div>
                    <?php } else { ?>
                        <strong><?= htmlspecialchars($sender) ?></strong>
                        <span style="font-size:12px;color:#888;">
                            <?= htmlspecialchars(wsDate($message['created_at'] ?? null)) ?>
                        </span>
                        <p style="white-space:pre-wrap;margin-bottom:0;">
                            <?= htmlspecialchars($message['message'] ?? '') ?>
                        </p>
                    <?php } ?>
                </div>
            <?php } ?>

            <form method="POST" action="send_job_message.php" style="margin-top:20px;">
                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                <textarea name="message" rows="4" placeholder="Send a message about this job..." required></textarea>
                <button type="submit" style="background:#0d6efd;color:white;border:0;border-radius:8px;cursor:pointer;">
                    Send Message
                </button>
            </form>
        </section>

        <section class="workspace-card">
            <h2>Photos</h2>
            <?php if (!empty($images)) { ?>
                <div class="workspace-images">
                    <?php foreach ($images as $image) { ?>
                        <?php if (!empty($image['image_path'])) { ?>
                            <a href="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" target="_blank">
                                <img src="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" alt="Job image">
                            </a>
                        <?php } ?>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p>No photos have been added yet.</p>
            <?php } ?>
        </section>

        <section class="workspace-card">
            <h2>Change Orders</h2>
            <?php if (empty($changeOrders)) { ?>
                <p>No change orders yet.</p>
            <?php } else { ?>
                <?php foreach ($changeOrders as $order) { ?>
                    <div style="border-bottom:1px solid #eee;padding:12px 0;">
                        <strong><?= htmlspecialchars(wsMoney($order['price_delta'] ?? $order['amount'] ?? null)) ?></strong>
                        <span class="workspace-pill"><?= htmlspecialchars(wsStatusLabel($order['status'] ?? 'pending')) ?></span>
                        <p><?= htmlspecialchars($order['description'] ?? '') ?></p>
                    </div>
                <?php } ?>
            <?php } ?>
        </section>
    </main>

    <aside>
        <section class="workspace-card">
            <h2>Quick Actions</h2>
            <div class="workspace-actions">
                <a href="job_detail.php?id=<?= (int)$job['id'] ?>" style="background:#333;">Details</a>
                <a href="estimate_job.php?id=<?= (int)$job['id'] ?>" style="background:#13764f;">Smart Estimate</a>
                <a href="edit_job.php?id=<?= (int)$job['id'] ?>" style="background:#6c757d;">Edit</a>
                <?php if ($isCustomer && !empty($job['handyman_id']) && !$successfulPayment && !empty($job['agreed_price'])) { ?>
                    <a href="pay_job.php?id=<?= (int)$job['id'] ?>" style="background:#16835a;">Pay Contractor</a>
                <?php } ?>
            </div>
        </section>

        <section class="workspace-card">
            <h2>People</h2>
            <p>
                <strong>Customer</strong><br>
                <?= htmlspecialchars($customer['name'] ?? 'Customer') ?><br>
                <?= htmlspecialchars($customer['email'] ?? '') ?>
            </p>

            <p>
                <strong>Contractor</strong><br>
                <?= htmlspecialchars($handyman['name'] ?? 'Not assigned yet') ?><br>
                <?= htmlspecialchars($handyman['email'] ?? '') ?>
            </p>
        </section>

        <section class="workspace-card">
            <h2>Property / Schedule</h2>
            <p>
                <strong>Address</strong><br>
                <?= htmlspecialchars($job['address'] ?? 'Not listed') ?>
            </p>

            <?php if (!empty($property)) { ?>
                <p>
                    <strong>Property</strong><br>
                    <?= htmlspecialchars($property['street_address'] ?? '') ?><br>
                    <?= htmlspecialchars(trim(($property['city'] ?? '') . ', ' . ($property['state'] ?? '') . ' ' . ($property['zip'] ?? ''))) ?>
                </p>
            <?php } ?>

            <p style="color:#666;">
                Scheduling fields can be added here in the next sprint without changing the workspace layout.
            </p>
        </section>

        <section class="workspace-card">
            <h2>Activity Timeline</h2>
            <?php if (empty($activities)) { ?>
                <p>No activity yet.</p>
            <?php } ?>

            <?php foreach ($activities as $activity) { ?>
                <div class="activity-item">
                    <strong><?= htmlspecialchars($activity['description'] ?? '') ?></strong><br>
                    <span style="font-size:12px;color:#888;">
                        <?= htmlspecialchars(wsDate($activity['created_at'] ?? null)) ?>
                    </span>
                </div>
            <?php } ?>
        </section>
    </aside>
</div>

<?php include 'footer.php'; ?>
