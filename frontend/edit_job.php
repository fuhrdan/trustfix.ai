<?php

require 'config.php';
requireLogin();

include 'header.php';

session_start();

//-------------------------------------------------
// Get job ID
//-------------------------------------------------
$jobId = (int)($_GET['id'] ?? 0);

if (!$jobId) {
    die("Missing job ID");
}

//-------------------------------------------------
// Load existing job
//-------------------------------------------------
$job = apiRequest('GET', "/jobs/$jobId");

if (!is_array($job)) {
    die("Job not found or invalid response");
}

$message = '';

//=========================================================
// FINAL UPDATE
//=========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

    $payload = [
        'address' => $_POST['address'] ?? '',
        'lat' => (float)($_POST['lat'] ?? 0),
        'lng' => (float)($_POST['lng'] ?? 0),
        'initial_description' => $_POST['initial_description'] ?? '',
        'agreed_price' => (float)($_POST['agreed_price'] ?? 0),
        'onsite_contact_name' => $_POST['onsite_contact_name'] ?? '',
        'onsite_contact_phone' => $_POST['onsite_contact_phone'] ?? '',
        'skills' => $_POST['skills'] ?? []
    ];

    apiRequest(
        'PUT',
        "/jobs/$jobId",
        $payload
    );

    $result = apiRequest('GET', "/jobs/$jobId");

    $message .= "
        <div style='
            background:#dff0d8;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        '>
            Job Updated Successfully
        </div>
    ";

    if (!empty($result['images'])) {
        foreach ($result['images'] as $img) {

            $url = storageUrl($img['image_path']);

            $message .= "
                <div style='margin-bottom:15px;'>
                    <img src='{$url}'
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
}

?>

<head>
    <title>Edit Job</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="TF-Style.css">
</head>

<body>

<h1>Edit Job</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="address"
        placeholder="Address"
        required
        value="<?= htmlspecialchars($job['address'] ?? '') ?>"
    >

    <textarea
        name="initial_description"
        placeholder="Job Description"
        required
    ><?= htmlspecialchars($job['initial_description'] ?? '') ?></textarea>

    <input
        type="number"
        step="0.01"
        name="agreed_price"
        placeholder="Price"
        value="<?= htmlspecialchars($job['agreed_price'] ?? 0) ?>"
    >

    <input
        type="text"
        name="onsite_contact_name"
        placeholder="On-site Contact Name"
        value="<?= htmlspecialchars($job['onsite_contact_name'] ?? '') ?>"
    >

    <input
        type="text"
        name="onsite_contact_phone"
        placeholder="On-site Contact Phone"
        value="<?= htmlspecialchars($job['onsite_contact_phone'] ?? '') ?>"
    >

    <h3>Required Skills</h3>

    <?php
        $existingSkills = $job['skills'] ?? [];
        if (!is_array($existingSkills)) {
            $existingSkills = [];
        }
    ?>

    <div class="skills-group">

        <?php
        $skillsList = [
            'electrical',
            'plumbing',
            'drywall',
            'flooring',
            'general'
        ];

        foreach ($skillsList as $skill):
        ?>
            <label class="skill-item">
                <input
                    type="checkbox"
                    name="skills[]"
                    value="<?= $skill ?>"
                    <?= in_array($skill, $existingSkills) ? 'checked' : '' ?>
                >
                <?= ucfirst($skill) ?>
            </label>
        <?php endforeach; ?>

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

    <div id="uploadedImages">
        <?php if (!empty($job['images'])): ?>
            <?php foreach ($job['images'] as $img): ?>
<div style="
    position:relative;
    display:inline-block;
    margin-bottom:15px;
">

    <img
        src="<?= htmlspecialchars(storageUrl($img['image_path'])) ?>"
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
    >
        ×
    </button>

</div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <br>

    <button type="submit">
        Update Job
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
        formData.append('ajax_upload', '1');
        formData.append('image', input.files[0]);

        button.disabled = true;
        button.innerText = 'Uploading...';

        const xhr = new XMLHttpRequest();

        xhr.open('POST', 'upload_job_image.php?job_id=<?= $jobId ?>', true);
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
                    progressText.innerHTML =
                        '<div style="color:red;">Invalid JSON<br><pre>' +
                        xhr.responseText +
                        '</pre></div>';
                    return;
                }

                if (data.success) {

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
                    progressText.innerText = 'Upload failed';
                }

            } else {
                progressText.innerText = 'HTTP Error: ' + xhr.status;
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
    formData.append('image_id', imageId);

    const xhr = new XMLHttpRequest();

    xhr.open('POST', 'delete_job_image.php', true);

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
