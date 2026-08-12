<?php

require 'config.php';
requireLogin();

$message = '';
$properties = apiRequest('GET', '/properties');

if (!is_array($properties) || isset($properties['error']) || isset($properties['message'])) {
    $properties = [];
}

function jobPropertyAddress($property)
{
    $parts = [];

    foreach (['street_address', 'address_line_2'] as $field) {
        if (!empty($property[$field])) {
            $parts[] = $property[$field];
        }
    }

    if (!empty($property['apartment'])) {
        $parts[] = 'Apt/Unit ' . $property['apartment'];
    }

    $cityStateZip = trim(
        trim((string)($property['city'] ?? '')) . ', ' .
        trim((string)($property['state'] ?? '')) . ' ' .
        trim((string)($property['zip'] ?? ''))
    );

    if ($cityStateZip !== ',') {
        $parts[] = $cityStateZip;
    }

    return implode(', ', array_filter($parts));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $selectedPropertyId = (int)($_POST['property_id'] ?? 0);
    $selectedAddress = '';

    foreach ($properties as $property) {
        if ((int)($property['id'] ?? 0) === $selectedPropertyId) {
            $selectedAddress = jobPropertyAddress($property);
            break;
        }
    }

    $description = trim($_POST['initial_description'] ?? '');
    $budgetInput = trim((string)($_POST['agreed_price'] ?? ''));

    if ($selectedPropertyId <= 0 || $selectedAddress === '' || $description === '') {
        $message = '<div class="tf-alert tf-alert-error">Choose a saved property and describe the work.</div>';
    } else {
        $result = apiRequest('POST', '/jobs', [
            'property_id' => $selectedPropertyId,
            'address' => $selectedAddress,
            'lat' => 0,
            'lng' => 0,
            'initial_description' => $description,
            'agreed_price' => $budgetInput === '' ? null : (float)$budgetInput,
            'onsite_contact_name' => trim($_POST['onsite_contact_name'] ?? '') ?: null,
            'onsite_contact_phone' => trim($_POST['onsite_contact_phone'] ?? '') ?: null,
            'skills' => $_POST['skills'] ?? [],
        ]);

        $httpCode = (int)($result['_http_code'] ?? 0);
        $jobId = (int)($result['id'] ?? $result['data']['id'] ?? 0);

        if ($httpCode >= 200 && $httpCode < 300 && $jobId > 0) {
            unset($_SESSION['draft_job_id']);

            if (!empty($_POST['smart_estimate'])) {
                $_SESSION['flash_success'] = 'Job saved. Add any useful photos, then continue to the Smart Estimate.';
                header('Location: edit_job.php?id=' . $jobId . '&next=estimate');
                exit;
            }

            $_SESSION['flash_success'] = 'Job saved. You can add photos below.';
            header('Location: edit_job.php?id=' . $jobId);
            exit;
        }

        $message = '<div class="tf-alert tf-alert-error">'
            . htmlspecialchars(apiMessage($result, 'Unable to save the job.'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

$pageTitle = 'Post a Job';
include 'header.php';
?>

<h1>Post a Job</h1>
<p class="tf-page-intro">Start with the property and a clear description. After saving, you can add photos without creating an abandoned draft record.</p>

<?= $message ?>

<?php if (empty($properties)): ?>
    <section class="tf-empty-state">
        <div class="tf-empty-state-icon" aria-hidden="true">+</div>
        <h2>Add a property first</h2>
        <p>Every job needs a saved address so estimates, messages, and repair history stay connected.</p>
        <a class="tf-button" href="add_property.php">Add Property</a>
    </section>
<?php else: ?>
    <form method="POST" class="tf-card">
        <?= csrfField() ?>

        <label for="property_id">Job Address</label>
        <select name="property_id" id="property_id" required>
            <option value="">Select an address</option>
            <?php foreach ($properties as $property): ?>
                <?php $address = jobPropertyAddress($property); ?>
                <option value="<?= (int)($property['id'] ?? 0) ?>" <?= (int)($_POST['property_id'] ?? 0) === (int)($property['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="initial_description">Job Description</label>
        <textarea id="initial_description" name="initial_description" required><?= htmlspecialchars($_POST['initial_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="agreed_price">Optional Homeowner Budget</label>
        <input id="agreed_price" type="number" min="0" step="0.01" name="agreed_price" value="<?= htmlspecialchars($_POST['agreed_price'] ?? '', ENT_QUOTES, 'UTF-8') ?>" aria-describedby="budget_help">
        <small id="budget_help">This is a planning number and is not used to calculate the TrustFix estimate.</small>

        <label class="tf-card" style="display:flex;gap:10px;align-items:flex-start;margin:18px 0;">
            <input type="checkbox" name="smart_estimate" value="1" <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['smart_estimate']) ? 'checked' : '' ?> style="margin-top:4px;">
            <span>
                <strong>Create a TrustFix Smart Estimate</strong><br>
                <small>TrustFix will ask follow-up questions, outline the work, and calculate a preliminary range.</small>
            </span>
        </label>

        <label for="onsite_contact_name">On-site Contact Name</label>
        <input id="onsite_contact_name" type="text" name="onsite_contact_name" value="<?= htmlspecialchars($_POST['onsite_contact_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="onsite_contact_phone">On-site Contact Phone</label>
        <input id="onsite_contact_phone" type="tel" name="onsite_contact_phone" value="<?= htmlspecialchars($_POST['onsite_contact_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <fieldset style="border:0;padding:0;margin:20px 0;">
            <legend><strong>Required Skills</strong></legend>
            <div class="skills-group">
                <?php foreach (['electrical', 'plumbing', 'drywall', 'flooring', 'general'] as $skill): ?>
                    <label class="skill-item">
                        <input type="checkbox" name="skills[]" value="<?= $skill ?>" <?= in_array($skill, $_POST['skills'] ?? [], true) ? 'checked' : '' ?>>
                        <?= ucfirst($skill) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="tf-alert" style="background:#eaf4ff;">
            Save the job first, then add photos from the job editing page.
        </div>

        <div class="tf-actions">
            <button type="submit">Save Job</button>
            <a class="tf-button tf-button-secondary" href="my_jobs.php">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php include 'footer.php'; ?>
