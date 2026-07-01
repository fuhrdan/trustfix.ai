<?php

require 'config.php';
requireLogin();
include 'header.php';

$jobId = (int)($_GET['id'] ?? 0);

if ($jobId <= 0) {
    die('Missing job id');
}

$message = '';

$statuses = [
    'posted' => 'Posted',
    'requested' => 'Requested',
    'accepted' => 'Accepted',
    'in_progress' => 'In Progress',
    'change_requested' => 'Change Requested',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'disputed' => 'Disputed',
];

$skillsList = [
    'electrical',
    'plumbing',
    'drywall',
    'flooring',
    'general',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'status' => $_POST['status'] ?? 'posted',
        'address' => $_POST['address'] ?? '',
        'lat' => (float)($_POST['lat'] ?? 0),
        'lng' => (float)($_POST['lng'] ?? 0),
        'initial_description' => $_POST['initial_description'] ?? '',
        'agreed_price' => ($_POST['agreed_price'] ?? '') === ''
            ? null
            : (float)$_POST['agreed_price'],
        'onsite_contact_name' => $_POST['onsite_contact_name'] ?? '',
        'onsite_contact_phone' => $_POST['onsite_contact_phone'] ?? '',
        'skills' => $_POST['skills'] ?? [],
    ];

    $response = apiRequest(
        'PUT',
        "/admin/jobs/$jobId",
        $payload
    );

    if (($response['success'] ?? false) === true) {
        $message = "
            <div style='background:#dff0d8;padding:15px;border-radius:8px;margin-bottom:20px;'>
                Job updated successfully.
            </div>
        ";
    } else {
        $message = "
            <div style='background:#f8d7da;padding:15px;border-radius:8px;margin-bottom:20px;'>
                Job update failed.
                <pre>" . htmlspecialchars(print_r($response, true)) . "</pre>
            </div>
        ";
    }
}

$job = apiRequest(
    'GET',
    "/admin/jobs/$jobId"
);

if (!is_array($job) || isset($job['error'])) {
    die('Job not found');
}

$existingSkills = $job['skills'] ?? [];

if (!is_array($existingSkills)) {
    $existingSkills = [];
}

?>

<h1>Edit Job #<?= (int)$jobId ?></h1>

<p>
    <a href="manage_jobs.php">&larr; Back to Manage Jobs</a>
</p>

<?= $message ?>

<div style="background:#fafafa;padding:20px;border-radius:8px;margin-bottom:20px;">
    <strong>Customer:</strong>
    <?= htmlspecialchars($job['customer']['name'] ?? '') ?>
    <?php if (!empty($job['customer']['email'])): ?>
        &lt;<?= htmlspecialchars($job['customer']['email']) ?>&gt;
    <?php endif; ?>

    <br><br>

    <strong>Assigned Contractor:</strong>
    <?php if (!empty($job['handyman'])): ?>
        <?= htmlspecialchars($job['handyman']['name'] ?? '') ?>
        <?php if (!empty($job['handyman']['email'])): ?>
            &lt;<?= htmlspecialchars($job['handyman']['email']) ?>&gt;
        <?php endif; ?>
    <?php else: ?>
        <em>Unassigned</em>
    <?php endif; ?>
</div>

<form method="POST">
    <label>Status</label><br>
    <select name="status" required>
        <?php foreach ($statuses as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= ($job['status'] ?? '') === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <br><br>

    <label>Address</label><br>
    <input
        type="text"
        name="address"
        required
        value="<?= htmlspecialchars($job['address'] ?? '') ?>"
    >

    <label>Latitude</label><br>
    <input
        type="number"
        step="0.0000001"
        name="lat"
        value="<?= htmlspecialchars($job['lat'] ?? 0) ?>"
    >

    <label>Longitude</label><br>
    <input
        type="number"
        step="0.0000001"
        name="lng"
        value="<?= htmlspecialchars($job['lng'] ?? 0) ?>"
    >

    <label>Description</label><br>
    <textarea
        name="initial_description"
        rows="7"
        required
    ><?= htmlspecialchars($job['initial_description'] ?? '') ?></textarea>

    <label>Agreed Price</label><br>
    <input
        type="number"
        step="0.01"
        name="agreed_price"
        value="<?= htmlspecialchars($job['agreed_price'] ?? '') ?>"
    >

    <label>On-site Contact Name</label><br>
    <input
        type="text"
        name="onsite_contact_name"
        value="<?= htmlspecialchars($job['onsite_contact_name'] ?? '') ?>"
    >

    <label>On-site Contact Phone</label><br>
    <input
        type="text"
        name="onsite_contact_phone"
        value="<?= htmlspecialchars($job['onsite_contact_phone'] ?? '') ?>"
    >

    <h3>Required Skills</h3>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
        <?php foreach ($skillsList as $skill): ?>
            <label style="display:flex;align-items:center;gap:6px;">
                <input
                    type="checkbox"
                    name="skills[]"
                    value="<?= htmlspecialchars($skill) ?>"
                    <?= in_array($skill, $existingSkills, true) ? 'checked' : '' ?>
                    style="width:auto;margin:0;"
                >
                <?= htmlspecialchars(ucfirst($skill)) ?>
            </label>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($job['images'])): ?>
        <h3>Job Images</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
            <?php foreach ($job['images'] as $image): ?>
                <a href="<?= htmlspecialchars(storageUrl($image['image_path'] ?? '')) ?>" target="_blank">
                    <img
                        src="<?= htmlspecialchars(storageUrl($image['image_path'] ?? '')) ?>"
                        style="width:140px;height:100px;object-fit:cover;border-radius:8px;border:1px solid #ccc;"
                    >
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <button type="submit">
        Update Job
    </button>
</form>

<?php include 'footer.php'; ?>
