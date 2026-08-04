<?php

require 'config.php';
requireLogin();

$jobId = (int)($_GET['id'] ?? $_POST['job_id'] ?? 0);
if (!$jobId) {
    die('Missing job ID');
}

$currentUser = apiRequest('GET', '/me');
$actionError = '';

function estApiSucceeded($response)
{
    if (!is_array($response)) {
        return false;
    }

    $code = (int)($response['_http_code'] ?? 200);
    return $code >= 200 && $code < 300 && empty($response['error']);
}

function estApiMessage($response)
{
    if (!is_array($response)) {
        return 'The TrustFix API returned an invalid response.';
    }

    if (!empty($response['message'])) {
        return (string)$response['message'];
    }
    if (!empty($response['error'])) {
        return (string)$response['error'];
    }
    if (!empty($response['errors']) && is_array($response['errors'])) {
        $messages = [];
        foreach ($response['errors'] as $fieldMessages) {
            foreach ((array)$fieldMessages as $fieldMessage) {
                $messages[] = $fieldMessage;
            }
        }
        if ($messages) {
            return implode(' ', $messages);
        }
    }

    return 'The requested estimate action could not be completed.';
}

function estScalarArray($values)
{
    $clean = [];
    foreach ((array)$values as $key => $value) {
        if (is_scalar($value)) {
            $clean[(string)$key] = trim((string)$value);
        }
    }
    return $clean;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = null;
    $successMessage = '';

    if ($action === 'analyze') {
        $response = apiRequest('POST', "/jobs/$jobId/estimate/analyze", [
            'answers' => estScalarArray($_POST['answers'] ?? []),
        ]);
        $successMessage = 'TrustFix updated the project analysis and price range.';
    } elseif ($action === 'review') {
        $steps = [];
        foreach ((array)($_POST['steps'] ?? []) as $step) {
            if (trim((string)($step['title'] ?? '')) === '') {
                continue;
            }
            $steps[] = [
                'title' => trim((string)($step['title'] ?? '')),
                'description' => trim((string)($step['description'] ?? '')),
                'hours_low' => (float)($step['hours_low'] ?? 0),
                'hours_high' => (float)($step['hours_high'] ?? 0),
            ];
        }

        $materials = [];
        foreach ((array)($_POST['materials'] ?? []) as $material) {
            if (trim((string)($material['name'] ?? '')) === '') {
                continue;
            }
            $materials[] = [
                'name' => trim((string)($material['name'] ?? '')),
                'quantity_low' => (float)($material['quantity_low'] ?? 0),
                'quantity_high' => (float)($material['quantity_high'] ?? 0),
                'unit' => trim((string)($material['unit'] ?? 'each')) ?: 'each',
                'unit_price_low' => (float)($material['unit_price_low'] ?? 0),
                'unit_price_high' => (float)($material['unit_price_high'] ?? 0),
                'notes' => trim((string)($material['notes'] ?? '')),
            ];
        }

        $response = apiRequest('PUT', "/jobs/$jobId/estimate", [
            'project_type' => trim((string)($_POST['project_type'] ?? 'general_repair')),
            'scope_summary' => trim((string)($_POST['scope_summary'] ?? '')),
            'steps' => $steps,
            'materials' => $materials,
        ]);
        $successMessage = 'Contractor review saved and the TrustFix-controlled price range was recalculated.';
    } elseif ($action === 'quote') {
        $response = apiRequest('POST', "/jobs/$jobId/estimate/quote", [
            'contractor_quote' => (float)($_POST['contractor_quote'] ?? 0),
        ]);
        $successMessage = 'The contractor quote was submitted to the customer.';
    } elseif ($action === 'accept') {
        $response = apiRequest('POST', "/jobs/$jobId/estimate/accept", []);
        $successMessage = 'The contractor quote was accepted and is now the agreed job price.';
    } elseif ($action === 'actuals') {
        $response = apiRequest('POST', "/jobs/$jobId/estimate/actuals", [
            'actual_hours' => (float)($_POST['actual_hours'] ?? 0),
            'actual_material_cost' => (float)($_POST['actual_material_cost'] ?? 0),
            'final_invoice' => (float)($_POST['final_invoice'] ?? 0),
        ]);
        $successMessage = 'Actual project results were saved for future TrustFix model training.';
    }

    if ($response !== null) {
        if (estApiSucceeded($response)) {
            $_SESSION['flash_success'] = $successMessage;
            header('Location: estimate_job.php?id=' . $jobId);
            exit;
        }

        $actionError = estApiMessage($response);
    }
}

