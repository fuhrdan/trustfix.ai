<?php

require 'config.php';
requireLogin();

$user = apiRequest('GET', '/me');
if (($user['role'] ?? '') !== 'admin') {
    die('Administrator access is required.');
}

$error = '';
$search = trim((string)($_GET['search'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        $result = apiRequest('DELETE', '/admin/material-prices/' . $id);
        $successMessage = 'Material price removed.';
    } else {
        $payload = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'category' => trim((string)($_POST['category'] ?? '')) ?: null,
            'zip_code' => trim((string)($_POST['zip_code'] ?? '')) ?: null,
            'unit' => trim((string)($_POST['unit'] ?? 'each')) ?: 'each',
            'unit_price' => (float)($_POST['unit_price'] ?? 0),
            'low_unit_price' => trim((string)($_POST['low_unit_price'] ?? '')) === '' ? null : (float)$_POST['low_unit_price'],
            'high_unit_price' => trim((string)($_POST['high_unit_price'] ?? '')) === '' ? null : (float)$_POST['high_unit_price'],
            'source_name' => trim((string)($_POST['source_name'] ?? '')) ?: null,
            'source_url' => trim((string)($_POST['source_url'] ?? '')) ?: null,
            'observed_at' => trim((string)($_POST['observed_at'] ?? '')) ?: null,
            'active' => !empty($_POST['active']),
        ];
        $result = apiRequest($id ? 'PUT' : 'POST', $id ? '/admin/material-prices/' . $id : '/admin/material-prices', $payload);
        $successMessage = $id ? 'Material price updated.' : 'Material price added.';
    }

    $httpCode = (int)($result['_http_code'] ?? 200);
    if (is_array($result) && $httpCode >= 200 && $httpCode < 300 && empty($result['error'])) {
        $_SESSION['flash_success'] = $successMessage;
        header('Location: manage_material_prices.php');
        exit;
    }
    $error = $result['message'] ?? $result['error'] ?? 'The material catalog change could not be saved.';
}

$query = http_build_query(['search' => $search, 'per_page' => 100]);
$response = apiRequest('GET', '/admin/material-prices?' . $query);
$prices = $response['data'] ?? [];
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
foreach ($prices as $price) {
    if ((int)($price['id'] ?? 0) === $editId) {
        $editing = $price;
        break;
    }
}

function mpMoney($value)
{
    return '$' . number_format((float)$value, 2);
}

include 'header.php';
?>

