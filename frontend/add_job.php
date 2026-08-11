<?php

require 'config.php';
requireLogin();

$message = '';
$properties = apiRequest('GET', '/properties');

if (!is_array($properties)) {
    $properties = [];
}

function jobPropertyAddress($property)
{
    $parts = [];

    if (!empty($property['street_address'])) {
        $parts[] = $property['street_address'];
    }

    if (!empty($property['address_line_2'])) {
        $parts[] = $property['address_line_2'];
    }

    if (!empty($property['apartment'])) {
        $parts[] = 'Apt/Unit ' . $property['apartment'];
    }

    $cityStateZip = trim(
        ($property['city'] ?? '') . ', ' .
        ($property['state'] ?? '') . ' ' .
        ($property['zip'] ?? '')
    );

    if ($cityStateZip !== ',') {
        $parts[] = $cityStateZip;
    }

    return implode(', ', array_filter($parts));
}

//-------------------------------------------------
// Draft jobs are now created only when the user uploads
// an image. Creating a draft on page-load failed because
// no selected property/address had been sent yet.
//-------------------------------------------------
$draftJobId = $_SESSION['draft_job_id'] ?? 0;

//-------------------------------------------------
// FINAL SAVE
//-------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedPropertyId = (int)($_POST['property_id'] ?? 0);
    $selectedAddress = '';

    foreach ($properties as $property) {
        if ((int)($property['id'] ?? 0) === $selectedPropertyId) {
            $selectedAddress = jobPropertyAddress($property);
            break;
        }
    }

    $budgetInput = trim((string)($_POST['agreed_price'] ?? ''));

    $payload = [
        'property_id' => $selectedPropertyId ?: null,
        'address' => $selectedAddress ?: 'Draft Job',
        'lat' => 0,
        'lng' => 0,
        'initial_description' => $_POST['initial_description'] ?? '',
        'agreed_price' => ($budgetInput === '' ? null : (float)$budgetInput),
        'onsite_contact_name' => $_POST['onsite_contact_name'] ?? null,
        'onsite_contact_phone' => $_POST['onsite_contact_phone'] ?? null,
        'skills' => $_POST['skills'] ?? []
    ];

    if (!empty($draftJobId)) {
        $saveResult = apiRequest(
            'PUT',
            "/jobs/$draftJobId",
            $payload
        );
    } else {
        $saveResult = apiRequest(
            'POST',
            '/jobs',
            $payload
        );
    }

    if (is_array($saveResult) && isset($saveResult['id'])) {

        if (!empty($_POST['smart_estimate'])) {
            unset($_SESSION['draft_job_id']);
            $_SESSION['flash_success'] = 'Job saved. TrustFix is ready to build the preliminary scope and estimate.';
            header('Location: estimate_job.php?id=' . (int)$saveResult['id'] . '&auto=1');
            exit;
        }

        $message .= "
            <div style='background:#dff0d8;padding:15px;border-radius:8px;margin-bottom:20px;'>
                Job Saved Successfully
            </div>
        ";

        if (!empty($saveResult['images'])) {
            foreach ($saveResult['images'] as $img) {
                $imagePath = $img['image_path'] ?? '';
                $url = storageUrl($imagePath);

                $message .= "
                    <div style='margin-bottom:15px;'>
                        <img src='" . htmlspecialchars($url) . "'
                             style='max-width:200px;border:1px solid #ccc;border-radius:8px;'>
                    </div>
                ";
            }
        }

        unset($_SESSION['draft_job_id']);
        $draftJobId = 0;

    } else {
        $safeMessage = htmlspecialchars(
            apiMessage($saveResult, 'Unable to save the job. Please try again.'),
            ENT_QUOTES,
            'UTF-8'
        );
        $message .= "<div class='tf-alert tf-alert-error'>{$safeMessage}</div>";
    }
}

include 'header.php';
?>

<style>
    .job-property-select
    {
        width: 100%;
        margin-bottom: 15px;
        padding: 10px;
        min-height: 42px;
        box-sizing: border-box;
        border: 1px solid var(--tf-border);
        border-radius: 4px;
        background: #ffffff;
        color: var(--tf-text);
        font: inherit;
    }

    .job-property-label
    {
        display: block;
        margin-bottom: 7px;
        font-weight: 700;
    }
</style>

<h1>Add Job</h1>

<?= $message ?>

<?php if (empty($properties)) { ?>

    <div style="background:#fff3cd;padding:15px;border-radius:8px;margin-bottom:20px;">
        No saved addresses were found. Please add a property/address before adding a job.
    </div>

<?php } ?>

