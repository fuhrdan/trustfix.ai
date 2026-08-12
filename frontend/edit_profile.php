<?php

require 'config.php';

requireLogin();

$message = '';

/*
|--------------------------------------------------------------------------
| Load User Account
|--------------------------------------------------------------------------
*/

$user = apiRequest(
    'GET',
    '/me'
);

/*
|--------------------------------------------------------------------------
| Load Contractor Profile
|--------------------------------------------------------------------------
*/

$contractor = apiRequest(
    'GET',
    '/contractor/profile'
);

if (!is_array($contractor))
{
    $contractor = [];
}

$contractorDocuments = apiRequest(
    'GET',
    '/contractor/documents'
);

if (!is_array($contractorDocuments))
{
    $contractorDocuments = [];
}

/*
|--------------------------------------------------------------------------
| Save Updates
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    requireValidCsrf();
    $contractorResult = [];

    /*
    |--------------------------------------------------------------------------
    | Update User Account
    |--------------------------------------------------------------------------
    */

    $userPayload = [
        'name' =>
            $_POST['name'] ?? '',

        'username' =>
            $_POST['username'] ?? null,

        'email' =>
            $_POST['email'] ?? '',

        'phone' =>
            $_POST['phone'] ?? null,

        'address' =>
            $_POST['address'] ?? null
    ];

    $userResult = apiRequest(
        'POST',
        '/me/update',
        $userPayload
    );

    /*
    |--------------------------------------------------------------------------
    | Update Contractor Profile
    |--------------------------------------------------------------------------
    */

    $wantsContractorProfile =
        !empty($_POST['sign_up_as_contractor']) ||
        !empty($contractor['id']);

    if ($wantsContractorProfile)
    {
        $contractorPayload = [
            'business_name' =>
                $_POST['business_name'] ?? '',

            'business_address' =>
                $_POST['business_address'] ?? null,

            'business_phone' =>
                $_POST['business_phone'] ?? null,

            'business_type' =>
                $_POST['business_type'] ?? null,

            'year_established' =>
                !empty($_POST['year_established'])
                    ? (int) $_POST['year_established']
                    : null,

            'website' =>
                $_POST['website'] ?? null,

            'service_area' =>
                $_POST['service_area'] ?? null,

            'emergency_availability' =>
                !empty($_POST['emergency_availability']),

            'license_number' =>
                $_POST['license_number'] ?? null,

            'state_license' =>
                $_POST['state_license'] ?? null,

            'local_license' =>
                $_POST['local_license'] ?? null,

            'sales_tax_license' =>
                $_POST['sales_tax_license'] ?? null,

            'license_expiration_date' =>
                $_POST['license_expiration_date'] ?? null,

            'coi_path' =>
                $_POST['coi_path'] ?? null,

            'insurance_expiration_date' =>
                $_POST['insurance_expiration_date'] ?? null,

            'surety_bond_path' =>
                $_POST['surety_bond_path'] ?? null,

            'service_agreement' =>
                $_POST['service_agreement'] ?? null,

            'bio' =>
                $_POST['bio'] ?? null,

            'is_public' =>
                !empty($_POST['is_public'])
        ];

        $contractorResult = apiRequest(
            'POST',
            '/contractor/profile',
            $contractorPayload
        );

    }

    /*
    |--------------------------------------------------------------------------
    | Reload Fresh Data
    |--------------------------------------------------------------------------
    */

    $user = apiRequest(
        'GET',
        '/me'
    );

    $contractor = apiRequest(
        'GET',
        '/contractor/profile'
    );

    if (!is_array($contractor))
    {
        $contractor = [];
    }

    $contractorDocuments = apiRequest(
        'GET',
        '/contractor/documents'
    );

    if (!is_array($contractorDocuments))
    {
        $contractorDocuments = [];
    }

    if (!empty($userResult['errors']) || !empty($contractorResult['errors']))
    {
        $message =
            '<p style="color:red;">Please check the profile fields and try again.</p>';
    }
    else
    {
        $message =
            '<p style="color:green;">Profile updated successfully.</p>';
    }
}

$hasContractorProfile =
    !empty($contractor['id']);

