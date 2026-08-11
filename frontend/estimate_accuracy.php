<?php

require 'config.php';
requireLogin();

$user = apiRequest('GET', '/me');
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Administrator access is required.');
}

$filters = [
    'project_type' => trim((string)($_GET['project_type'] ?? '')),
    'zip_code' => trim((string)($_GET['zip_code'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];
$query = http_build_query(array_filter(
    $filters,
    fn($value) => $value !== ''
));
$response = apiRequest(
    'GET',
    '/admin/estimate-accuracy' . ($query ? '?' . $query : '')
);
$summary = $response['summary'] ?? [];
$projectTypes = $response['by_project_type'] ?? [];
$zipCodes = $response['by_zip_code'] ?? [];
$samples = $response['recent_samples'] ?? [];
$options = $response['filter_options'] ?? [];
$error = empty($response['summary'])
    ? apiMessage($response, 'Unable to load estimate accuracy data.')
    : '';

function eaLabel($value)
{
    return ucwords(str_replace('_', ' ', (string)$value));
}

function eaPercent($value)
{
    return ($value === null || $value === '')
        ? '—'
        : number_format((float)$value, 1) . '%';
}

function eaMoney($value)
{
    return ($value === null || $value === '')
        ? '—'
        : '$' . number_format((float)$value, 2);
}

function eaWidth($value)
{
    return max(0, min(100, (float)($value ?? 0)));
}

include 'header.php';
?>

<style>
    .ea-shell{max-width:1250px;margin:0 auto 60px;padding:0 18px}.ea-hero{background:linear-gradient(135deg,#05080c,#17345b);border-bottom:4px solid #4EA8DE;border-radius:16px;color:#fff;padding:30px 34px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:22px}.ea-kicker{color:#78c9f4;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:800}.ea-hero h1{font-size:34px;margin:6px 0 8px}.ea-hero p{margin:0;color:#c8d6e0;max-width:700px;line-height:1.5}.ea-hero-links{display:flex;gap:9px;flex-wrap:wrap}.ea-hero a{background:#fff;color:#17345b;padding:11px 14px;border-radius:999px;text-decoration:none;font-weight:800;white-space:nowrap}.ea-card{background:#fff;border:1px solid #d9e2ec;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(17,24,39,.05)}.ea-filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;align-items:end;margin-bottom:20px}.ea-field label{display:block;font-size:12px;font-weight:800;color:#52616b;margin-bottom:6px}.ea-field input,.ea-field select{margin:0;width:100%;height:42px;border:1px solid #c6d4dd;border-radius:8px;background:#fff}.ea-filter-button{height:42px;margin:0;border:0;border-radius:8px;background:#2d6cdf;color:#fff;font-weight:800;cursor:pointer}.ea-metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}.ea-metric{background:#fff;border:1px solid #d9e2ec;border-top:4px solid #4EA8DE;border-radius:11px;padding:17px}.ea-metric strong{display:block;font-size:28px;color:#101820}.ea-metric span{color:#65737e;font-size:12px;line-height:1.4}.ea-readiness{padding:17px 19px;margin-bottom:20px;border-radius:10px;border-left:5px solid #2d6cdf;background:#eaf4ff;color:#263642}.ea-readiness.ready{border-color:#16835a;background:#edf8f2}.ea-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px}.ea-card h2{margin:0 0 5px;color:#101820}.ea-card-intro{margin:0 0 15px;color:#65737e;font-size:13px}.ea-table-wrap{overflow-x:auto}.ea-table{width:100%;border-collapse:collapse;min-width:620px}.ea-table.wide{min-width:1050px}.ea-table th,.ea-table td{padding:10px 9px;border-bottom:1px solid #e2e8ed;text-align:left;vertical-align:middle}.ea-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#65737e}.ea-table .num{text-align:right;white-space:nowrap}.ea-bar{height:7px;background:#e5ebef;border-radius:99px;overflow:hidden;margin-top:4px}.ea-bar span{display:block;height:100%;background:linear-gradient(90deg,#2d6cdf,#4EA8DE)}.ea-good{color:#13764f;font-weight:800}.ea-warn{color:#b36b00;font-weight:800}.ea-empty{text-align:center;color:#65737e;padding:28px}.ea-note{font-size:12px;color:#65737e;margin-top:12px}@media(max-width:980px){.ea-filters{grid-template-columns:1fr 1fr}.ea-metrics{grid-template-columns:1fr 1fr}.ea-grid{grid-template-columns:1fr}}@media(max-width:620px){.ea-hero{align-items:flex-start;flex-direction:column;padding:24px 21px}.ea-hero h1{font-size:29px}.ea-filters,.ea-metrics{grid-template-columns:1fr}}
</style>

<main class="ea-shell">
    <header class="ea-hero">
        <div>
            <span class="ea-kicker">Estimate quality</span>
            <h1>Accuracy Dashboard</h1>
            <p>Measure how TrustFix estimate ranges and contractor quotes compare with completed-job invoices and labor hours.</p>
        </div>
        <div class="ea-hero-links">
            <a href="estimate_training_data.php">View ML Data</a>
            <a href="manage_material_prices.php">Material Prices</a>
        </div>
    </header>

    <?php if ($error) { ?>
        <div class="tf-alert tf-alert-error"><?= htmlspecialchars($error) ?></div>
    <?php } ?>

    <form class="ea-card ea-filters" method="GET">
        <div class="ea-field">
            <label for="project_type">Project type</label>
            <select id="project_type" name="project_type">
                <option value="">All project types</option>
                <?php foreach (($options['project_types'] ?? []) as $option) { ?>
                    <option value="<?= htmlspecialchars($option) ?>" <?= $filters['project_type'] === $option ? 'selected' : '' ?>>
                        <?= htmlspecialchars(eaLabel($option)) ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="ea-field">
            <label for="zip_code">ZIP code</label>
            <select id="zip_code" name="zip_code">
                <option value="">All ZIP codes</option>
                <?php foreach (($options['zip_codes'] ?? []) as $option) { ?>
                    <option value="<?= htmlspecialchars($option) ?>" <?= $filters['zip_code'] === $option ? 'selected' : '' ?>>
                        <?= htmlspecialchars($option) ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="ea-field">
            <label for="date_from">Estimate created from</label>
            <input id="date_from" type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div class="ea-field">
            <label for="date_to">Created through</label>
            <input id="date_to" type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <button class="ea-filter-button" type="submit">Apply filters</button>
    </form>

    <section class="ea-metrics">
        <div class="ea-metric">
            <strong><?= (int)($summary['sample_count'] ?? 0) ?></strong>
            <span>Completed samples with a final invoice</span>
        </div>
        <div class="ea-metric">
            <strong><?= eaPercent($summary['in_range_percent'] ?? null) ?></strong>
            <span>Final invoices inside the TrustFix range</span>
        </div>
        <div class="ea-metric">
            <strong><?= eaPercent($summary['average_midpoint_error_percent'] ?? null) ?></strong>
            <span>Average estimate-midpoint error</span>
        </div>
        <div class="ea-metric">
            <strong><?= eaPercent($summary['average_quote_error_percent'] ?? null) ?></strong>
            <span>Average contractor-quote error</span>
        </div>
    </section>

    <?php $ready = !empty($summary['baseline_training_ready']); ?>
    <section class="ea-readiness <?= $ready ? 'ready' : '' ?>">
        <strong><?= $ready ? 'Baseline dataset ready' : 'Keep collecting completed jobs' ?></strong>
        <div>
            <?php if ($ready) { ?>
                TrustFix has reached the <?= (int)($summary['baseline_sample_target'] ?? 30) ?>-sample baseline. Validate individual project types before training a production model.
            <?php } else { ?>
                Add actual hours, actual material cost, and final invoice for
                <strong><?= (int)($summary['samples_needed_for_baseline'] ?? 0) ?> more completed jobs</strong>
                to reach the first <?= (int)($summary['baseline_sample_target'] ?? 30) ?>-sample checkpoint.
            <?php } ?>
        </div>
    </section>

    <section class="ea-grid">
        <div class="ea-card">
            <h2>By project type</h2>
            <p class="ea-card-intro">Ten completed samples per type is the first useful calibration checkpoint.</p>
            <div class="ea-table-wrap">
                <table class="ea-table">
                    <thead>
                        <tr><th>Project type</th><th class="num">Samples</th><th class="num">In range</th><th class="num">Midpoint error</th><th class="num">Quote error</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($projectTypes as $group) { ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars(eaLabel($group['project_type'] ?? 'unknown')) ?></strong>
                                    <div class="ea-bar"><span style="width:<?= eaWidth(($group['sample_count'] ?? 0) * 10) ?>%"></span></div>
                                </td>
                                <td class="num <?= ($group['sample_count'] ?? 0) >= 10 ? 'ea-good' : 'ea-warn' ?>"><?= (int)($group['sample_count'] ?? 0) ?></td>
                                <td class="num"><?= eaPercent($group['in_range_percent'] ?? null) ?></td>
                                <td class="num"><?= eaPercent($group['average_midpoint_error_percent'] ?? null) ?></td>
                                <td class="num"><?= eaPercent($group['average_quote_error_percent'] ?? null) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php if (!$projectTypes) { ?><div class="ea-empty">No completed estimate samples match these filters.</div><?php } ?>
            </div>
        </div>

        <div class="ea-card">
            <h2>By ZIP code</h2>
            <p class="ea-card-intro">Use location groups to spot labor and material-price differences.</p>
            <div class="ea-table-wrap">
                <table class="ea-table">
                    <thead>
                        <tr><th>ZIP</th><th class="num">Samples</th><th class="num">In range</th><th class="num">Midpoint error</th><th class="num">Avg. invoice</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($zipCodes as $group) { ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($group['zip_code'] ?? 'Unknown') ?></strong></td>
                                <td class="num"><?= (int)($group['sample_count'] ?? 0) ?></td>
                                <td class="num"><?= eaPercent($group['in_range_percent'] ?? null) ?></td>
                                <td class="num"><?= eaPercent($group['average_midpoint_error_percent'] ?? null) ?></td>
                                <td class="num"><?= eaMoney($group['average_final_invoice'] ?? null) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php if (!$zipCodes) { ?><div class="ea-empty">No ZIP-level samples match these filters.</div><?php } ?>
            </div>
        </div>
    </section>

    <section class="ea-card">
        <h2>Recent completed samples</h2>
        <p class="ea-card-intro">A low percentage is better for each error column. “In range” means the final invoice landed between the original TrustFix low and high estimate.</p>
        <div class="ea-table-wrap">
            <table class="ea-table wide">
                <thead>
                    <tr><th>Job</th><th>Project / ZIP</th><th class="num">Estimate range</th><th class="num">Quote</th><th class="num">Final invoice</th><th class="num">In range</th><th class="num">Midpoint error</th><th class="num">Hours error</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($samples as $sample) { ?>
                        <tr>
                            <td><a href="estimate_job.php?id=<?= (int)($sample['job_id'] ?? 0) ?>">Job #<?= (int)($sample['job_id'] ?? 0) ?></a></td>
                            <td><?= htmlspecialchars(eaLabel($sample['project_type'] ?? 'unknown')) ?><br><span class="ea-note"><?= htmlspecialchars($sample['zip_code'] ?? 'Unknown') ?></span></td>
                            <td class="num"><?= eaMoney($sample['estimate_low'] ?? null) ?>–<?= eaMoney($sample['estimate_high'] ?? null) ?></td>
                            <td class="num"><?= eaMoney($sample['contractor_quote'] ?? null) ?></td>
                            <td class="num"><strong><?= eaMoney($sample['final_invoice'] ?? null) ?></strong></td>
                            <td class="num <?= !empty($sample['in_estimate_range']) ? 'ea-good' : 'ea-warn' ?>"><?= !empty($sample['in_estimate_range']) ? 'Yes' : 'No' ?></td>
                            <td class="num"><?= eaPercent($sample['midpoint_error_percent'] ?? null) ?></td>
                            <td class="num"><?= eaPercent($sample['hours_error_percent'] ?? null) ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <?php if (!$samples) { ?><div class="ea-empty">Record actual job results and final invoices to populate this dashboard.</div><?php } ?>
        </div>
        <p class="ea-note">
            Funnel: <?= (int)($summary['total_estimates'] ?? 0) ?> estimates,
            <?= (int)($summary['quoted_estimates'] ?? 0) ?> quoted,
            <?= (int)($summary['accepted_estimates'] ?? 0) ?> accepted, and
            <?= (int)($summary['actuals_complete'] ?? 0) ?> with complete actuals.
        </p>
    </section>
</main>

<?php include 'footer.php'; ?>