$bundle = apiRequest('GET', "/jobs/$jobId/estimate");
if (!is_array($bundle) || !empty($bundle['error']) || (($bundle['_http_code'] ?? 200) >= 400)) {
    die('Estimate not found or you do not have permission to view it.');
}

$job = $bundle['job'] ?? [];
$estimate = $bundle['estimate'] ?? null;
$permissions = $bundle['permissions'] ?? [];

if (!empty($_GET['auto']) && !$estimate && !empty($permissions['can_analyze'])) {
    $autoResponse = apiRequest('POST', "/jobs/$jobId/estimate/analyze", ['answers' => []]);
    if (estApiSucceeded($autoResponse)) {
        $_SESSION['flash_success'] = 'TrustFix created the first project analysis. Review any follow-up questions below.';
        header('Location: estimate_job.php?id=' . $jobId);
        exit;
    }
    $actionError = estApiMessage($autoResponse);
    $bundle = apiRequest('GET', "/jobs/$jobId/estimate");
    $job = $bundle['job'] ?? $job;
    $estimate = $bundle['estimate'] ?? null;
    $permissions = $bundle['permissions'] ?? $permissions;
}

function estMoney($value)
{
    return '$' . number_format((float)$value, 2);
}

function estNumber($value)
{
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

function estLabel($value)
{
    return ucwords(str_replace('_', ' ', (string)$value));
}

function estTitle($text)
{
    $title = trim(strtok(trim((string)$text), "\n."));
    if ($title === '') {
        return 'TrustFix Job Estimate';
    }
    return strlen($title) > 90 ? substr($title, 0, 87) . '...' : $title;
}

$questions = is_array($estimate['follow_up_questions'] ?? null) ? $estimate['follow_up_questions'] : [];
$answers = is_array($estimate['intake_answers'] ?? null) ? $estimate['intake_answers'] : [];
$steps = is_array($estimate['steps'] ?? null) ? $estimate['steps'] : [];
$materials = is_array($estimate['materials'] ?? null) ? $estimate['materials'] : [];
$assumptions = is_array($estimate['assumptions'] ?? null) ? $estimate['assumptions'] : [];
$riskFlags = is_array($estimate['risk_flags'] ?? null) ? $estimate['risk_flags'] : [];
$missingPrices = is_array($bundle['missing_material_prices'] ?? null) ? $bundle['missing_material_prices'] : [];
$pricing = is_array($estimate['pricing_snapshot'] ?? null) ? $estimate['pricing_snapshot'] : [];

$questionKeys = [];
foreach ($questions as $question) {
    $questionKeys[] = (string)($question['key'] ?? '');
}

$baseWageLow = (float)($estimate['estimated_hours_low'] ?? 0) * (float)($pricing['hourly_wage'] ?? 0);
$baseWageHigh = (float)($estimate['estimated_hours_high'] ?? 0) * (float)($pricing['hourly_wage'] ?? 0);
$burdenLow = max(0, (float)($estimate['labor_cost_low'] ?? 0) - $baseWageLow);
$burdenHigh = max(0, (float)($estimate['labor_cost_high'] ?? 0) - $baseWageHigh);
$rawMaterialLow = 0;
$rawMaterialHigh = 0;
foreach ($materials as $material) {
    $rawMaterialLow += (float)($material['estimated_cost_low'] ?? 0);
    $rawMaterialHigh += (float)($material['estimated_cost_high'] ?? 0);
}
$materialMarkupLow = max(0, (float)($estimate['material_cost_low'] ?? 0) - $rawMaterialLow);
$materialMarkupHigh = max(0, (float)($estimate['material_cost_high'] ?? 0) - $rawMaterialHigh);

include 'header.php';
?>

<style>
    .est-shell{max-width:1220px;margin:0 auto 55px;color:#17222b}.est-hero{background:linear-gradient(135deg,#0d171e,#173d37);border-radius:18px;padding:28px 32px;color:#fff;margin-bottom:20px;display:flex;justify-content:space-between;gap:24px;align-items:center}.est-kicker{display:block;color:#53d69a;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:800}.est-hero h1{margin:5px 0 7px}.est-hero p{margin:0;color:#cfe0db}.est-range{text-align:right;min-width:240px}.est-range strong{display:block;font-size:28px;color:#6ce3ab}.est-range small{color:#cfe0db}.est-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(320px,1fr);gap:22px;align-items:start}.est-card{background:#fff;border:1px solid #dbe3e0;border-radius:12px;padding:22px;margin-bottom:18px;box-shadow:0 2px 7px rgba(14,35,29,.05)}.est-card h2,.est-card h3{margin-top:0}.est-badges{display:flex;flex-wrap:wrap;gap:8px;margin:13px 0}.est-badge{display:inline-block;padding:5px 10px;border-radius:99px;background:#e8f6ef;color:#126b49;font-size:12px;font-weight:800}.est-badge.neutral{background:#edf1f4;color:#41515e}.est-alert{border-radius:9px;padding:14px 16px;margin:14px 0}.est-alert.warn{background:#fff7df;border:1px solid #ead49a;color:#72520b}.est-alert.error{background:#fdebea;border:1px solid #efb4b1;color:#842b26}.est-alert.info{background:#eaf4ff;border:1px solid #bbd7f4;color:#154c79}.est-question{border-top:1px solid #e8ecea;padding:15px 0}.est-question:first-of-type{border-top:0}.est-question label{font-weight:800;display:block;margin-bottom:6px}.est-question small{display:block;color:#65726d;margin-bottom:8px}.est-table{width:100%;border-collapse:collapse}.est-table th,.est-table td{text-align:left;padding:10px 8px;border-bottom:1px solid #e6ebe8;vertical-align:top}.est-table th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#64716c}.est-table .num{text-align:right;white-space:nowrap}.est-total td{font-size:17px;font-weight:800;border-top:2px solid #1a8a5c}.est-steps{counter-reset:step}.est-step{position:relative;border-left:3px solid #2ab277;padding:0 0 18px 18px;margin-left:8px}.est-step h3{margin-bottom:4px}.est-step p{margin:0;color:#56645e}.est-step-time{font-weight:800;color:#13764f;margin-top:6px}.est-list{padding-left:20px}.est-list li{margin-bottom:8px}.est-form-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;align-items:end}.est-form-grid.material{grid-template-columns:1.6fr .65fr .65fr .7fr .75fr .75fr auto}.est-form-grid input{margin:0}.est-row-editor{border:1px solid #e1e7e4;border-radius:9px;padding:12px;margin-bottom:10px}.est-row-editor textarea{margin-top:8px;min-height:65px}.est-remove{background:#b53b34!important;color:#fff!important;width:auto!important;padding:9px 12px!important}.est-add{background:#e8f6ef!important;color:#126b49!important;width:auto!important;border:1px solid #b8dfcc!important}.est-primary{background:#16835a!important;color:#fff!important;width:auto!important}.est-accept{background:#146c43!important;color:#fff!important}.est-meta{font-size:13px;color:#64716c}.est-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.est-actions a,.est-actions button{width:auto;margin:0}.est-empty{text-align:center;padding:45px 25px}.est-price-source{font-size:11px;color:#6b7772}.est-missing{color:#a33c34;font-weight:800}.est-disclaimer{font-size:12px;color:#65726d;line-height:1.45}.est-formula{background:#f4f7f5;border-radius:8px;padding:12px;font-size:13px}.est-formula code{white-space:normal}.est-input-label{display:block;font-weight:700;margin-bottom:5px}@media(max-width:900px){.est-grid{grid-template-columns:1fr}.est-hero{align-items:flex-start;flex-direction:column}.est-range{text-align:left}.est-form-grid,.est-form-grid.material{grid-template-columns:1fr 1fr}.est-form-grid>:first-child,.est-form-grid.material>:first-child{grid-column:1/-1}}@media(max-width:580px){.est-form-grid,.est-form-grid.material{grid-template-columns:1fr}.est-form-grid>:first-child,.est-form-grid.material>:first-child{grid-column:auto}.est-table{font-size:13px}.est-hero{padding:23px 20px}}
</style>

<div class="est-shell">
    <p><a href="job_workspace.php?id=<?= $jobId ?>">&larr; Job Workspace</a> &nbsp;|&nbsp; <a href="job_detail.php?id=<?= $jobId ?>">Job Details</a></p>

    <?php if ($actionError !== '') { ?>
        <div class="est-alert error"><strong>Estimate action failed:</strong> <?= htmlspecialchars($actionError) ?></div>
    <?php } ?>

    <header class="est-hero">
        <div>
            <span class="est-kicker">TrustFix Smart Estimate</span>
            <h1><?= htmlspecialchars(estTitle($job['initial_description'] ?? '')) ?></h1>
            <p>Job #<?= $jobId ?> &bull; <?= htmlspecialchars($job['address'] ?? 'Address not listed') ?></p>
        </div>
        <?php if ($estimate) { ?>
            <div class="est-range">
                <small>TrustFix calculated range</small>
                <strong><?= estMoney($estimate['estimate_low'] ?? 0) ?>–<?= estMoney($estimate['estimate_high'] ?? 0) ?></strong>
                <small><?= estNumber($estimate['estimated_hours_low'] ?? 0) ?>–<?= estNumber($estimate['estimated_hours_high'] ?? 0) ?> labor hours</small>
            </div>
        <?php } ?>
    </header>

    <?php if (!$estimate) { ?>
        <section class="est-card est-empty">
            <span class="est-kicker">Start with understanding</span>
            <h2>No estimate has been generated yet</h2>
            <p>TrustFix will analyze the request and photos, ask follow-up questions, create a step-by-step work plan, and identify materials. TrustFix—not the AI provider—will calculate the money.</p>
            <?php if (!empty($permissions['can_analyze'])) { ?>
                <form method="POST">
                    <input type="hidden" name="job_id" value="<?= $jobId ?>">
                    <input type="hidden" name="action" value="analyze">
                    <button class="est-primary" type="submit">Generate Preliminary Estimate</button>
                </form>
            <?php } else { ?>
                <p class="est-alert info">The homeowner or assigned contractor must generate the initial analysis.</p>
            <?php } ?>
        </section>
    <?php } else { ?>
        <div class="est-badges">
            <span class="est-badge"><?= htmlspecialchars(estLabel($estimate['status'] ?? 'preliminary')) ?></span>
            <span class="est-badge neutral">Confidence: <?= htmlspecialchars(estLabel($estimate['confidence'] ?? 'low')) ?></span>
            <span class="est-badge neutral">Analysis: <?= htmlspecialchars(estLabel($estimate['analysis_provider'] ?? 'rules')) ?></span>
            <span class="est-badge neutral">Version <?= (int)($estimate['version'] ?? 1) ?></span>
        </div>

        <?php if (!empty($estimate['analysis_error'])) { ?>
            <div class="est-alert warn"><strong>Fallback used:</strong> <?= htmlspecialchars($estimate['analysis_error']) ?> The result remains editable by the contractor.</div>
        <?php } ?>
        <?php if (empty($pricing['configured'])) { ?>
            <div class="est-alert warn"><strong>Pricing setup required:</strong> this range uses visible starter assumptions. An administrator or assigned contractor should save real wage, insurance, tools, travel, overhead, and profit settings before a quote is submitted.<?php if (!empty($permissions['is_contractor']) || !empty($permissions['is_admin'])) { ?> <a href="estimate_settings.php">Open Estimate Settings</a>.<?php } ?></div>
        <?php } ?>

        <div class="est-grid">
            <main>
                <?php if ($questions && !empty($permissions['can_analyze'])) { ?>
                    <section class="est-card">
                        <span class="est-kicker">Improve confidence</span>
                        <h2>Follow-up questions</h2>
                        <p>These details can materially change labor time, materials, access, or safety. Answer what you know and TrustFix will rebuild the scope.</p>
                        <form method="POST">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="analyze">
                            <?php foreach ($answers as $key => $answer) { ?>
                                <?php if (!in_array((string)$key, $questionKeys, true)) { ?>
                                    <input type="hidden" name="answers[<?= htmlspecialchars((string)$key) ?>]" value="<?= htmlspecialchars((string)$answer) ?>">
                                <?php } ?>
                            <?php } ?>
                            <?php foreach ($questions as $question) { ?>
                                <?php
                                    $key = (string)($question['key'] ?? 'question');
                                    $type = $question['answer_type'] ?? 'text';
                                    $choices = is_array($question['choices'] ?? null) ? $question['choices'] : [];
                                ?>
                                <div class="est-question">
                                    <label for="answer-<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($question['question'] ?? '') ?></label>
                                    <small><?= htmlspecialchars($question['why_it_matters'] ?? '') ?></small>
                                    <?php if (in_array($type, ['yes_no', 'choice'], true) && $choices) { ?>
                                        <select id="answer-<?= htmlspecialchars($key) ?>" name="answers[<?= htmlspecialchars($key) ?>]" required>
                                            <option value="">Choose an answer</option>
                                            <?php foreach ($choices as $choice) { ?>
                                                <option value="<?= htmlspecialchars($choice) ?>"><?= htmlspecialchars($choice) ?></option>
                                            <?php } ?>
                                        </select>
                                    <?php } else { ?>
                                        <input id="answer-<?= htmlspecialchars($key) ?>" type="<?= $type === 'number' ? 'number' : 'text' ?>" step="<?= $type === 'number' ? '0.01' : '' ?>" name="answers[<?= htmlspecialchars($key) ?>]" required>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                            <button class="est-primary" type="submit">Update Analysis</button>
                        </form>
                    </section>
                <?php } ?>

                <?php if (!empty($permissions['can_review'])) { ?>
                    <form method="POST" id="contractor-review-form">
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">
                        <input type="hidden" name="action" value="review">

                        <section class="est-card">
                            <span class="est-kicker">Contractor control</span>
                            <h2>Review scope and classification</h2>
                            <label class="est-input-label" for="project_type">Project type</label>
                            <input id="project_type" name="project_type" value="<?= htmlspecialchars($estimate['project_type'] ?? 'general_repair') ?>" required>
                            <label class="est-input-label" for="scope_summary">Scope summary</label>
                            <textarea id="scope_summary" name="scope_summary" rows="6" required><?= htmlspecialchars($estimate['scope_summary'] ?? '') ?></textarea>
                        </section>

                        <section class="est-card">
                            <h2>Work steps and labor hours</h2>
                            <p class="est-meta">Adjust the AI/rules suggestion using your trade judgment. The high value must be at least the low value.</p>
                            <div id="step-editors">
                                <?php foreach ($steps as $index => $step) { ?>
                                    <div class="est-row-editor step-editor">
                                        <div class="est-form-grid">
                                            <div><label class="est-input-label">Step</label><input name="steps[<?= $index ?>][title]" value="<?= htmlspecialchars($step['title'] ?? '') ?>" required></div>
                                            <div><label class="est-input-label">Low hours</label><input type="number" min="0" step="0.25" name="steps[<?= $index ?>][hours_low]" value="<?= htmlspecialchars($step['hours_low'] ?? 0) ?>" required></div>
                                            <div><label class="est-input-label">High hours</label><input type="number" min="0" step="0.25" name="steps[<?= $index ?>][hours_high]" value="<?= htmlspecialchars($step['hours_high'] ?? 0) ?>" required></div>
                                        </div>
                                        <textarea name="steps[<?= $index ?>][description]" placeholder="What is included in this step?"><?= htmlspecialchars($step['description'] ?? '') ?></textarea>
                                        <button class="est-remove" type="button" onclick="this.closest('.step-editor').remove()">Remove step</button>
                                    </div>
                                <?php } ?>
                            </div>
                            <button class="est-add" type="button" id="add-step">+ Add work step</button>
                        </section>

                        <section class="est-card">
                            <h2>Materials and verified unit prices</h2>
                            <p class="est-meta">AI identifies materials and quantities; TrustFix uses catalog values or the contractor-entered prices below. Enter zero only when no material cost applies.</p>
                            <div id="material-editors">
                                <?php foreach ($materials as $index => $material) { ?>
                                    <div class="est-row-editor material-editor">
                                        <div class="est-form-grid material">
                                            <div><label class="est-input-label">Material</label><input name="materials[<?= $index ?>][name]" value="<?= htmlspecialchars($material['name'] ?? '') ?>" required></div>
                                            <div><label class="est-input-label">Qty low</label><input type="number" min="0" step="0.01" name="materials[<?= $index ?>][quantity_low]" value="<?= htmlspecialchars($material['quantity_low'] ?? 0) ?>" required></div>
                                            <div><label class="est-input-label">Qty high</label><input type="number" min="0" step="0.01" name="materials[<?= $index ?>][quantity_high]" value="<?= htmlspecialchars($material['quantity_high'] ?? 0) ?>" required></div>
                                            <div><label class="est-input-label">Unit</label><input name="materials[<?= $index ?>][unit]" value="<?= htmlspecialchars($material['unit'] ?? 'each') ?>" required></div>
                                            <div><label class="est-input-label">$ / unit low</label><input type="number" min="0" step="0.01" name="materials[<?= $index ?>][unit_price_low]" value="<?= htmlspecialchars($material['unit_price_low'] ?? 0) ?>"></div>
                                            <div><label class="est-input-label">$ / unit high</label><input type="number" min="0" step="0.01" name="materials[<?= $index ?>][unit_price_high]" value="<?= htmlspecialchars($material['unit_price_high'] ?? 0) ?>"></div>
                                            <button class="est-remove" type="button" onclick="this.closest('.material-editor').remove()">Remove</button>
                                        </div>
                                        <textarea name="materials[<?= $index ?>][notes]" placeholder="Quality, size, source, delivery, or other material notes"><?= htmlspecialchars($material['notes'] ?? '') ?></textarea>
                                    </div>
                                <?php } ?>
                            </div>
                            <button class="est-add" type="button" id="add-material">+ Add material</button>
                            <div style="margin-top:18px"><button class="est-primary" type="submit">Save Contractor Review & Recalculate</button></div>
                        </section>
                    </form>
                <?php } else { ?>
                    <section class="est-card">
                        <span class="est-kicker">Understood scope</span>
                        <h2><?= htmlspecialchars(estLabel($estimate['project_type'] ?? 'general_repair')) ?></h2>
                        <p style="white-space:pre-wrap"><?= htmlspecialchars($estimate['scope_summary'] ?? '') ?></p>
                    </section>

                    <section class="est-card">
                        <h2>Work plan</h2>
                        <?php foreach ($steps as $step) { ?>
                            <div class="est-step">
                                <h3><?= htmlspecialchars($step['title'] ?? 'Work step') ?></h3>
                                <p><?= htmlspecialchars($step['description'] ?? '') ?></p>
                                <div class="est-step-time"><?= estNumber($step['hours_low'] ?? 0) ?>–<?= estNumber($step['hours_high'] ?? 0) ?> hours</div>
                            </div>
                        <?php } ?>
                    </section>

                    <section class="est-card">
                        <h2>Materials</h2>
                        <?php if (!$materials) { ?><p>No specific materials were identified yet.</p><?php } ?>
                        <table class="est-table">
                            <?php foreach ($materials as $material) { ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($material['name'] ?? '') ?></strong><br><span class="est-meta"><?= htmlspecialchars($material['notes'] ?? '') ?></span></td>
                                    <td class="num"><?= estNumber($material['quantity_low'] ?? 0) ?>–<?= estNumber($material['quantity_high'] ?? 0) ?> <?= htmlspecialchars($material['unit'] ?? '') ?></td>
                                    <td class="num"><?php if (!empty($material['price_missing'])) { ?><span class="est-missing">Price needed</span><?php } else { ?><?= estMoney($material['estimated_cost_low'] ?? 0) ?>–<?= estMoney($material['estimated_cost_high'] ?? 0) ?><br><span class="est-price-source"><?= htmlspecialchars($material['price_source'] ?? '') ?></span><?php } ?></td>
                                </tr>
                            <?php } ?>
                        </table>
                    </section>
                <?php } ?>
            </main>

            <aside>
                <section class="est-card">
                    <span class="est-kicker">TrustFix calculation</span>
                    <h2>Price breakdown</h2>
                    <table class="est-table">
                        <thead><tr><th>Component</th><th class="num">Low</th><th class="num">High</th></tr></thead>
                        <tbody>
                            <tr><td>Worker wages<br><span class="est-meta"><?= estMoney($pricing['hourly_wage'] ?? 0) ?>/hr</span></td><td class="num"><?= estMoney($baseWageLow) ?></td><td class="num"><?= estMoney($baseWageHigh) ?></td></tr>
                            <tr><td>Payroll burden <span class="est-meta"><?= estNumber($pricing['payroll_burden_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($burdenLow) ?></td><td class="num"><?= estMoney($burdenHigh) ?></td></tr>
                            <tr><td>Materials</td><td class="num"><?= estMoney($rawMaterialLow) ?></td><td class="num"><?= estMoney($rawMaterialHigh) ?></td></tr>
                            <tr><td>Material markup <span class="est-meta"><?= estNumber($pricing['material_markup_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($materialMarkupLow) ?></td><td class="num"><?= estMoney($materialMarkupHigh) ?></td></tr>
                            <tr><td>Travel</td><td class="num"><?= estMoney($estimate['travel_cost'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['travel_cost'] ?? 0) ?></td></tr>
                            <tr><td>Insurance <span class="est-meta"><?= estNumber($pricing['insurance_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($estimate['insurance_cost_low'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['insurance_cost_high'] ?? 0) ?></td></tr>
                            <tr><td>Tools/equipment <span class="est-meta"><?= estNumber($pricing['tools_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($estimate['tools_cost_low'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['tools_cost_high'] ?? 0) ?></td></tr>
                            <tr><td>Business overhead <span class="est-meta"><?= estNumber($pricing['overhead_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($estimate['overhead_cost_low'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['overhead_cost_high'] ?? 0) ?></td></tr>
                            <tr><td>Profit <span class="est-meta"><?= estNumber($pricing['profit_percent'] ?? 0) ?>%</span></td><td class="num"><?= estMoney($estimate['profit_low'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['profit_high'] ?? 0) ?></td></tr>
                            <tr class="est-total"><td>Fair price range</td><td class="num"><?= estMoney($estimate['estimate_low'] ?? 0) ?></td><td class="num"><?= estMoney($estimate['estimate_high'] ?? 0) ?></td></tr>
                        </tbody>
                    </table>
                    <p class="est-disclaimer">The range includes a <?= estNumber($pricing['uncertainty_percent'] ?? 0) ?>% uncertainty allowance. It is a planning estimate, not a binding quote, until a contractor reviews the site, scope, and current material prices.</p>
                    <?php if ($missingPrices) { ?>
                        <div class="est-alert error"><strong>Missing material prices:</strong> <?= htmlspecialchars(implode(', ', $missingPrices)) ?>. These items currently add $0 and must be priced before quoting.</div>
                    <?php } ?>
                </section>

                <section class="est-card">
                    <h2>Contractor quote</h2>
                    <?php if (!empty($estimate['contractor_quote'])) { ?>
                        <p style="font-size:28px;font-weight:800;color:#13764f;margin:8px 0"><?= estMoney($estimate['contractor_quote']) ?></p>
                        <p class="est-meta">Submitted <?= htmlspecialchars($estimate['quoted_at'] ?? '') ?></p>
                    <?php } else { ?>
                        <p>No binding contractor quote has been submitted.</p>
                    <?php } ?>

                    <?php if (!empty($permissions['can_quote']) && ($estimate['status'] ?? '') === 'contractor_reviewed' && !empty($pricing['configured']) && !$missingPrices) { ?>
                        <form method="POST">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="quote">
                            <label class="est-input-label" for="contractor_quote">Final contractor quote</label>
                            <input id="contractor_quote" type="number" min="0.01" max="999999.99" step="0.01" name="contractor_quote" value="<?= htmlspecialchars($estimate['estimate_high'] ?? '') ?>" required>
                            <button class="est-primary" type="submit">Submit Quote to Customer</button>
                        </form>
                    <?php } elseif (!empty($permissions['can_quote']) && ($estimate['status'] ?? '') === 'contractor_reviewed' && (empty($pricing['configured']) || $missingPrices)) { ?>
                        <p class="est-alert warn">Complete the pricing setup and all material unit prices before submitting a quote.</p>
                    <?php } elseif (!empty($permissions['can_quote']) && empty($estimate['contractor_quote'])) { ?>
                        <p class="est-alert info">Save the contractor review above before submitting a quote.</p>
                    <?php } ?>

                    <?php if (!empty($permissions['can_accept'])) { ?>
                        <form method="POST" onsubmit="return confirm('Accept this contractor quote as the agreed job price?');">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="accept">
                            <button class="est-accept" type="submit">Accept Contractor Quote</button>
                        </form>
                    <?php } ?>

                    <?php if (!empty($estimate['accepted_price'])) { ?>
                        <div class="est-alert info"><strong>Accepted price:</strong> <?= estMoney($estimate['accepted_price']) ?></div>
                    <?php } ?>
                </section>

                <?php if (!empty($permissions['can_record_actuals'])) { ?>
                    <section class="est-card">
                        <span class="est-kicker">Future model data</span>
                        <h2>Actual project outcome</h2>
                        <p class="est-meta">Record the result after work is performed. These values remain separate from the original estimate so TrustFix can measure error and train a future model.</p>
                        <form method="POST">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="actuals">
                            <label class="est-input-label">Actual labor hours</label>
                            <input type="number" min="0" max="10000" step="0.25" name="actual_hours" value="<?= htmlspecialchars($estimate['actual_hours'] ?? '') ?>" required>
                            <label class="est-input-label">Actual material cost</label>
                            <input type="number" min="0" max="999999.99" step="0.01" name="actual_material_cost" value="<?= htmlspecialchars($estimate['actual_material_cost'] ?? '') ?>" required>
                            <label class="est-input-label">Final invoice</label>
                            <input type="number" min="0" max="999999.99" step="0.01" name="final_invoice" value="<?= htmlspecialchars($estimate['final_invoice'] ?? '') ?>" required>
                            <button class="est-primary" type="submit">Save Actual Results</button>
                        </form>
                    </section>
                <?php } ?>

                <section class="est-card">
                    <h3>Assumptions</h3>
                    <ul class="est-list"><?php foreach ($assumptions as $assumption) { ?><li><?= htmlspecialchars($assumption) ?></li><?php } ?></ul>
                    <h3>Risks / exclusions</h3>
                    <ul class="est-list"><?php foreach ($riskFlags as $risk) { ?><li><?= htmlspecialchars($risk) ?></li><?php } ?></ul>
                </section>
            </aside>
        </div>
    <?php } ?>
</div>

<?php if ($estimate && !empty($permissions['can_review'])) { ?>
<script>
(function() {
    let stepIndex = <?= count($steps) ?>;
    let materialIndex = <?= count($materials) ?>;

    document.getElementById('add-step').addEventListener('click', function() {
        const wrapper = document.createElement('div');
        wrapper.className = 'est-row-editor step-editor';
        wrapper.innerHTML = `<div class="est-form-grid"><div><label class="est-input-label">Step</label><input name="steps[${stepIndex}][title]" required></div><div><label class="est-input-label">Low hours</label><input type="number" min="0" step="0.25" name="steps[${stepIndex}][hours_low]" value="0" required></div><div><label class="est-input-label">High hours</label><input type="number" min="0" step="0.25" name="steps[${stepIndex}][hours_high]" value="0" required></div></div><textarea name="steps[${stepIndex}][description]" placeholder="What is included in this step?"></textarea><button class="est-remove" type="button" onclick="this.closest('.step-editor').remove()">Remove step</button>`;
        document.getElementById('step-editors').appendChild(wrapper);
        stepIndex++;
    });

    document.getElementById('add-material').addEventListener('click', function() {
        const wrapper = document.createElement('div');
        wrapper.className = 'est-row-editor material-editor';
        wrapper.innerHTML = `<div class="est-form-grid material"><div><label class="est-input-label">Material</label><input name="materials[${materialIndex}][name]" required></div><div><label class="est-input-label">Qty low</label><input type="number" min="0" step="0.01" name="materials[${materialIndex}][quantity_low]" value="1" required></div><div><label class="est-input-label">Qty high</label><input type="number" min="0" step="0.01" name="materials[${materialIndex}][quantity_high]" value="1" required></div><div><label class="est-input-label">Unit</label><input name="materials[${materialIndex}][unit]" value="each" required></div><div><label class="est-input-label">$ / unit low</label><input type="number" min="0" step="0.01" name="materials[${materialIndex}][unit_price_low]" value="0"></div><div><label class="est-input-label">$ / unit high</label><input type="number" min="0" step="0.01" name="materials[${materialIndex}][unit_price_high]" value="0"></div><button class="est-remove" type="button" onclick="this.closest('.material-editor').remove()">Remove</button></div><textarea name="materials[${materialIndex}][notes]" placeholder="Quality, size, source, delivery, or other material notes"></textarea>`;
        document.getElementById('material-editors').appendChild(wrapper);
        materialIndex++;
    });
})();
</script>
<?php } ?>

<?php include 'footer.php'; ?>
