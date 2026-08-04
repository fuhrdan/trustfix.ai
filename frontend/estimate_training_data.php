<?php

require 'config.php';
requireLogin();

$user = apiRequest('GET', '/me');
if (($user['role'] ?? '') !== 'admin') {
    die('Administrator access is required.');
}

$response = apiRequest('GET', '/admin/estimate-training-data?per_page=500');
$rows = $response['data'] ?? [];

$csvFields = [
    'estimate_id',
    'job_id',
    'job_status',
    'estimate_status',
    'analysis_provider',
    'analysis_model',
    'project_type',
    'zip_code',
    'photo_count',
    'confidence',
    'description',
    'scope_summary',
    'estimated_hours_low',
    'estimated_hours_high',
    'actual_hours',
    'estimated_material_cost_low',
    'estimated_material_cost_high',
    'actual_material_cost',
    'contractor_quote',
    'accepted_price',
    'final_invoice',
    'steps',
    'materials',
    'completeness_percent',
    'created_at',
];

if (!empty($_GET['download'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="trustfix-estimate-training-data-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $csvFields);

    foreach ($rows as $row) {
        $line = [];
        foreach ($csvFields as $field) {
            $value = $row[$field] ?? null;
            $line[] = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $value;
        }
        fputcsv($output, $line);
    }
    fclose($output);
    exit;
}

$averageCompleteness = 0;
if ($rows) {
    $averageCompleteness = (int)round(array_sum(array_column($rows, 'completeness_percent')) / count($rows));
}

function etdValue($value, $fallback = '—')
{
    return ($value === null || $value === '') ? $fallback : htmlspecialchars((string)$value);
}

function etdMoney($value)
{
    return ($value === null || $value === '') ? '—' : '$' . number_format((float)$value, 2);
}

include 'header.php';
?>

<style>
    .etd-shell{max-width:1250px;margin:0 auto 55px}.etd-hero{background:linear-gradient(135deg,#111927,#26355c);border-radius:16px;color:#fff;padding:28px 32px;margin-bottom:22px;display:flex;justify-content:space-between;gap:20px;align-items:center}.etd-kicker{color:#89a8ff;text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:800}.etd-hero h1{margin:5px 0}.etd-hero p{margin:0;color:#d6dced}.etd-hero a{background:#fff;color:#23345d;padding:11px 15px;border-radius:8px;text-decoration:none;font-weight:800;white-space:nowrap}.etd-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:20px}.etd-metric{background:#fff;border:1px solid #dce2e9;border-radius:11px;padding:18px}.etd-metric strong{display:block;font-size:27px}.etd-metric span{color:#67727e;font-size:13px}.etd-card{background:#fff;border:1px solid #dce2e9;border-radius:12px;padding:20px;box-shadow:0 2px 7px rgba(0,0,0,.05)}.etd-table-wrap{overflow-x:auto}.etd-table{width:100%;border-collapse:collapse;min-width:1050px}.etd-table th,.etd-table td{text-align:left;padding:10px 8px;border-bottom:1px solid #e5e9ee;vertical-align:top}.etd-table th{font-size:11px;text-transform:uppercase;color:#657180}.etd-table .num{text-align:right;white-space:nowrap}.etd-progress{height:8px;background:#e7ebf1;border-radius:99px;overflow:hidden;margin-top:5px}.etd-progress span{display:block;height:100%;background:linear-gradient(90deg,#778fdf,#2d9b70)}.etd-complete{font-weight:800}.etd-note{background:#eef3ff;border-left:4px solid #6681d0;padding:15px;border-radius:8px;margin-bottom:18px}.etd-missing{color:#a34840}@media(max-width:760px){.etd-hero{align-items:flex-start;flex-direction:column;padding:24px 20px}.etd-metrics{grid-template-columns:1fr}}
</style>

<div class="etd-shell">
    <header class="etd-hero">
        <div>
            <span class="etd-kicker">Future TrustFix model</span>
            <h1>Estimate Training Data</h1>
            <p>Compare what TrustFix predicted with what contractors quoted and projects actually required.</p>
        </div>
        <a href="estimate_training_data.php?download=1">Download CSV</a>
    </header>

    <div class="etd-metrics">
        <div class="etd-metric"><strong><?= count($rows) ?></strong><span>Estimate records loaded</span></div>
        <div class="etd-metric"><strong><?= $averageCompleteness ?>%</strong><span>Average training completeness</span></div>
        <div class="etd-metric"><strong><?= count(array_filter($rows, fn($row) => !empty($row['final_invoice']))) ?></strong><span>Projects with final invoices</span></div>
    </div>

    <div class="etd-note"><strong>Training readiness:</strong> A useful row needs project type, ZIP, estimated hours, actual hours, actual material cost, contractor quote, accepted price, and final invoice. Photos remain attached to the job; the CSV records their count and the job ID needed to resolve them later.</div>

    <section class="etd-card">
        <div class="etd-table-wrap">
            <table class="etd-table">
                <thead><tr><th>Job</th><th>Project / ZIP</th><th>Photos</th><th class="num">Hours est. / actual</th><th class="num">Materials est. / actual</th><th class="num">Quote / accepted / invoice</th><th>Provider</th><th>Completeness</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><a href="estimate_job.php?id=<?= (int)$row['job_id'] ?>">Job #<?= (int)$row['job_id'] ?></a><br><span style="font-size:12px;color:#697583"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['estimate_status'] ?? ''))) ?></span></td>
                            <td><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['project_type'] ?? ''))) ?></strong><br><?= etdValue($row['zip_code'] ?? null) ?></td>
                            <td class="num"><?= (int)($row['photo_count'] ?? 0) ?></td>
                            <td class="num"><?= etdValue($row['estimated_hours_low'] ?? null) ?>–<?= etdValue($row['estimated_hours_high'] ?? null) ?><br><span class="<?= empty($row['actual_hours']) ? 'etd-missing' : '' ?>">Actual: <?= etdValue($row['actual_hours'] ?? null) ?></span></td>
                            <td class="num"><?= etdMoney($row['estimated_material_cost_low'] ?? null) ?>–<?= etdMoney($row['estimated_material_cost_high'] ?? null) ?><br><span class="<?= empty($row['actual_material_cost']) ? 'etd-missing' : '' ?>">Actual: <?= etdMoney($row['actual_material_cost'] ?? null) ?></span></td>
                            <td class="num"><?= etdMoney($row['contractor_quote'] ?? null) ?><br><?= etdMoney($row['accepted_price'] ?? null) ?><br><strong><?= etdMoney($row['final_invoice'] ?? null) ?></strong></td>
                            <td><?= htmlspecialchars(ucfirst($row['analysis_provider'] ?? 'rules')) ?><br><span style="font-size:12px;color:#697583"><?= htmlspecialchars($row['analysis_model'] ?? 'No external model') ?></span></td>
                            <td style="min-width:120px"><span class="etd-complete"><?= (int)($row['completeness_percent'] ?? 0) ?>%</span><div class="etd-progress"><span style="width:<?= max(0, min(100, (int)($row['completeness_percent'] ?? 0))) ?>%"></span></div></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (!$rows) { ?><p style="padding:25px;text-align:center">No estimates have been generated yet. Data will appear here automatically as the workflow is used.</p><?php } ?>
    </section>
</div>

<?php include 'footer.php'; ?>
