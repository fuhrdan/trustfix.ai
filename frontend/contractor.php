<?php

require 'config.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$contractor = $id > 0 ? apiRequest('GET', '/contractors/' . $id) : [];

if (!is_array($contractor) || empty($contractor['id'])) {
    renderFrontendError(404, 'Contractor Not Found', 'This contractor profile is unavailable or is no longer public.');
}

$pageTitle = $contractor['business_name'] ?? 'Contractor Profile';
$website = filter_var($contractor['website'] ?? '', FILTER_VALIDATE_URL) ?: '';
$reviews = is_array($contractor['visible_reviews'] ?? null)
    ? $contractor['visible_reviews']
    : (is_array($contractor['visibleReviews'] ?? null) ? $contractor['visibleReviews'] : []);
$badges = is_array($contractor['badges'] ?? null) ? $contractor['badges'] : [];

include 'header.php';
?>

<p><a href="list_contractors.php">← Back to Contractors</a></p>

<div class="tf-card-grid" style="grid-template-columns:minmax(0,2fr) minmax(260px,1fr);">
    <article class="tf-card">
        <div class="tf-actions" style="justify-content:space-between;align-items:flex-start;">
            <div>
                <h1 style="margin-bottom:8px;"><?= htmlspecialchars($contractor['business_name'] ?? 'TrustFix Contractor', ENT_QUOTES, 'UTF-8') ?></h1>
                <p style="margin-top:0;color:#52616b;"><?= htmlspecialchars($contractor['service_area'] ?? 'Service area not listed', ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <span style="background:#edf8f2;color:#126b49;border-radius:999px;padding:6px 10px;font-weight:700;">Approved</span>
        </div>

        <?php if (!empty($contractor['bio'])): ?>
            <h2>About</h2>
            <p><?= nl2br(htmlspecialchars($contractor['bio'], ENT_QUOTES, 'UTF-8')) ?></p>
        <?php endif; ?>

        <?php if (!empty($badges)): ?>
            <h2>TrustFix Badges</h2>
            <div class="tf-actions">
                <?php foreach ($badges as $badge): ?>
                    <span style="background:#eaf4ff;color:#1f3a8a;border-radius:999px;padding:6px 10px;font-size:13px;">
                        <?= htmlspecialchars($badge['name'] ?? 'Verified', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2>Customer Reviews</h2>
        <?php if (empty($reviews)): ?>
            <p>No public reviews yet.</p>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <blockquote style="margin:0 0 14px;padding:14px;border-left:4px solid var(--tf-blue);background:#f4f8fb;">
                    <strong><?= number_format((float)($review['rating'] ?? 0), 1) ?> / 5</strong>
                    <p><?= htmlspecialchars($review['comment'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                </blockquote>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>

    <aside class="tf-card">
        <h2>Business Details</h2>
        <p><strong>Established:</strong><br><?= !empty($contractor['year_established']) ? (int)$contractor['year_established'] : 'Not listed' ?></p>
        <p><strong>Business type:</strong><br><?= htmlspecialchars(ucfirst($contractor['business_type'] ?? 'Not listed'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Emergency availability:</strong><br><?= !empty($contractor['emergency_availability']) ? 'Available' : 'Not listed' ?></p>
        <?php if ($website): ?>
            <a class="tf-button" href="<?= htmlspecialchars($website, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Visit Website</a>
        <?php endif; ?>
    </aside>
</div>

<?php include 'footer.php'; ?>
