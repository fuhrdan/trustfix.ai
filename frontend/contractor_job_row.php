<?php
$firstImage = $job['images'][0]['image_path'] ?? '';
$payment = $job['payments'][0] ?? [];
?>
<article class="cd-job">
    <?php if ($firstImage) { ?>
        <img class="cd-thumb" src="<?= htmlspecialchars(storageUrl($firstImage)) ?>" alt="">
    <?php } else { ?>
        <div class="cd-thumb cd-placeholder">No image</div>
    <?php } ?>
    <div>
        <div class="cd-job-top">
            <div>
                <h3><?= htmlspecialchars(cdTitle($job['initial_description'] ?? '')) ?></h3>
                <div class="cd-meta">Job #<?= (int)$job['id'] ?> · <?= htmlspecialchars($job['address'] ?? '') ?></div>
            </div>
            <span class="cd-pill"><?= htmlspecialchars(cdStatus($job['status'] ?? '')) ?></span>
        </div>
        <div class="cd-meta" style="margin-top:7px">
            <?= htmlspecialchars(cdJobMoney($job['agreed_price'] ?? null)) ?>
            <?php if (!empty($payment['status'])) { ?> · Payment: <?= htmlspecialchars(cdStatus($payment['status'])) ?><?php } ?>
        </div>
        <div class="cd-actions">
            <a href="job_workspace.php?id=<?= (int)$job['id'] ?>">Open workspace</a>
            <a href="job_detail.php?id=<?= (int)$job['id'] ?>">Details</a>
            <?php if (empty($isAdmin)) { ?>
                <a href="contractor_job_photos.php?id=<?= (int)$job['id'] ?>">Add photos</a>
            <?php } ?>
        </div>
    </div>
</article>