function contractorDocumentStatus($documents, $type)
{
    if (empty($documents[$type]) || !is_array($documents[$type]))
    {
        return [
            'label' => 'Not uploaded',
            'style' => 'color:#777;',
            'verified' => false
        ];
    }

    $document = $documents[$type];
    $status = $document['status'] ?? 'pending';
    $verified = !empty($document['verified']) || $status === 'approved';

    if ($verified)
    {
        return [
            'label' => '✓ Verified by admin',
            'style' => 'color:green; font-weight:bold;',
            'verified' => true
        ];
    }

    if ($status === 'rejected')
    {
        return [
            'label' => 'Rejected - upload a replacement',
            'style' => 'color:red;',
            'verified' => false
        ];
    }

    return [
        'label' => 'Uploaded - pending admin verification',
        'style' => 'color:#b36b00;',
        'verified' => false
    ];
}

function renderContractorDocumentUpload($documents, $type, $fieldName, $label)
{
    $status = contractorDocumentStatus($documents, $type);
    $inputId = 'contractor_document_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $type);
    $buttonId = $inputId . '_button';
    $statusId = $inputId . '_status';
    ?>
        <div
            class="contractor-document-upload"
            data-document-type="<?= htmlspecialchars($type) ?>"
            style="margin:12px 0 18px 0; padding:10px; border:1px solid #ddd; border-radius:6px;"
        >
            <label for="<?= htmlspecialchars($inputId) ?>"><?= htmlspecialchars($label) ?></label>

            <input
                id="<?= htmlspecialchars($inputId) ?>"
                class="contractor-document-file"
                type="file"
                name="<?= htmlspecialchars($fieldName) ?>"
                accept=".pdf,image/jpeg,image/png,image/webp"
            >

            <button
                id="<?= htmlspecialchars($buttonId) ?>"
                class="contractor-document-upload-button"
                type="button"
                style="display:none; margin-top:8px;"
            >
                Upload
            </button>

            <div
                id="<?= htmlspecialchars($statusId) ?>"
                class="contractor-document-status"
                style="<?= $status['style'] ?> margin-top:6px;"
            >
                <?= htmlspecialchars($status['label']) ?>
            </div>
        </div>
    <?php
}

$pageTitle = 'Profile';
include 'header.php';

?>

<h1>Edit Profile</h1>

<?= $message ?>

