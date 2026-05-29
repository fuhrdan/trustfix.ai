<?php
require 'config.php';

//It is late, I had this open way too long and now the program is telling me
//There are unsaved changes.  Seriously, WTF?  So I'm going to save and close
//When something breaks, blame the other guy, because I am tired.
//Dan

session_start();

//-------------------------------------------------
// Create draft job if one does not exist
//-------------------------------------------------
if (!isset($_SESSION['draft_job_id'])) {

    $payload = [
        'address' => '',
        'lat' => 0,
        'lng' => 0,
        'initial_description' => 'Draft Job',
        'agreed_price' => 0
    ];

    $draftJob = apiRequest(
        'POST',
        '/jobs',
        $payload
    );

    $_SESSION['draft_job_id'] =
        $draftJob['id'];
}

$draftJobId =
    $_SESSION['draft_job_id'];

requireLogin();

/*
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax_upload'])
) {

    header('Content-Type: application/json');

    $jobId = $_SESSION['temp_job_id'] ?? null;

    if (!$jobId) {

        $payload = [
            'address' => 'Draft Job',
            'lat' => 0,
            'lng' => 0,
            'initial_description' => 'Draft',
            'agreed_price' => 0
        ];

        $job = apiRequest('POST', '/jobs', $payload);

        $jobId = $job['id'];

        $_SESSION['temp_job_id'] = $jobId;
    }

    $file = new CURLFile(
        $_FILES['image']['tmp_name'],
        $_FILES['image']['type'],
        $_FILES['image']['name']
    );

    apiRequest(
        'POST',
        "/jobs/$jobId/images",
        [
            'images[]' => $file
        ]
    );

    $job = apiRequest('GET', "/jobs/$jobId");

    $html = '';

    if (!empty($job['images'])) {

        foreach ($job['images'] as $img) {

            $url =
                'https://trustfix.lakehousesoftware.com/storage/' . $img['image_path'];

            $html .= "
                <div style='margin-bottom:15px;'>
                    <img
                        src='{$url}'
                        style='max-width:200px;
                               border:1px solid #ccc;
                               border-radius:8px;'
                    >
                </div>
            ";
        }
    }

    echo json_encode([
        'success' => true,
        'html' => $html
    ]);

    exit;
}
*/

//***************************************************************************
// TEST DEBUG
//***************************************************************************

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax_upload'])
) {

    header('Content-Type: application/json');

    try {

        $jobId = $_SESSION['temp_job_id'] ?? null;

        if (!$jobId) {

            $payload = [
                'address' => 'Draft Job',
                'lat' => 0,
                'lng' => 0,
                'initial_description' => 'Draft',
                'agreed_price' => 0
            ];

            $job = apiRequest('POST', '/jobs', $payload);

            if (!isset($job['id'])) {
                throw new Exception("Job creation failed: " . json_encode($job));
            }

            $jobId = $job['id'];
            $_SESSION['temp_job_id'] = $jobId;
        }

        //---------------------------------
        // DEBUG FILE CHECK
        //---------------------------------
        if (!isset($_FILES['image'])) {
            throw new Exception("No file received");
        }

        $file = new CURLFile(
            $_FILES['image']['tmp_name'],
            $_FILES['image']['type'],
            $_FILES['image']['name']
        );

        //---------------------------------
        // UPLOAD IMAGE
        //---------------------------------
        $uploadResponse = apiRequest(
            'POST',
            "/jobs/$jobId/images",
            ['images[]' => $file]
        );

        if (!$uploadResponse) {
            throw new Exception("Upload returned empty response");
        }

        //---------------------------------
        // GET JOB
        //---------------------------------
        $job = apiRequest('GET', "/jobs/$jobId");

        if (!is_array($job)) {
            throw new Exception("Job fetch failed (non-JSON response)");
        }

        $html = '';

        if (!empty($job['images'])) {

            foreach ($job['images'] as $img) {

                $url = '/storage/' . $img['image_path'];

                $html .= "
                    <div style='margin-top:10px'>
                        <img src='{$url}' style='max-width:200px;border:1px solid #ccc'>
                    </div>
                ";
            }
        }

        echo json_encode([
            'success' => true,
            'html' => $html
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
}

//***************************************************************************
// END TEST
//***************************************************************************

include 'header.php';

$message = '';

//=========================================================
// Create placeholder job immediately
//=========================================================
if (!isset($_SESSION['draft_job_id'])) {

    $draftPayload = [
        'address' => 'Draft Job',
        'lat' => 0,
        'lng' => 0,
        'initial_description' => 'Draft',
        'agreed_price' => 0
    ];

    $draftJob = apiRequest('POST', '/jobs', $draftPayload);

    $_SESSION['draft_job_id'] = $draftJob['id'];
}

$jobId = $_SESSION['draft_job_id'];

//=========================================================
// AJAX IMAGE UPLOAD
//=========================================================
if (
    isset($_POST['ajax_upload']) &&
    $_POST['ajax_upload'] == '1'
) {

    header('Content-Type: application/json');

    if (!empty($_FILES['image']['tmp_name'])) {

        $file = new CURLFile(
            $_FILES['image']['tmp_name'],
            $_FILES['image']['type'],
            $_FILES['image']['name']
        );

        $uploadResult = apiRequest(
            'POST',
            "/jobs/$jobId/images",
            [
                'images[]' => $file
            ]
        );

        $latestJob = apiRequest(
            'GET',
            "/jobs/$jobId"
        );

        $html = '';

        if (!empty($latestJob['images'])) {

            foreach ($latestJob['images'] as $img) {

                $url = '/storage/' . $img['image_path'];

                $html .= "
                    <div style='margin-top:15px;'>

                        <img
                            src='{$url}'
                            style='
                                max-width:200px;
                                border:1px solid #999;
                                border-radius:8px;
                                display:block;
                                margin-bottom:10px;
                            '
                        >

                    </div>
                ";
            }
        }

        echo json_encode([
            'success' => true,
            'html' => $html
        ]);

        exit;
    }

    echo json_encode([
        'success' => false
    ]);

    exit;
}

//=========================================================
// FINAL SAVE
//=========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

    $payload = [
        'address' => $_POST['address'],
        'lat' => (float)$_POST['lat'],
        'lng' => (float)$_POST['lng'],
        'initial_description' => $_POST['initial_description'],
        'agreed_price' => (float)$_POST['agreed_price'],
        'onsite_contact_name' => $_POST['onsite_contact_name'],
        'onsite_contact_phone' => $_POST['onsite_contact_phone'],
        'skills' => $_POST['skills'] ?? []
    ];

    apiRequest(
        'PUT',
        "/jobs/$jobId",
        $payload
    );

    $result = apiRequest(
        'GET',
        "/jobs/$jobId"
    );

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

    $message .= '<pre>' . print_r($result, true) . '</pre>';

    unset($_SESSION['draft_job_id']);
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

    <input
        type="text"
        name="address"
        placeholder="Address"
        required
    >

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