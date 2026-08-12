<?php

//-------------------------------------------------
// Edit Property
//-------------------------------------------------

require 'config.php';
requireLogin();

//-------------------------------------------------
// Get property ID
//-------------------------------------------------
$propertyId = (int)($_GET['id'] ?? 0);

if (!$propertyId) {
    renderFrontendError(400, 'Missing Property', 'Choose a property before opening the editing page.');
}

//-------------------------------------------------
// Load existing property
//-------------------------------------------------
$property = apiRequest(
    'GET', 
    "/properties/$propertyId"
    );

if (!is_array($property) || empty($property['id'])) {
    renderFrontendError(404, 'Property Not Found', 'This property is unavailable or you do not have access to it.');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
}

//-------------------------------------------------
// Add or remove an authorized property user
//-------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_authorized_user') {

    $authorizedEmail = trim($_POST['authorized_email'] ?? '');

    $authorizedResult = apiRequest(
        'POST',
        "/properties/$propertyId/authorized-users",
        ['email' => $authorizedEmail]
    );

    if (!empty($authorizedResult['success'])) {
        $message .= "<div style='background:#dff0d8;padding:15px;border-radius:8px;margin-bottom:20px;'>Authorized user added successfully.</div>";
    } else {
        $authorizedMessage = $authorizedResult['message'] ?? 'Unable to add authorized user.';
        $message .= "<div style='background:#f8d7da;padding:15px;border-radius:8px;margin-bottom:20px;'>" . htmlspecialchars($authorizedMessage) . "</div>";
    }

    $property = apiRequest('GET', "/properties/$propertyId");
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'remove_authorized_user') {

    $authorizedUserId = (int)($_POST['authorized_user_id'] ?? 0);

    $authorizedResult = apiRequest(
        'DELETE',
        "/properties/$propertyId/authorized-users/$authorizedUserId"
    );

    if (!empty($authorizedResult['success'])) {
        $message .= "<div style='background:#dff0d8;padding:15px;border-radius:8px;margin-bottom:20px;'>Authorized user removed successfully.</div>";
    } else {
        $authorizedMessage = $authorizedResult['message'] ?? 'Unable to remove authorized user.';
        $message .= "<div style='background:#f8d7da;padding:15px;border-radius:8px;margin-bottom:20px;'>" . htmlspecialchars($authorizedMessage) . "</div>";
    }

    $property = apiRequest('GET', "/properties/$propertyId");
}

