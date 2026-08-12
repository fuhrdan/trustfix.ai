<?php

require 'config.php';
requireLogin();

$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'service_area' => trim($_GET['service_area'] ?? ''),
    'min_rating' => trim($_GET['min_rating'] ?? ''),
    'sort' => $_GET['sort'] ?? 'rating_high',
    'page' => max(1, (int)($_GET['page'] ?? 1)),
];

$response = apiRequest('GET', '/contractors?' . http_build_query(array_filter(
    $filters,
    static fn ($value) => $value !== ''
)));
$contractors = is_array($response['data'] ?? null) ? $response['data'] : [];
$currentPage = (int)($response['current_page'] ?? 1);
$lastPage = (int)($response['last_page'] ?? 1);

$pageTitle = 'Contractors';
include 'header.php';
?>

<h1>Find a Contractor</h1>
<p class="tf-page-intro">Browse approved public profiles, compare service areas and experience, and open a profile for more detail.</p>

<form method="GET" class="tf-card" style="margin-bottom:24px;">
    <div class="tf-filter-grid">
        <div>
            <label for="contractor_q">Search</label>
            <input id="contractor_q" type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Business, service, or license">
        </div>
        <div>
            <label for="contractor_area">Service Area</label>
            <input id="contractor_area" type="text" name="service_area" value="<?= htmlspecialchars($filters['service_area'], ENT_QUOTES, 'UTF-8') ?>" placeholder="City or region">
        </div>
        <div>
            <label for="contractor_rating">Minimum Rating</label>
            <select id="contractor_rating" name="min_rating">
                <option value="">Any rating</option>
                <?php foreach ([5, 4, 3] as $rating): ?>
                    <option value="<?= $rating ?>" <?= (string)$filters['min_rating'] === (string)$rating ? 'selected' : '' ?>><?= $rating ?>+ stars</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="contractor_sort">Sort</label>
            <select id="contractor_sort" name="sort">
                <option value="rating_high" <?= $filters['sort'] === 'rating_high' ? 'selected' : '' ?>>Highest rated</option>
                <option value="business_name" <?= $filters['sort'] === 'business_name' ? 'selected' : '' ?>>Business name</option>
                <option value="established_oldest" <?= $filters['sort'] === 'established_oldest' ? 'selected' : '' ?>>Most established</option>
                <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest</option>
            </select>
        </div>
        <button type="submit">Search</button>
    </div>
</form>

<?php if (empty($contractors)): ?>
    <section class="tf-empty-state">
        <div class="tf-empty-state-icon" aria-hidden="true">?</div>
        <h2>No contractors matched</h2>
        <p>Clear one or more filters, or check back as additional professionals are approved.</p>
        <a class="tf-button tf-button-secondary" href="list_contractors.php">Clear Filters</a>
    </section>
<?php else: ?>
    <div class="tf-card-grid">
        <?php foreach ($contractors as $contractor): ?>
            <?php
                $experience = $contractor['years_experience'] ?? null;
                if (($experience === null || $experience === '') && !empty($contractor['year_established'])) {
                    $experience = max(0, (int)date('Y') - (int)$contractor['year_established']);
                }
                $rating = (float)($contractor['average_rating'] ?? 0);
                $reviewCount = (int)($contractor['review_count'] ?? 0);
                $badges = is_array($contractor['badges'] ?? null) ? $contractor['badges'] : [];
            ?>
            <article class="tf-card">
                <div class="tf-actions" style="justify-content:space-between;align-items:flex-start;">
                    <h2 style="margin-bottom:8px;"><?= htmlspecialchars($contractor['business_name'] ?? 'TrustFix Contractor', ENT_QUOTES, 'UTF-8') ?></h2>
                    <span style="background:#edf8f2;color:#126b49;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:700;">Approved</span>
                </div>

                <p><strong>Service area:</strong><br><?= htmlspecialchars($contractor['service_area'] ?? 'Not listed', ENT_QUOTES, 'UTF-8') ?></p>
                <p><strong>Experience:</strong> <?= $experience === null || $experience === '' ? 'Not listed' : (int)$experience . ' years' ?></p>
                <p><strong>Rating:</strong> <?= $reviewCount > 0 ? number_format($rating, 1) . ' / 5 (' . $reviewCount . ')' : 'No reviews yet' ?></p>

                <?php if (!empty($badges)): ?>
                    <p>
                        <?php foreach ($badges as $badge): ?>
                            <span style="display:inline-block;background:#eaf4ff;color:#1f3a8a;border-radius:999px;padding:4px 8px;margin:0 4px 5px 0;font-size:12px;">
                                <?= htmlspecialchars($badge['name'] ?? 'Verified', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <a class="tf-button" href="contractor.php?id=<?= (int)($contractor['id'] ?? 0) ?>">View Profile</a>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($lastPage > 1): ?>
    <nav class="tf-actions" aria-label="Contractor results pages">
        <?php if ($currentPage > 1): ?>
            <a class="tf-button tf-button-secondary" href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1])) ?>">Previous</a>
        <?php endif; ?>
        <span>Page <?= $currentPage ?> of <?= $lastPage ?></span>
        <?php if ($currentPage < $lastPage): ?>
            <a class="tf-button tf-button-secondary" href="?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php include 'footer.php'; ?>
