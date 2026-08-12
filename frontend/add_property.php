<?php

require 'config.php';
requireLogin();

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $payload = [
        'street_address' => trim($_POST['street_address'] ?? ''),
        'address_line_2' => trim($_POST['address_line_2'] ?? ''),
        'apartment' => trim($_POST['apartment'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip' => trim($_POST['zip'] ?? ''),
        'county' => trim($_POST['county'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
    ];

    if ($payload['street_address'] === '' || $payload['city'] === '' || $payload['state'] === '' || $payload['zip'] === '') {
        $message = '<div class="tf-alert tf-alert-error">Street address, city, state, and ZIP are required.</div>';
    } else {
        $result = apiRequest('POST', '/properties', $payload);
        $httpCode = (int)($result['_http_code'] ?? 0);
        $propertyId = (int)($result['data']['id'] ?? $result['id'] ?? 0);

        if ($httpCode >= 200 && $httpCode < 300 && $propertyId > 0) {
            $_SESSION['flash_success'] = 'Property saved. You can add photos or authorized users below.';
            header('Location: edit_property.php?id=' . $propertyId);
            exit;
        }

        $message = '<div class="tf-alert tf-alert-error">'
            . htmlspecialchars(apiMessage($result, 'Unable to save the property.'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

$pageTitle = 'Add Property';
include 'header.php';
?>

<h1>Add Property</h1>
<p class="tf-page-intro">Save the address first. After it is created, TrustFix will take you to the property page where you can add photos and authorized users.</p>

<?= $message ?>

<form method="POST" class="tf-card">
    <?= csrfField() ?>

    <label for="street_address">Street Address</label>
    <input id="street_address" type="text" name="street_address" autocomplete="street-address" value="<?= htmlspecialchars($_POST['street_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="address_line_2">Address Line 2</label>
    <input id="address_line_2" type="text" name="address_line_2" value="<?= htmlspecialchars($_POST['address_line_2'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label for="apartment">Apartment / Unit</label>
    <input id="apartment" type="text" name="apartment" value="<?= htmlspecialchars($_POST['apartment'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label for="city">City</label>
    <input id="city" type="text" name="city" autocomplete="address-level2" value="<?= htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="state">State</label>
    <input id="state" type="text" name="state" autocomplete="address-level1" value="<?= htmlspecialchars($_POST['state'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="zip">ZIP Code</label>
    <input id="zip" type="text" name="zip" autocomplete="postal-code" value="<?= htmlspecialchars($_POST['zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>

    <label for="county">County</label>
    <input id="county" type="text" name="county" value="<?= htmlspecialchars($_POST['county'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <label for="description">Property Notes</label>
    <textarea id="description" name="description"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

    <div class="tf-actions">
        <button type="submit">Save Property</button>
        <a class="tf-button tf-button-secondary" href="list_properties.php">Cancel</a>
    </div>
</form>

<?php include 'footer.php'; ?>
