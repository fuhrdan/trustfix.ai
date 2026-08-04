<?php

require 'config.php';
requireLogin();

$user = apiRequest('GET', '/me');
$role = $user['role'] ?? '';
if (!in_array($role, ['handyman', 'admin'], true)) {
    die('Contractor or administrator access is required.');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'name',
        'hourly_wage',
        'payroll_burden_percent',
        'insurance_percent',
        'tools_percent',
        'material_markup_percent',
        'travel_flat_fee',
        'overhead_percent',
        'profit_percent',
        'uncertainty_percent',
    ];
    $payload = [];
    foreach ($fields as $field) {
        $payload[$field] = $field === 'name'
            ? trim((string)($_POST[$field] ?? 'TrustFix pricing'))
            : (float)($_POST[$field] ?? 0);
    }

    $result = apiRequest('PUT', '/estimate-pricing-profile', $payload);
    $httpCode = (int)($result['_http_code'] ?? 200);
    if (is_array($result) && $httpCode >= 200 && $httpCode < 300 && empty($result['error'])) {
        $_SESSION['flash_success'] = 'Estimate pricing settings saved. New contractor reviews will use these values.';
        header('Location: estimate_settings.php');
        exit;
    }
    $error = $result['message'] ?? $result['error'] ?? 'Pricing settings could not be saved.';
}

$profile = apiRequest('GET', '/estimate-pricing-profile');
if (!is_array($profile) || !empty($profile['error'])) {
    die('Estimate pricing settings are temporarily unavailable.');
}

function epsValue($profile, $key)
{
    return htmlspecialchars((string)($profile[$key] ?? 0));
}

include 'header.php';
?>

