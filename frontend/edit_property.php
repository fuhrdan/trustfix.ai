<?php

//-------------------------------------------------
// Edit Property
//-------------------------------------------------

require 'config.php';
requireLogin();

include 'header.php';

session_start();

//-------------------------------------------------
// Get property ID
//-------------------------------------------------
$propertyId = (int)($_GET['id'] ?? 0);

if (!$propertyId) {
    die("Missing property ID");
}

//-------------------------------------------------
// Load existing property
//-------------------------------------------------
$property = apiRequest(
    'GET', 
    "/properties/$propertyId"
    );

if (!is_array($property)) {
    die("Property not found or invalid response");
}

$message = '';

//=========================================================
// FINAL UPDATE
//=========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

    $payload = [
        'street_address' => $_POST['street_address'] ?? '',
        'address_line_2' => $_POST['address_line_2'] ?? '',
        'apartment' => $_POST['apartment'] ?? '',
        'city' => ($_POST['city'] ?? 0),
        'state' => ($_POST['state'] ?? 0),
        'zip' => $_POST['zip'] ?? '',
        'county' => ($_POST['county'] ?? 0),
        'description' => $_POST['description'] ?? '',
    ];

    apiRequest(
        'PUT',
        "/properties/$propertyId",
        $payload
    );

    $result = apiRequest(
        'GET', 
        "/properties/$propertyId"
    );

    $message .= "
        <div style='
            background:#dff0d8;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        '>
            Property Updated Successfully
        </div>
    ";

    if (!empty($result['images'])) {
        foreach ($result['images'] as $img) {

            $url = '/storage/' . $img['image_path'];

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
    <title>Edit Property</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="TF-Style.css">
</head>

<body>

<h1>Edit Property</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="street_address"
        placeholder="Street Address"
        required
        value="<?= htmlspecialchars($property['street_address'] ?? '') ?>"
    >

    <input
        type="text"
        name="address_line_2"
        placeholder="Address Line 2"
        value="<?= htmlspecialchars($property['address_line_2'] ?? '') ?>"
    >

    <input
        type="text"
        name="apartment"
        placeholder="Apartment / Unit"
        value="<?= htmlspecialchars($property['apartment'] ?? '') ?>"
    >

    <textarea
        name="city"
        placeholder="city"
        required
    ><?= htmlspecialchars($property['city'] ?? '') ?></textarea>

    <input
        name="state"
        placeholder="state"
        value="<?= htmlspecialchars($property['state'] ?? '') ?>"
    >

    <input
        type="text"
        name="zip"
        placeholder="zip"
        value="<?= htmlspecialchars($property['zip'] ?? '') ?>"
    >

    <input
        type="text"
        name="description"
        placeholder="description"
        value="<?= htmlspecialchars($property['description'] ?? '') ?>"
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

    <div id="uploadedImages">
        <?php if (!empty($property['images'])): ?>
            <?php foreach ($property['images'] as $img): ?>
<div style="
    position:relative;
    display:inline-block;
    margin-bottom:15px;
">

    <img
        src="/storage/<?= htmlspecialchars($img['image_path']) ?>"
        style="
            max-width:200px;
            border:1px solid #ccc;
            border-radius:8px;
            display:block;
        "
    >

<form
    method="post"
    action="delete_property_image.php"
    style="
        position:absolute;
        top:6px;
        right:6px;
        margin:0;
    "
    onsubmit="return confirm('Delete this image?');"
>
    <input
        type="hidden"
        name="image_id"
        value="<?= (int)$img['id'] ?>"
    >

    <button
        type="submit"
        style="
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
</form>

</div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <br>

    <button type="submit">
        Update Property
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