<form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>

    <h2>Account Information</h2>

    <label for="profile_name">Full Name</label>
    <input
        id="profile_name"
        type="text"
        name="name"
        placeholder="Full Name"
        value="<?= htmlspecialchars(
            $user['name']
            ?? ''
        ) ?>"
        required
    >

    <label for="profile_username">Username</label>
    <input
        id="profile_username"
        type="text"
        name="username"
        placeholder="Username"
        value="<?= htmlspecialchars(
            $user['username']
            ?? ''
        ) ?>"
    >

    <label for="profile_email">Email Address</label>
    <input
        id="profile_email"
        type="email"
        name="email"
        placeholder="Email Address"
        value="<?= htmlspecialchars(
            $user['email']
            ?? ''
        ) ?>"
        required
    >

    <label for="profile_phone">Phone Number</label>
    <input
        id="profile_phone"
        type="text"
        name="phone"
        placeholder="Phone Number"
        value="<?= htmlspecialchars(
            $user['phone']
            ?? ''
        ) ?>"
    >

    <label for="profile_address">Mailing / Account Address</label>
    <input
        id="profile_address"
        type="text"
        name="address"
        placeholder="Account Address"
        value="<?= htmlspecialchars(
            $user['address']
            ?? ''
        ) ?>"
    >

    <hr>

    <h2>Contractor / Business Information</h2>

    <div style="display:flex; align-items:center; margin-bottom:20px;">
        <input
            type="checkbox"
            name="sign_up_as_contractor"
            id="sign_up_as_contractor"
            value="1"
            <?= $hasContractorProfile ? 'checked' : '' ?>
            style="margin:0 10px 0 0; width:auto;"
        >

        <label
            for="sign_up_as_contractor"
            style="margin:0; font-weight:bold; cursor:pointer;"
        >
            Sign up as contractor
        </label>
    </div>

    <div id="contractor_fields" style="<?= $hasContractorProfile ? '' : 'display:none;' ?>">

        <label for="business_name">Business Name</label>
        <input
            id="business_name"
            type="text"
            name="business_name"
            placeholder="Business Name"
            value="<?= htmlspecialchars(
                $contractor['business_name']
                ?? ''
            ) ?>"
        >

        <label for="business_address">Business Address</label>
        <input
            id="business_address"
            type="text"
            name="business_address"
            placeholder="Business Address"
            value="<?= htmlspecialchars(
                $contractor['business_address']
                ?? ''
            ) ?>"
        >

        <label for="business_phone">Business Phone Number</label>
        <input
            id="business_phone"
            type="text"
            name="business_phone"
            placeholder="Business Phone Number"
            value="<?= htmlspecialchars(
                $contractor['business_phone']
                ?? $contractor['phone']
                ?? ''
            ) ?>"
        >

        <label for="business_type">Business Type</label>
        <select id="business_type" name="business_type">
            <option value="">Select Business Type</option>
            <option value="individual" <?= (($contractor['business_type'] ?? '') === 'individual') ? 'selected' : '' ?>>Individual</option>
            <option value="company" <?= (($contractor['business_type'] ?? '') === 'company') ? 'selected' : '' ?>>Company</option>
        </select>

        <label for="year_established">Year Established</label>
        <input
            id="year_established"
            type="number"
            name="year_established"
            placeholder="Year Established"
            min="1800"
            max="<?= date('Y') ?>"
            value="<?= htmlspecialchars(
                $contractor['year_established']
                ?? ''
            ) ?>"
        >

        <label for="business_website">Website</label>
        <input
            id="business_website"
            type="url"
            name="website"
            placeholder="https://example.com"
            value="<?= htmlspecialchars(
                $contractor['website']
                ?? ''
            ) ?>"
        >

        <label for="service_area">Service Area</label>
        <input
            id="service_area"
            type="text"
            name="service_area"
            placeholder="Denver metro, Boulder, Colorado Springs, etc."
            value="<?= htmlspecialchars(
                $contractor['service_area']
                ?? ''
            ) ?>"
        >

    <div style="display:flex; align-items:center; margin-bottom:20px;">
        <input
            type="checkbox"
            name="emergency_availability"
            id="emergency_availability"
            value="1"
            <?= !empty($contractor['emergency_availability']) ? 'checked' : '' ?>
            style="margin:0 10px 0 0; width:auto;"
        >

        <label
            for="emergency_availability"
            style="margin:0; font-weight:bold; cursor:pointer;"
        >
            Available for emergency work
        </label>
    </div>

        <label for="state_license">State Licenses</label>
        <input
            id="state_license"
            type="text"
            name="state_license"
            placeholder="State License Number(s)"
            value="<?= htmlspecialchars(
                $contractor['state_license']
                ?? $contractor['license_number']
                ?? ''
            ) ?>"
        >

        <?php renderContractorDocumentUpload(
            $contractorDocuments,
            'state_license',
            'state_license_file',
            'State License Document'
        ); ?>

        <label for="local_license">Local Licenses</label>
        <input
            id="local_license"
            type="text"
            name="local_license"
            placeholder="Local License Number(s)"
            value="<?= htmlspecialchars(
                $contractor['local_license']
                ?? ''
            ) ?>"
        >

        <label for="sales_tax_license">Sales Tax License</label>
        <input
            id="sales_tax_license"
            type="text"
            name="sales_tax_license"
            placeholder="Sales Tax License"
            value="<?= htmlspecialchars(
                $contractor['sales_tax_license']
                ?? ''
            ) ?>"
        >

        <?php renderContractorDocumentUpload(
            $contractorDocuments,
            'sales_tax_license',
            'sales_tax_license_file',
            'Sales Tax License Document'
        ); ?>

        <label for="license_expiration_date">License Expiration Date</label>
        <input
            id="license_expiration_date"
            type="date"
            name="license_expiration_date"
            value="<?= htmlspecialchars(
                substr($contractor['license_expiration_date'] ?? '', 0, 10)
            ) ?>"
        >

        <?php renderContractorDocumentUpload(
            $contractorDocuments,
            'certificate_of_liability_insurance',
            'coi_file',
            'Certificate of Liability Insurance (COI)'
        ); ?>
        <input type="hidden" name="coi_path" value="<?= htmlspecialchars($contractor['coi_path'] ?? '') ?>">

        <label for="insurance_expiration_date">Insurance Expiration Date</label>
        <input
            id="insurance_expiration_date"
            type="date"
            name="insurance_expiration_date"
            value="<?= htmlspecialchars(
                substr($contractor['insurance_expiration_date'] ?? '', 0, 10)
            ) ?>"
        >

        <?php renderContractorDocumentUpload(
            $contractorDocuments,
            'surety_bond',
            'surety_bond_file',
            'Surety Bond'
        ); ?>
        <input type="hidden" name="surety_bond_path" value="<?= htmlspecialchars($contractor['surety_bond_path'] ?? '') ?>">

        <label for="service_agreement">Contract / Service Agreement Area</label>
        <textarea
            id="service_agreement"
            name="service_agreement"
            placeholder="Paste service agreement text or notes here."
        ><?= htmlspecialchars(
            $contractor['service_agreement']
            ?? ''
        ) ?></textarea>

        <?php renderContractorDocumentUpload(
            $contractorDocuments,
            'service_agreement',
            'service_agreement_file',
            'Contract / Service Agreement File'
        ); ?>

        <label for="business_bio">Business Bio</label>
        <textarea
            id="business_bio"
            name="bio"
            placeholder="Business Bio"
        ><?= htmlspecialchars(
            $contractor['bio']
            ?? ''
        ) ?></textarea>

    <div style="display:flex; align-items:center; margin-bottom:20px;">
        <input
            type="checkbox"
            name="is_public"
            id="is_public"
            value="1"
            <?= !empty($contractor['is_public']) ? 'checked' : '' ?>
            style="margin:0 10px 0 0; width:auto;"
        >

        <label
            for="is_public"
            style="margin:0; font-weight:bold; cursor:pointer;"
        >
            Show my business profile in public contractor search after approval
        </label>
    </div>

        <?php if (!empty($contractor['background_check_status']) || !empty($contractor['is_verified'])): ?>
            <p>
                <strong>Background Check Status:</strong>
                <?= htmlspecialchars($contractor['background_check_status'] ?? 'not_started') ?>
                <br>
                <strong>Verified:</strong>
                <?= !empty($contractor['is_verified']) ? 'Yes' : 'No' ?>
            </p>
        <?php endif; ?>

    </div>

    <button type="submit">
        Save Profile
    </button>