<form method="POST">

    <label for="property_id" class="job-property-label">Job Address</label>
    <select name="property_id" id="property_id" class="job-property-select" required>
        <?php if (!empty($properties)) { ?>
            <option value="">Select an address</option>
            <?php foreach ($properties as $property) { ?>
                <?php $address = jobPropertyAddress($property); ?>
                <option value="<?= htmlspecialchars($property['id']) ?>">
                    <?= htmlspecialchars($address) ?>
                </option>
            <?php } ?>
            <option value="add_property">+ Add Property</option>
        <?php } else { ?>
            <option value="add_property">+ Add Property</option>
        <?php } ?>
    </select>

    <textarea
        name="initial_description"
        placeholder="Job Description"
        required
    ></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        placeholder="Optional homeowner budget (not used in calculation)"
    >

    <label style="display:flex;gap:10px;align-items:flex-start;background:#eef8f3;border:1px solid #b9dfcc;border-radius:8px;padding:14px;margin:15px 0;">
        <input type="checkbox" name="smart_estimate" value="1" checked style="width:auto;margin-top:3px;">
        <span>
            <strong>Create a TrustFix Smart Estimate</strong><br>
            <small>TrustFix will ask useful follow-up questions, outline the work, estimate labor time, identify materials, and calculate a preliminary range from configured pricing rules.</small>
        </span>
    </label>

    <input
        type="text"
        name="onsite_contact_name"
        placeholder="On-site Contact Name"
    >

    <input
        type="text"
        name="onsite_contact_phone"
        placeholder="On-site Contact Phone"
    >

    <h3>Required Skills</h3>

    <div class="skills-group">
        <label class="skill-item"><input type="checkbox" name="skills[]" value="electrical"> Electrical</label>
        <label class="skill-item"><input type="checkbox" name="skills[]" value="plumbing"> Plumbing</label>
        <label class="skill-item"><input type="checkbox" name="skills[]" value="drywall"> Drywall</label>
        <label class="skill-item"><input type="checkbox" name="skills[]" value="flooring"> Flooring</label>
        <label class="skill-item"><input type="checkbox" name="skills[]" value="general"> General</label>
    </div>

    <h3>Upload Pictures</h3>

    <div id="uploadArea">
        <div class="upload-block">
            <input type="file" class="image-input" accept="image/*">
            <button type="button" class="upload-btn" disabled>Upload Image</button>
        </div>
    </div>

    <div id="uploadedImages"></div>

    <br>

    <button type="submit" <?= empty($properties) ? 'disabled' : '' ?>>
        Save Job
    </button>

</form>

<script>
const propertySelect = document.getElementById('property_id');

propertySelect.addEventListener('change', function()
{
    if (this.value === 'add_property') {
        window.location.href = 'add_property.php';
    }
});

document.querySelector('form').addEventListener('submit', function(event)
{
    if (propertySelect.value === 'add_property') {
        event.preventDefault();
        window.location.href = 'add_property.php';
    }
});

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

        const propertySelect = document.getElementById('property_id');

        if (!propertySelect.value) {
            alert('Please select a job address before uploading pictures.');
            return;
        }

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

        formData.append('ajax_upload', '1');
        formData.append('image', input.files[0]);
        formData.append('property_id', document.getElementById('property_id').value);

        button.disabled = true;
        button.innerText = 'Uploading...';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload_job_image.php', true);
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
            let data = {};

            try {
                data = JSON.parse(xhr.responseText);
            } catch(err) {
                progressText.innerText = 'The upload service returned an unexpected response.';
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data.success) {
                progressFill.style.width = '100%';
                progressText.innerText = 'Upload Complete';
                document.getElementById('uploadedImages').innerHTML = data.html;

                const newBlock = document.createElement('div');
                newBlock.className = 'upload-block';
                newBlock.style.marginTop = '20px';
                newBlock.innerHTML = `
                    <input type="file" class="image-input" accept="image/*">
                    <button type="button" class="upload-btn" disabled>Upload Image</button>
                `;

                document.getElementById('uploadArea').appendChild(newBlock);
                wireUploadBlock(newBlock);
            } else {
                progressText.innerText = data.error || 'Upload failed. Please try again.';
            }
        };

        xhr.onerror = function()
        {
            progressText.innerText = 'Network error during upload';
        };

        xhr.send(formData);
    });
}

document.querySelectorAll('.upload-block').forEach(wireUploadBlock);
</script>

<?php include 'footer.php'; ?>