<style>
    .mp-shell{max-width:1220px;margin:0 auto 55px}.mp-hero{background:linear-gradient(135deg,#101820,#23445a);border-radius:16px;color:#fff;padding:28px 32px;margin-bottom:22px}.mp-kicker{color:#62d6ff;text-transform:uppercase;letter-spacing:.12em;font-size:12px;font-weight:800}.mp-hero h1{margin:5px 0}.mp-hero p{margin:0;color:#d2e0e8}.mp-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:22px;align-items:start}.mp-card{background:#fff;border:1px solid #dbe2e6;border-radius:12px;padding:21px;box-shadow:0 2px 7px rgba(0,0,0,.05)}.mp-card h2{margin-top:0}.mp-field{margin-bottom:13px}.mp-field label{display:block;font-weight:800;margin-bottom:5px}.mp-pair{display:grid;grid-template-columns:1fr 1fr;gap:10px}.mp-table{width:100%;border-collapse:collapse}.mp-table th,.mp-table td{text-align:left;border-bottom:1px solid #e4e9ec;padding:11px 8px;vertical-align:top}.mp-table th{font-size:12px;text-transform:uppercase;color:#62717b}.mp-table .num{text-align:right;white-space:nowrap}.mp-source{font-size:12px;color:#687781}.mp-active{color:#16835a;font-weight:800}.mp-inactive{color:#9a4e46}.mp-actions{display:flex;gap:8px;flex-wrap:wrap}.mp-actions a,.mp-actions button{width:auto;margin:0;padding:7px 10px}.mp-save{background:#16835a!important;color:#fff!important}.mp-delete{background:#b53b34!important;color:#fff!important}.mp-alert{background:#fdebea;border:1px solid #efb4b1;color:#842b26;border-radius:8px;padding:14px;margin-bottom:18px}.mp-check{display:flex!important;gap:8px;align-items:center}.mp-check input{width:auto;margin:0}@media(max-width:880px){.mp-grid{grid-template-columns:1fr}.mp-hero{padding:24px 20px}}@media(max-width:600px){.mp-table{display:block;overflow-x:auto}.mp-pair{grid-template-columns:1fr}}
</style>

<div class="mp-shell">
    <header class="mp-hero">
        <span class="mp-kicker">TrustFix-controlled pricing</span>
        <h1>Material Price Catalog</h1>
        <p>Store observed unit prices by source and ZIP so the AI never invents material costs.</p>
    </header>

    <?php if ($error !== '') { ?><div class="mp-alert"><?= htmlspecialchars($error) ?></div><?php } ?>

    <div class="mp-grid">
        <aside class="mp-card">
            <h2><?= $editing ? 'Edit material price' : 'Add material price' ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="mp-field"><label>Material name</label><input name="name" value="<?= htmlspecialchars($editing['name'] ?? '') ?>" placeholder="Example: 1/2 inch drywall panel" required></div>
                <div class="mp-pair">
                    <div class="mp-field"><label>Category</label><input name="category" value="<?= htmlspecialchars($editing['category'] ?? '') ?>" placeholder="drywall"></div>
                    <div class="mp-field"><label>ZIP (blank = global)</label><input name="zip_code" maxlength="10" value="<?= htmlspecialchars($editing['zip_code'] ?? '') ?>" placeholder="80202"></div>
                </div>
                <div class="mp-pair">
                    <div class="mp-field"><label>Unit</label><input name="unit" value="<?= htmlspecialchars($editing['unit'] ?? 'each') ?>" required></div>
                    <div class="mp-field"><label>Observed unit price</label><input type="number" min="0" max="999999.99" step="0.01" name="unit_price" value="<?= htmlspecialchars($editing['unit_price'] ?? '') ?>" required></div>
                </div>
                <div class="mp-pair">
                    <div class="mp-field"><label>Low unit price</label><input type="number" min="0" max="999999.99" step="0.01" name="low_unit_price" value="<?= htmlspecialchars($editing['low_unit_price'] ?? '') ?>"></div>
                    <div class="mp-field"><label>High unit price</label><input type="number" min="0" max="999999.99" step="0.01" name="high_unit_price" value="<?= htmlspecialchars($editing['high_unit_price'] ?? '') ?>"></div>
                </div>
                <div class="mp-field"><label>Source / store</label><input name="source_name" value="<?= htmlspecialchars($editing['source_name'] ?? '') ?>" placeholder="Store or supplier"></div>
                <div class="mp-field"><label>Source URL</label><input type="url" name="source_url" value="<?= htmlspecialchars($editing['source_url'] ?? '') ?>" placeholder="https://..."></div>
                <div class="mp-field"><label>Price observed date</label><input type="date" name="observed_at" value="<?= htmlspecialchars(substr((string)($editing['observed_at'] ?? ''), 0, 10)) ?>"></div>
                <div class="mp-field"><label class="mp-check"><input type="checkbox" name="active" value="1" <?= !isset($editing['active']) || !empty($editing['active']) ? 'checked' : '' ?>> Active for new estimates</label></div>
                <button type="submit" class="mp-save"><?= $editing ? 'Update Price' : 'Add to Catalog' ?></button>
                <?php if ($editing) { ?><a href="manage_material_prices.php">Cancel edit</a><?php } ?>
            </form>
        </aside>

        <main class="mp-card">
            <div style="display:flex;justify-content:space-between;gap:15px;align-items:end;flex-wrap:wrap">
                <div><h2 style="margin-bottom:4px">Catalog</h2><span class="mp-source"><?= (int)($response['total'] ?? count($prices)) ?> price records</span></div>
                <form method="GET" style="display:flex;gap:8px;align-items:end"><div><label style="display:block;font-weight:800">Search</label><input name="search" value="<?= htmlspecialchars($search) ?>"></div><button type="submit" style="width:auto">Find</button></form>
            </div>

            <?php if (!$prices) { ?><p style="padding:25px 0">No material prices are stored yet. Add the first verified source on the left.</p><?php } ?>
            <table class="mp-table">
                <thead><tr><th>Material</th><th>Region / source</th><th class="num">Price range</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($prices as $price) { ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($price['name'] ?? '') ?></strong><br><span class="mp-source"><?= htmlspecialchars($price['category'] ?? 'uncategorized') ?> &bull; per <?= htmlspecialchars($price['unit'] ?? 'each') ?></span></td>
                            <td><?= htmlspecialchars($price['zip_code'] ?: 'All ZIP codes') ?><br><span class="mp-source"><?= htmlspecialchars($price['source_name'] ?? 'Source not listed') ?><?= !empty($price['observed_at']) ? ' &bull; ' . htmlspecialchars(substr($price['observed_at'], 0, 10)) : '' ?></span></td>
                            <td class="num"><?= mpMoney($price['low_unit_price'] ?? $price['unit_price'] ?? 0) ?>–<?= mpMoney($price['high_unit_price'] ?? $price['unit_price'] ?? 0) ?></td>
                            <td class="<?= !empty($price['active']) ? 'mp-active' : 'mp-inactive' ?>"><?= !empty($price['active']) ? 'Active' : 'Inactive' ?></td>
                            <td><div class="mp-actions"><a href="manage_material_prices.php?edit=<?= (int)$price['id'] ?>">Edit</a><form method="POST" onsubmit="return confirm('Remove this material price record?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$price['id'] ?>"><button class="mp-delete" type="submit">Delete</button></form></div></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </main>
    </div>
</div>

<?php include 'footer.php'; ?>
