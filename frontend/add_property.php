<?php

require 'config.php';
requireLogin();

$message = '';

// Images need a property ID before the final form is submitted, so create a
// genuinely empty draft instead of exposing test data in production.
if (!isset($_SESSION['draft_property_id'])) {
    $draftProperty = apiRequest('POST', '/properties', [
        'street_address' => '',
        'address_line_2' => '',
        'apartment' => '',
        'city' => '',
        'state' => '',
        'zip' => '',
        'county' => '',
        'description' => '',
    ]);

    $draftId = $draftProperty['data']['id'] ?? $draftProperty['id'] ?? null;

    if ($draftId) {
        $_SESSION['draft_property_id'] = (int)$draftId;
    } else {
        $message = '<div class="tf-alert tf-alert-error">'
            . htmlspecialchars(apiMessage($draftProperty, 'Unable to start a property draft.'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

$draftPropertyId = (int)($_SESSION['draft_property_id'] ?? 0);
$propertyId = $draftPropertyId;

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
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

    if (!$propertyId) {
        $message = '<div class="tf-alert tf-alert-error">Unable to save because the property draft was not created.</div>';
    } else {
        $saveResult = apiRequest('PUT', "/properties/$propertyId", $payload);
        $httpCode = (int)($saveResult['_http_code'] ?? 0);

        if ($httpCode >= 200 && $httpCode < 300) {
            unset($_SESSION['draft_property_id']);
            $_SESSION['flash_success'] = 'Property saved successfully.';
            header('Location: list_properties.php');
            exit;
        }

        $message = '<div class="tf-alert tf-alert-error">'
            . htmlspecialchars(apiMessage($saveResult, 'Unable to save the property.'), ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}

include 'header.php';
?>

<h1>Add Property</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="street_address"
        placeholder="Street Address"
        value="<?= htmlspecialchars($_POST['street_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <input
        type="text"
        name="address_line_2"
        placeholder="Address Line 2"
        value="<?= htmlspecialchars($_POST['address_line_2'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    >

    <input
        type="text"
        name="apartment"
        placeholder="Apartment / Unit"
        value="<?= htmlspecialchars($_POST['apartment'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    >

    <input
        type="text"
        name="city"
        placeholder="City"
        value="<?= htmlspecialchars($_POST['city'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <input
        type="text"
        name="state"
        placeholder="State"
        value="<?= htmlspecialchars($_POST['state'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <input
        type="text"
        name="zip"
        placeholder="Zip"
        value="<?= htmlspecialchars($_POST['zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <input
        type="text"
        name="county"
        placeholder="County"
        value="<?= htmlspecialchars($_POST['county'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    >

    <input
        type="text"
        name="description"
        placeholder="Description"
        value="<?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
    >


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

    <div id="uploadedImages"></div>

    <br>

    <button type="submit" <?= $draftPropertyId ? '' : 'disabled' ?>>
        Save Property
    </button>

</form>

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
        if (!input.files.length) {
            return;
        }

        //-----------------------------------------
        // Progress UI
        //-----------------------------------------
        const progressContainer = document.createElement('div');

        progressContainer.style.marginTop = '10px';

        progressContainer.innerHTML = `
            <div
                style="
                    width:300px;
                    height:20px;
                    border:1px solid #999;
                    border-radius:6px;
                    overflow:hidden;
                    background:#eee;
                "
            >
                <div
                    class="progress-fill"
                    style="
                        width:0%;
                        height:100%;
                        background:#4caf50;
                        transition:width 0.2s;
                    "
                ></div>
            </div>

            <div
                class="progress-text"
                style="
                    margin-top:5px;
                    font-size:14px;
                "
            >
                Preparing upload...
            </div>
        `;

        block.appendChild(progressContainer);

        const progressFill =
            progressContainer.querySelector('.progress-fill');

        const progressText =
            progressContainer.querySelector('.progress-text');

        //-----------------------------------------
        // Build form data
        //-----------------------------------------
        const formData = new FormData();

        formData.append('ajax_upload', '1');
        formData.append('image', input.files[0]);

        //-----------------------------------------
        // Disable button
        //-----------------------------------------
        button.disabled = true;
        button.innerText = 'Uploading...';

        //-----------------------------------------
        // AJAX Upload
        //-----------------------------------------
        const xhr = new XMLHttpRequest();

        xhr.open('POST', 'upload_property_image.php?property_id=<?= $draftPropertyId ?>', true);

        xhr.withCredentials = true;
        
        //-----------------------------------------
        // Upload progress
        //-----------------------------------------
        xhr.upload.addEventListener('progress', function(e)
        {
            if (e.lengthComputable) {

                const percent =
                    Math.round((e.loaded / e.total) * 100);

                progressFill.style.width =
                    percent + '%';

                progressText.innerText =
                    'Uploading... ' + percent + '%';
            }
        });

        //-----------------------------------------
        // Upload completed
        //-----------------------------------------
        xhr.onload = function()
        {
            if (xhr.status == 200) {

                let data = {};

                try {
    
                    data = JSON.parse(xhr.responseText);

                } catch(err) {

                    progressText.innerText =
                        'The upload service returned an unexpected response.';

                    return;
                }

                if (data.success) {

                    progressFill.style.width = '100%';

                    progressText.innerText =
                        'Upload Complete';

                    //---------------------------------
                    // Show images
                    //---------------------------------
                    document.getElementById(
                        'uploadedImages'
                    ).innerHTML = data.html;

                    input.value = '';
                    button.disabled = true;
                    button.innerText = 'Upload Image';

                    setTimeout(function()
                    {
                        progressContainer.remove();
                    }, 1000);

                } else {

                    progressText.innerText = data.error || 'Upload failed';
                }

            } else {

                progressText.innerText =
                    'Upload failed. Please try again.';
            }
        };

        //-----------------------------------------
        // Upload error
        //-----------------------------------------
        xhr.onerror = function()
        {
            progressText.innerText =
                'Network error during upload';
        };

        //-----------------------------------------
        // Start upload
        //-----------------------------------------
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
    formData.append('image_id', imageId);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'delete_property_image.php', true);

    xhr.onload = function()
    {
        if (xhr.status == 200) {
            try {
                const res = JSON.parse(xhr.responseText);

                if (res.success) {
                    const wrapper = btn.closest('div');
                    wrapper.remove();
                } else {
                    alert('Delete failed');
                }
            } catch (e) {
                alert('Invalid server response');
            }
        } else {
            alert('Delete failed. Please try again.');
        }
    };

    xhr.send(formData);
}

</script>

<?php include 'footer.php'; ?>