//=========================================================
// FINAL UPDATE
//=========================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

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

    $updateResult = apiRequest(
        'PUT',
        "/properties/$propertyId",
        $payload
    );
    $httpCode = (int)($updateResult['_http_code'] ?? 0);

    if ($httpCode >= 200 && $httpCode < 300) {
        $property = apiRequest('GET', "/properties/$propertyId");
        $message = '<div class="tf-alert tf-alert-success">Property updated successfully.</div>';
    } else {
        $message = '<div class="tf-alert tf-alert-error">'
            . htmlspecialchars(apiMessage($updateResult, 'Unable to update the property.'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

$pageTitle = 'Edit Property';
include 'header.php';

?>

<h1>Edit Property</h1>

<?= $message ?>

<form method="POST">
    <?= csrfField() ?>

    <label for="property_street_address">Street Address</label>
    <input
        id="property_street_address"
        type="text"
        name="street_address"
        placeholder="Street Address"
        required
        value="<?= htmlspecialchars($property['street_address'] ?? '') ?>"
    >

    <label for="property_address_line_2">Address Line 2</label>
    <input
        id="property_address_line_2"
        type="text"
        name="address_line_2"
        placeholder="Address Line 2"
        value="<?= htmlspecialchars($property['address_line_2'] ?? '') ?>"
    >

    <label for="property_apartment">Apartment / Unit</label>
    <input
        id="property_apartment"
        type="text"
        name="apartment"
        placeholder="Apartment / Unit"
        value="<?= htmlspecialchars($property['apartment'] ?? '') ?>"
    >

    <label for="property_city">City</label>
    <input
        id="property_city"
        type="text"
        name="city"
        required
        value="<?= htmlspecialchars($property['city'] ?? '') ?>"
    >

    <label for="property_state">State</label>
    <input
        id="property_state"
        type="text"
        name="state"
        value="<?= htmlspecialchars($property['state'] ?? '') ?>"
    >

    <label for="property_zip">ZIP Code</label>
    <input
        id="property_zip"
        type="text"
        name="zip"
        value="<?= htmlspecialchars($property['zip'] ?? '') ?>"
    >

    <label for="property_county">County</label>
    <input
        id="property_county"
        type="text"
        name="county"
        value="<?= htmlspecialchars($property['county'] ?? '') ?>"
    >

    <label for="property_description">Property Notes</label>
    <textarea
        id="property_description"
        name="description"
    ><?= htmlspecialchars($property['description'] ?? '') ?></textarea>

    <h3>Upload Pictures</h3>

    <div id="uploadArea">

        <div class="upload-block">

            <input
                type="file"
                class="image-input"
                accept="image/*"
            >

            <button
                type="button"
                class="upload-btn"
                disabled
            >
                Upload Image
            </button>

        </div>

    </div>

    <div id="uploadedImages">
        <?php if (!empty($property['images'])): ?>
            <?php foreach ($property['images'] as $img): ?>
                <div style="
                    position:relative;
                    display:inline-block;
                    margin:0 15px 15px 0;
                ">
                    <img
                        src="<?= htmlspecialchars(storageUrl($img['image_path'])) ?>"
                        alt="Property photo"
                        style="
                            max-width:200px;
                            border:1px solid #ccc;
                            border-radius:8px;
                            display:block;
                        "
                    >

                    <button
                        type="button"
                        onclick="deleteImage(<?= (int)$img['id'] ?>, this)"
                        style="
                            position:absolute;
                            top:6px;
                            right:6px;
                            width:32px;
                            height:32px;
                            background:#e53935;
                            color:white;
                            border:none;
                            border-radius:10px;
                            font-size:18px;
                            font-weight:bold;
                            cursor:pointer;
                            line-height:32px;
                            text-align:center;
                        "
                        title="Delete image"
                    >×</button>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <br>

    <button type="submit">
        Update Property
    </button>

</form>

<section style="margin-top:30px;padding:20px;border:1px solid #d9d9d9;border-radius:8px;background:#fff;">
    <h3 style="margin-top:0;">Authorized Property Users</h3>

    <p style="margin-bottom:15px;">
        Add a renter, family member, or other trusted person who may create jobs for this property.
        The person must already have a TrustFix account.
    </p>

    <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_authorized_user">

        <label class="tf-sr-only" for="authorized_email">Authorized user's email address</label>
        <input
            id="authorized_email"
            type="email"
            name="authorized_email"
            placeholder="Authorized user's email address"
            required
            style="flex:1;min-width:260px;margin:0;"
        >

        <button type="submit" style="margin:0;">
            Add Authorized User
        </button>
    </form>

    <?php if (!empty($property['users'])): ?>
        <?php foreach ($property['users'] as $authorizedUser): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;padding:12px 0;border-top:1px solid #eee;">
                <div>
                    <strong><?= htmlspecialchars($authorizedUser['name'] ?? 'TrustFix User') ?></strong><br>
                    <span><?= htmlspecialchars($authorizedUser['email'] ?? '') ?></span>
                </div>

                <form method="POST" onsubmit="return confirm('Remove this user's access to the property?');" style="margin:0;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="remove_authorized_user">
                    <input type="hidden" name="authorized_user_id" value="<?= (int)($authorizedUser['id'] ?? 0) ?>">
                    <button type="submit" style="margin:0;background:#b42318;">
                        Remove
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="padding:12px;background:#f7f7f7;border-radius:6px;">
            No additional users currently have access to this property.
        </div>
    <?php endif; ?>
</section>

<script>

function wireUploadBlock(block)
{
    const input = block.querySelector('.image-input');
    const button = block.querySelector('.upload-btn');

    input.addEventListener('change', function()
    {
        button.disabled = !input.files.length;
    });

    button.addEventListener('click', function()
    {
        if (!input.files.length) return;

        const progressContainer = document.createElement('div');
        progressContainer.style.marginTop = '10px';

        progressContainer.innerHTML = `
            <div style="width:300px;height:20px;border:1px solid #999;border-radius:6px;overflow:hidden;background:#eee;">
                <div class="progress-fill" style="width:0%;height:100%;background:#4caf50;transition:width 0.2s;"></div>
            </div>
            <div class="progress-text" style="margin-top:5px;font-size:14px;">Preparing upload...</div>
        `;

        block.appendChild(progressContainer);

        const progressFill = progressContainer.querySelector('.progress-fill');
        const progressText = progressContainer.querySelector('.progress-text');

        const formData = new FormData();
        formData.append('csrf_token', <?= json_encode(csrfToken()) ?>);
        formData.append('ajax_upload', '1');
        formData.append('image', input.files[0]);

        button.disabled = true;
        button.innerText = 'Uploading...';

        const xhr = new XMLHttpRequest();

        xhr.open('POST', 'upload_property_image.php?property_id=<?= $propertyId ?>', true);
        xhr.withCredentials = true;

        xhr.upload.addEventListener('progress', function(e)
        {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = percent + '%';
                progressText.innerText = 'Uploading... ' + percent + '%';
            }
        });

        xhr.onload = function()
        {
            if (xhr.status == 200) {

                let data = {};

                try {
                    data = JSON.parse(xhr.responseText);
                } catch(err) {
                    progressText.innerText = 'The upload service returned an unexpected response.';
                    return;
                }

                if (data.success) {

                    progressFill.style.width = '100%';
                    progressText.innerText = 'Upload Complete';

                    document.getElementById('uploadedImages').innerHTML = data.html;

                    input.value = '';
                    button.disabled = true;
                    button.innerText = 'Upload Image';

                    setTimeout(function()
                    {
                        progressContainer.remove();
                    }, 1000);

                } else {
                    progressText.innerText = data.error || 'Upload failed. Please try again.';
                }

            } else {
                progressText.innerText = 'Upload failed. Please try again.';
            }
        };

        xhr.onerror = function()
        {
            progressText.innerText = 'Network error during upload';
        };

        xhr.send(formData);
    });
}

document.querySelectorAll('.upload-block')
    .forEach(wireUploadBlock);

function deleteImage(imageId, btn)
{
    if (!confirm('Delete this image?')) {
        return;
    }

    const formData = new FormData();
    formData.append('csrf_token', <?= json_encode(csrfToken()) ?>);
    formData.append('image_id', imageId);

    const xhr = new XMLHttpRequest();

    xhr.open('POST', 'delete_property_image.php', true);

    xhr.onload = function()
    {
        if (xhr.status == 200) {

            try {
                const res = JSON.parse(xhr.responseText);

                if (res.success) {

                    // remove image from UI
                    const wrapper = btn.closest('div');
                    wrapper.remove();

                } else {
                    alert('Delete failed');
                }

            } catch (e) {
                alert('Invalid server response');
            }

        } else {
            alert('HTTP error: ' + xhr.status);
        }
    };

    xhr.send(formData);
}

</script>

<?php include 'footer.php'; ?>