<style>
    .eps-shell{max-width:1050px;margin:0 auto 55px}.eps-hero{background:linear-gradient(135deg,#101820,#1b4438);border-radius:16px;color:#fff;padding:28px 32px;margin-bottom:22px}.eps-kicker{color:#5de0a7;text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:800}.eps-hero h1{margin:5px 0}.eps-hero p{margin:0;color:#d0dfda}.eps-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.eps-card{background:#fff;border:1px solid #dce4e0;border-radius:12px;padding:22px;box-shadow:0 2px 7px rgba(0,0,0,.05)}.eps-card h2{margin-top:0}.eps-field{margin-bottom:16px}.eps-field label{display:block;font-weight:800;margin-bottom:5px}.eps-field small{display:block;color:#64716c;margin-top:5px}.eps-formula{background:#edf7f2;border-left:4px solid #1d9a67;padding:16px;border-radius:8px;line-height:1.55}.eps-alert{background:#fff7df;border:1px solid #ead49a;border-radius:8px;padding:14px;margin-bottom:18px}.eps-error{background:#fdebea;border-color:#efb4b1;color:#842b26}.eps-save{background:#16835a!important;color:#fff!important;width:auto!important;padding:12px 20px!important}@media(max-width:760px){.eps-grid{grid-template-columns:1fr}.eps-hero{padding:24px 20px}}
</style>

<div class="eps-shell">
    <header class="eps-hero">
        <span class="eps-kicker"><?= $role === 'admin' ? 'TrustFix global defaults' : 'Contractor company profile' ?></span>
        <h1>Estimate Pricing Settings</h1>
        <p>Convert labor time and verified material prices into a sustainable customer price.</p>
    </header>

    <?php if ($error !== '') { ?><div class="eps-alert eps-error"><?= htmlspecialchars($error) ?></div><?php } ?>
    <?php if (empty($profile['configured'])) { ?>
        <div class="eps-alert"><strong>Starter values are active.</strong> Review every field below and save real business assumptions before using estimate ranges with customers.</div>
    <?php } ?>

    <form method="POST">
        <div class="eps-grid">
            <section class="eps-card">
                <h2>Labor and direct costs</h2>
                <div class="eps-field">
                    <label for="name">Profile name</label>
                    <input id="name" name="name" value="<?= htmlspecialchars($profile['name'] ?? 'TrustFix pricing') ?>" required>
                </div>
                <div class="eps-field">
                    <label for="hourly_wage">Worker hourly wage ($)</label>
                    <input id="hourly_wage" type="number" min="0" max="10000" step="0.01" name="hourly_wage" value="<?= epsValue($profile, 'hourly_wage') ?>" required>
                    <small>The amount paid to the worker—not the customer billing rate.</small>
                </div>
                <div class="eps-field">
                    <label for="payroll_burden_percent">Payroll burden (%)</label>
                    <input id="payroll_burden_percent" type="number" min="0" max="500" step="0.01" name="payroll_burden_percent" value="<?= epsValue($profile, 'payroll_burden_percent') ?>" required>
                    <small>Employer taxes, benefits, workers' compensation, and other wage-linked costs.</small>
                </div>
                <div class="eps-field">
                    <label for="insurance_percent">Insurance allocation (%)</label>
                    <input id="insurance_percent" type="number" min="0" max="500" step="0.01" name="insurance_percent" value="<?= epsValue($profile, 'insurance_percent') ?>" required>
                    <small>Applied to burdened labor plus marked-up materials.</small>
                </div>
                <div class="eps-field">
                    <label for="tools_percent">Tools and equipment (%)</label>
                    <input id="tools_percent" type="number" min="0" max="500" step="0.01" name="tools_percent" value="<?= epsValue($profile, 'tools_percent') ?>" required>
                    <small>Applied to burdened labor for wear, replacement, rental, and consumables.</small>
                </div>
                <div class="eps-field">
                    <label for="travel_flat_fee">Travel / mobilization per job ($)</label>
                    <input id="travel_flat_fee" type="number" min="0" max="999999.99" step="0.01" name="travel_flat_fee" value="<?= epsValue($profile, 'travel_flat_fee') ?>" required>
                </div>
            </section>

            <section class="eps-card">
                <h2>Materials, overhead, and profit</h2>
                <div class="eps-field">
                    <label for="material_markup_percent">Material markup (%)</label>
                    <input id="material_markup_percent" type="number" min="0" max="500" step="0.01" name="material_markup_percent" value="<?= epsValue($profile, 'material_markup_percent') ?>" required>
                    <small>Covers sourcing, delivery coordination, waste, returns, and price movement.</small>
                </div>
                <div class="eps-field">
                    <label for="overhead_percent">Business overhead (%)</label>
                    <input id="overhead_percent" type="number" min="0" max="500" step="0.01" name="overhead_percent" value="<?= epsValue($profile, 'overhead_percent') ?>" required>
                    <small>Applied to direct job costs, including travel, insurance, and tools.</small>
                </div>
                <div class="eps-field">
                    <label for="profit_percent">Profit markup (%)</label>
                    <input id="profit_percent" type="number" min="0" max="500" step="0.01" name="profit_percent" value="<?= epsValue($profile, 'profit_percent') ?>" required>
                    <small>Applied after direct costs and overhead. This is markup, not gross-margin percentage.</small>
                </div>
                <div class="eps-field">
                    <label for="uncertainty_percent">Range uncertainty allowance (%)</label>
                    <input id="uncertainty_percent" type="number" min="0" max="50" step="0.01" name="uncertainty_percent" value="<?= epsValue($profile, 'uncertainty_percent') ?>" required>
                    <small>Widens the preliminary low/high range; it is not added to an accepted quote.</small>
                </div>

                <div class="eps-formula">
                    <strong>Calculation order</strong><br>
                    Wage × hours → payroll burden → materials + markup → travel → insurance → tools → overhead → profit → uncertainty range.
                </div>
            </section>
        </div>

        <div style="margin-top:20px"><button type="submit" class="eps-save">Save Pricing Settings</button></div>
    </form>
</div>

<?php include 'footer.php'; ?>
