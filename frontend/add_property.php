<?php

require 'config.php';

// Part II : Electric Boogaloo.  Going to give this a trie <sic> and see
// if I can get the magic to work again.  Hopefully better than
// Mary Poppins Returns.  Which IS a thing that happened, and no one 
// ever remembers or talks about it.  What was wrong with Mary Poppins?
// was it not enough?  Start asking questions people.
// This is because this is a copy of add job updated for property.
//Dan

session_start();

requireLogin();

include 'header.php';

//-------------------------------------------------
// Create draft property if one does not exist
//-------------------------------------------------
if (!isset($_SESSION['draft_property_id'])) {

    $payload = [
        'street_address' => '123 Test St',
        'city' => 'Test',
        'state' => 'Insanity',
        'zip' => '12345',
        'county' => 'Crazy',
        'description' => 'Test'
    ];

    $draftProperty = apiRequest(
        'POST',
        '/properties',
        $payload
    );
/*    
echo "<pre>";
print_r($draftProperty);
echo "</pre>";
exit;
*/
    $_SESSION['draft_property_id'] =
        $draftProperty['id'] ?? $draftProperty['data']['id'] ?? null;
}

$draftPropertyId =
    $_SESSION['draft_property_id'];




$message = '';

//=========================================================
// Create placeholder property immediately
//=========================================================

$propertyId = $_SESSION['draft_property_id'];


//=========================================================
// FINAL SAVE
//=========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    !isset($_POST['ajax_upload'])) {

    $payload = [
        'street_address' => $_POST['street_address'],
        'city' => $_POST['city'],
        'state' => $_POST['state'],
        'zip' => $_POST['zip'],
        'county' => $_POST['county'],
        'description' => $_POST['description'],
    ];

// ** DEBUG
/*
 echo "<pre>";
 echo "Draft Property = ";
 print_r($draftProperty);
 echo "<BR>Property ID = ";
 print_r($propertyId);

 echo "\n\nPayload:\n";
 print_r($payload);
 echo "</pre>";
 exit;
 */
// ** END DEBUG

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
            Property Saved Successfully
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

// This is the debugging message for testing.
// Remove it for production.
//    $message .= '<pre>' . print_r($result, true) . '</pre>';
// Error log
error_log(print_r($result, true));

    unset($_SESSION['draft_property_id']);
}
?>

<head>
    <title>Add Property</title>

    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="TF-Style.css">
</head>

<body>

<h1>Add Property</h1>

<?= $message ?>

<form method="POST">

    <input
        type="text"
        name="street_address"
        placeholder="Street Address"
        required
    >

    <textarea
        type="text"
        name="city"
        placeholder="City"
        required
    ></textarea>

    <input
        type="text"
        name="state"
        placeholder="State"
        required
    >

    <input
        type="text"
        name="zip"
        placeholder="Zip"
        required
    >

    <input
        type="text"
        name="county"
        placeholder="County"
    >

    <input
        type="text"
        name="description"
        placeholder="Description"
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

    <button type="submit">
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