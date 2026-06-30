<?php
require 'config.php';
requireLogin();
include 'header.php';

$search = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$budget = $_GET['budget'] ?? 'any';
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));

$query = http_build_query([
    'search' => $search,
    'category' => $category,
    'budget' => $budget,
    'sort' => $sort,
    'page' => $page,
    'per_page' => 20
]);

$response = apiRequest('GET', '/jobs/available?' . $query);
$jobs = $response['data'] ?? [];
$total = $response['total'] ?? count($jobs);
$currentPage = $response['current_page'] ?? 1;
$lastPage = $response['last_page'] ?? 1;

function selectedOption($value, $current)
{
    return $value === $current ? 'selected' : '';
}

function jobTitle($job)
{
    $description = trim($job['initial_description'] ?? 'Available Job');
    $title = strtok($description, "\n.");

    if (!$title) {
        $title = 'Available Job';
    }

    return strlen($title) > 70
        ? substr($title, 0, 67) . '...'
        : $title;
}


function formatCardMoney($amount)
{
    if ($amount === null || $amount === '') {
        return 'Not listed';
    }

    return '$' . number_format((float)$amount, 2);
}

function formatCardDate($value)
{
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return $value;
    }

    return date('M j, Y g:i A', $timestamp);
}
?>


<style>
    .tf-job-card {
        background: white;
        border-radius: 10px;
        padding: 0;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,.08);
        display: flex;
        flex-direction: column;
    }

    .tf-job-card-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .tf-job-thumb {
        width: 100%;
        height: 160px;
        object-fit: cover;
        background: #e9ecef;
        border-bottom: 1px solid #ddd;
    }

    .tf-job-placeholder {
        height: 160px;
        background: #e9ecef;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #666;
        border-bottom: 1px solid #ddd;
    }

    .tf-job-link {
        display: block;
        background: #222;
        color: white;
        text-align: center;
        padding: 12px;
        border-radius: 6px;
        text-decoration: none;
        margin-top: auto;
    }
</style>

<h1>Available Jobs</h1>

<p>
    <?= (int)$total ?> open job<?= (int)$total === 1 ? '' : 's' ?> available.
</p>

<form method="GET" style="background:white;padding:20px;border-radius:8px;margin-bottom:25px;">
    <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end;">
        <div>
            <label>Search</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Description, address, contact">
        </div>

        <div>
            <label>Category</label>
            <select name="category" style="width:100%;padding:10px;box-sizing:border-box;">
                <option value="" <?= selectedOption('', $category) ?>>All</option>
                <option value="plumbing" <?= selectedOption('plumbing', $category) ?>>Plumbing</option>
                <option value="electrical" <?= selectedOption('electrical', $category) ?>>Electrical</option>
                <option value="drywall" <?= selectedOption('drywall', $category) ?>>Drywall</option>
                <option value="flooring" <?= selectedOption('flooring', $category) ?>>Flooring</option>
                <option value="general" <?= selectedOption('general', $category) ?>>General</option>
            </select>
        </div>

        <div>
            <label>Budget</label>
            <select name="budget" style="width:100%;padding:10px;box-sizing:border-box;">
                <option value="any" <?= selectedOption('any', $budget) ?>>Any</option>
                <option value="under_100" <?= selectedOption('under_100', $budget) ?>>Under $100</option>
                <option value="100_250" <?= selectedOption('100_250', $budget) ?>>$100 - $250</option>
                <option value="250_500" <?= selectedOption('250_500', $budget) ?>>$250 - $500</option>
                <option value="500_plus" <?= selectedOption('500_plus', $budget) ?>>$500+</option>
            </select>
        </div>

        <div>
            <label>Sort</label>
            <select name="sort" style="width:100%;padding:10px;box-sizing:border-box;">
                <option value="newest" <?= selectedOption('newest', $sort) ?>>Newest</option>
                <option value="oldest" <?= selectedOption('oldest', $sort) ?>>Oldest</option>
                <option value="highest_budget" <?= selectedOption('highest_budget', $sort) ?>>Highest Budget</option>
                <option value="lowest_budget" <?= selectedOption('lowest_budget', $sort) ?>>Lowest Budget</option>
            </select>
        </div>

        <div>
            <button type="submit">Filter</button>
        </div>
    </div>
</form>

<?php if (empty($jobs)) { ?>
    <div style="background:white;padding:25px;border-radius:8px;">
        <h3>No available jobs found</h3>
        <p>Try clearing filters or checking back after new jobs are posted.</p>
    </div>
<?php } ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
    <?php foreach ($jobs as $job) { ?>
        <?php
            $skills = $job['skills'] ?? [];
            if (!is_array($skills)) {
                $skills = [];
            }
        ?>

        <div class="tf-job-card">
            <?php $firstImage = $job['images'][0]['image_path'] ?? null; ?>
            <?php if ($firstImage) { ?>
                <img class="tf-job-thumb" src="<?= htmlspecialchars(storageUrl($firstImage)) ?>" alt="Job image">
            <?php } else { ?>
                <div class="tf-job-placeholder">No photos yet</div>
            <?php } ?>

            <div class="tf-job-card-body">
            <h3 style="margin-top:0;">
                <?= htmlspecialchars(jobTitle($job)) ?>
            </h3>

            <p style="color:#555;min-height:42px;">
                <?= htmlspecialchars(substr($job['initial_description'] ?? '', 0, 140)) ?><?= strlen($job['initial_description'] ?? '') > 140 ? '...' : '' ?>
            </p>

            <p>
                <strong>Address:</strong><br>
                <?= htmlspecialchars($job['address'] ?? '') ?>
            </p>

            <p>
                <strong>Budget:</strong>
                <?= htmlspecialchars(formatCardMoney($job['agreed_price'] ?? null)) ?>
            </p>

            <?php if (!empty($skills)) { ?>
                <p>
                    <strong>Skills:</strong>
                    <?= htmlspecialchars(implode(', ', $skills)) ?>
                </p>
            <?php } ?>

            <p style="font-size:13px;color:#777;">
                Posted <?= htmlspecialchars(formatCardDate($job['created_at'] ?? null)) ?>
            </p>

            <a href="job_detail.php?id=<?= (int)$job['id'] ?>" class="tf-job-link">
                View Details
            </a>
            </div>
        </div>
    <?php } ?>
</div>

<?php if ($lastPage > 1) { ?>
    <div style="margin-top:25px;display:flex;gap:10px;">
        <?php if ($currentPage > 1) { ?>
            <a href="available_jobs.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1])) ?>">Previous</a>
        <?php } ?>

        <span>Page <?= (int)$currentPage ?> of <?= (int)$lastPage ?></span>

        <?php if ($currentPage < $lastPage) { ?>
            <a href="available_jobs.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1])) ?>">Next</a>
        <?php } ?>
    </div>
<?php } ?>

<?php include 'footer.php'; ?>