</form>

<script>
const contractorCheckbox = document.getElementById('sign_up_as_contractor');
const contractorFields = document.getElementById('contractor_fields');

function toggleContractorFields()
{
    contractorFields.style.display = contractorCheckbox.checked ? '' : 'none';
}

contractorCheckbox.addEventListener('change', toggleContractorFields);
toggleContractorFields();

document.querySelectorAll('.contractor-document-upload').forEach(function (wrapper)
{
    const fileInput = wrapper.querySelector('.contractor-document-file');
    const uploadButton = wrapper.querySelector('.contractor-document-upload-button');
    const statusBox = wrapper.querySelector('.contractor-document-status');
    const documentType = wrapper.dataset.documentType;

    fileInput.addEventListener('change', function ()
    {
        if (fileInput.files.length > 0)
        {
            uploadButton.style.display = 'inline-block';
            statusBox.style.color = '#555';
            statusBox.style.fontWeight = 'normal';
            statusBox.textContent = 'Selected: ' + fileInput.files[0].name;
        }
        else
        {
            uploadButton.style.display = 'none';
        }
    });

    uploadButton.addEventListener('click', async function ()
    {
        if (fileInput.files.length === 0)
        {
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', <?= json_encode(csrfToken()) ?>);
        formData.append('document_type', documentType);
        formData.append('file', fileInput.files[0]);

        uploadButton.disabled = true;
        statusBox.style.color = '#555';
        statusBox.style.fontWeight = 'normal';
        statusBox.textContent = 'Uploading...';

        try
        {
            const response = await fetch('upload_contractor_document.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success)
            {
                statusBox.style.color = 'green';
                statusBox.style.fontWeight = 'bold';
                statusBox.textContent = 'Upload successful - pending admin verification';
                uploadButton.style.display = 'none';
                fileInput.value = '';
            }
            else
            {
                statusBox.style.color = 'red';
                statusBox.style.fontWeight = 'normal';
                statusBox.textContent = result.error || 'Upload failed';
                uploadButton.disabled = false;
            }
        }
        catch (error)
        {
            statusBox.style.color = 'red';
            statusBox.style.fontWeight = 'normal';
            statusBox.textContent = 'Upload failed: ' + error.message;
            uploadButton.disabled = false;
        }
    });
});
</script>

<?php include 'footer.php'; ?>
