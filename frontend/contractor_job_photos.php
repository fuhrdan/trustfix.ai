<?php
require 'config.php';
requireLogin();

$jobId = (int)($_GET['id'] ?? $_POST['job_id'] ?? 0);
$job = apiRequest('GET', '/jobs/' . $jobId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['images']['tmp_name'])) {
    requireValidCsrf();
    $payload = [];
    foreach ($_FILES['images']['tmp_name'] as $index => $tmpName) {
        if ($tmpName && ($_FILES['images']['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $payload['images[' . $index . ']'] = new CURLFile(
                $tmpName,
                $_FILES['images']['type'][$index],
                $_FILES['images']['name'][$index]
            );
        }
    }

    $result = apiRequest('POST', '/jobs/' . $jobId . '/images', $payload);
    if (!empty($result['success'])) {
        $_SESSION['flash_success'] = 'Job photos added.';
        header('Location: contractor_job_photos.php?id=' . $jobId);
        exit;
    }

    $error = $result['message'] ?? $result['error'] ?? 'Photo upload failed.';
}

include 'header.php';
?>
<div style="max-width:900px;margin:auto">
    <p><a href="contractor_dashboard.php">&larr; Contractor dashboard</a></p>
    <h1>Add job photos</h1>
    <p><strong>Job #<?= $jobId ?></strong> — <?= htmlspecialchars($job['address'] ?? '') ?></p>
    <?php if (!empty($error)) { ?><div class="tf-alert tf-alert-error"><?= htmlspecialchars($error) ?></div><?php } ?>
    <form method="POST" enctype="multipart/form-data" style="background:white;padding:24px;border-radius:12px">
        <?= csrfField() ?>
        <input type="hidden" name="job_id" value="<?= $jobId ?>">
        <label for="images"><strong>Select progress or completed-work photos</strong></label>
        <input id="images" type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
        <p style="color:#666;font-size:13px">JPG, PNG, WEBP, or GIF; up to 5 MB each.</p>
        <button type="submit">Upload photos</button>
    </form>
    <?php if (!empty($job['images'])) { ?>
        <h2>Existing photos</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px">
        <?php foreach ($job['images'] as $image) { ?>
            <a href="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" target="_blank">
                <img src="<?= htmlspecialchars(storageUrl($image['image_path'])) ?>" alt="" style="width:100%;height:160px;object-fit:cover;border-radius:9px">
            </a>
        <?php } ?>
        </div>
    <?php } ?>
</div>
<?php include 'footer.php'; ?>
