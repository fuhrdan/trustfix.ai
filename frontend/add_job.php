<?php

require 'config.php';

//It is late, I had this open way too long and now the program is telling me
//There are unsaved changes.  Seriously, WTF?  So I'm going to save and close
//When something breaks, blame the other guy, because I am tired.
//Dan

session_start();
requireLogin();

include 'header.php';

$message = '';

//-------------------------------------------------
// Load addresses/properties available to this user
//-------------------------------------------------
$properties = apiRequest('GET', '/properties');

if (!is_array($properties)) {

    $properties = [];
}

function buildPropertyAddress($property)
{
    $parts = [];

    foreach ([
        'street_address',
        'address_line_2',
        'apartment',
        'city',
        'state',
        'zip'
    ] as $field) {

        if (!empty($property[$field])) {

            $parts[] = $property[$field];
        }
    }

    return implode(', ', $parts);
}

//-------------------------------------------------
// Create placeholder job immediately so pictures can
// still be uploaded before the final Save Job click.
//-------------------------------------------------
if (!isset($_SESSION['draft_job_id'])) {

    $draftPayload = [
        'address' => 'Draft Job',
        'lat' => 0,
        'lng' => 0,
        'initial_description' => 'Draft',
        'agreed_price' => 0
    ];

    $draftJob = apiRequest('POST', '/jobs', $draftPayload);

    $_SESSION['draft_job_id'] = $draftJob['id'] ?? null;
}

$jobId = $_SESSION['draft_job_id'];
$draftJobId = $jobId;

//=========================================================
// FINAL SAVE
//=========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

    $selectedPropertyId = $_POST['property_id'] ?? '';
    $selectedAddress = '';

    foreach ($properties as $property) {

        if ((string)($property['id'] ?? '') === (string)$selectedPropertyId) {

            $selectedAddress = buildPropertyAddress($property);
            break;
        }
    }

    if ($selectedAddress === '') {

        $message .= "
            <div style='
                background:#f2dede;
                padding:15px;
                border-radius:8px;
                margin-bottom:20px;
            '>
                Please choose a saved property address before saving the job.
            </div>
        ";
    }
    else {

        $payload = [
            'property_id' => (int)$selectedPropertyId,
            'address' => $selectedAddress,
            'lat' => 0,
            'lng' => 0,
            'initial_description' => $_POST['initial_description'],
            'agreed_price' => (float)($_POST['agreed_price'] ?? 0),
            'onsite_contact_name' => $_POST['onsite_contact_name'] ?? null,
            'onsite_contact_phone' => $_POST['onsite_contact_phone'] ?? null,
            'skills' => $_POST['skills'] ?? []
        ];

        $saveResult = apiRequest(
            'PUT',
            "/jobs/$jobId",
            $payload
        );

        $result = apiRequest(
            'GET',
            "/jobs/$jobId"
        );

        if (!is_array($saveResult) || empty($saveResult['id'])) {

            $message .= "
                <div style='
                    background:#f2dede;
                    padding:15px;
                    border-radius:8px;
                    margin-bottom:20px;
                '>
                    Job save failed. Please check the backend log/API response.
                </div>
            ";

            error_log('Job save failed: ' . print_r($saveResult, true));
        }
        else {

            $message .= "
            <div style='
                background:#dff0d8;
                padding:15px;
                border-radius:8px;
                margin-bottom:20px;
            '>
                Job Saved Successfully
            </div>
        ";

        if (!empty($result['images'])) {

            foreach ($result['images'] as $img) {

                $url = '/storage/' . $img['image_path'];

                $message .= "
                    <div style='margin-bottom:15px;'>

                        <img
                            src='{$url}'
                            style='
                                max-width:200px;
                                border:1px solid #ccc;
                                border-radius:8px;
                            '
                        >

                    </div>
                ";
            }
        }

        error_log(print_r($result, true));

            unset($_SESSION['draft_job_id']);
        }
    }
}
?>

<head>
    <title>Add Job</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="TF-Style.css">
</head>

<body>

<h1>Add Job</h1>

<?= $message ?>

<form method="POST">

    <label for="property_id">
        Job Address
    </label>

    <select
        name="property_id"
        id="property_id"
        required
    >
        <option value="">
            Select a saved property address
        </option>

        <?php foreach ($properties as $property) { ?>

            <?php $addressLabel = buildPropertyAddress($property); ?>

            <?php if ($addressLabel !== '') { ?>

                <option value="<?= htmlspecialchars($property['id']) ?>">
                    <?= htmlspecialchars($addressLabel) ?>
                </option>

            <?php } ?>

        <?php } ?>
    </select>

    <?php if (empty($properties)) { ?>

        <div style="margin:10px 0;color:#a94442;">
            No saved property addresses found.
            <a href="add_property.php">Add a property first</a>.
        </div>

    <?php } ?>

    <textarea
        name="initial_description"
        placeholder="Job Description"
        required
    ></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        placeholder="Price"
    >

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

        <label class="skill-item">
            <input type="checkbox" name="skills[]" value="electrical">
            Electrical
        </label>

        <label class="skill-item">
            <input type="checkbox" name="skills[]" value="plumbing">
            Plumbing
        </label>

        <label class="skill-item">
            <input type="checkbox" name="skills[]" value="drywall">
            Drywall
        </label>

        <label class="skill-item">
            <input type="checkbox" name="skills[]" value="flooring">
            Flooring
        </label>

        <label class="skill-item">
            <input type="checkbox" name="skills[]" value="general">
            General
        </label>

    </div>

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

    <button type="submit">
        Save Job
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

        xhr.open('POST', 'upload_job_image.php?job_id=<?= $draftJobId ?>', true);

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
    
                    console.log(xhr.responseText);

                    data = JSON.parse(xhr.responseText);

                } catch(err) {

                    progressText.innerHTML =
                        '<div style="color:red;">' +
                        'Server returned invalid JSON<br><br>' +
                        '<pre style="white-space:pre-wrap;">' +
                        xhr.responseText +
                        '</pre></div>';

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

                    //---------------------------------
                    // Create next upload block
                    //---------------------------------
                    const newBlock =
                        document.createElement('div');

                    newBlock.className =
                        'upload-block';

                    newBlock.style.marginTop = '20px';

                    newBlock.innerHTML = `
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
                    `;

                    document
                        .getElementById('uploadArea')
                        .appendChild(newBlock);

                    wireUploadBlock(newBlock);

                } else {

                    progressText.innerText =
                        'Upload failed';
                }

            } else {

                progressText.innerText =
                    'HTTP Error: ' + xhr.status;
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

</script>

<?php include 'footer.php'; ?>