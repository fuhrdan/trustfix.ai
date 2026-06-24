<?php

require 'config.php';

requireLogin();

include 'header.php';

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

/*
|--------------------------------------------------------------------------
| Save Updates
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
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

?>

<h1>Edit Profile</h1>

<?= $message ?>

<form method="POST">

    <h2>Account Information</h2>

    <label>Full Name</label>
    <input
        type="text"
        name="name"
        placeholder="Full Name"
        value="<?= htmlspecialchars(
            $user['name']
            ?? ''
        ) ?>"
        required
    >

    <label>Username</label>
    <input
        type="text"
        name="username"
        placeholder="Username"
        value="<?= htmlspecialchars(
            $user['username']
            ?? ''
        ) ?>"
    >

    <label>Email Address</label>
    <input
        type="email"
        name="email"
        placeholder="Email Address"
        value="<?= htmlspecialchars(
            $user['email']
            ?? ''
        ) ?>"
        required
    >

    <label>Phone Number</label>
    <input
        type="text"
        name="phone"
        placeholder="Phone Number"
        value="<?= htmlspecialchars(
            $user['phone']
            ?? ''
        ) ?>"
    >

    <label>Mailing / Account Address</label>
    <input
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

    <label style="display:block; margin-bottom:15px;">
        <input
            type="checkbox"
            name="sign_up_as_contractor"
            id="sign_up_as_contractor"
            value="1"
            <?= $hasContractorProfile ? 'checked' : '' ?>
        >
        Sign up as contractor
    </label>

    <div id="contractor_fields" style="<?= $hasContractorProfile ? '' : 'display:none;' ?>">

        <label>Business Name</label>
        <input
            type="text"
            name="business_name"
            placeholder="Business Name"
            value="<?= htmlspecialchars(
                $contractor['business_name']
                ?? ''
            ) ?>"
        >

        <label>Business Address</label>
        <input
            type="text"
            name="business_address"
            placeholder="Business Address"
            value="<?= htmlspecialchars(
                $contractor['business_address']
                ?? ''
            ) ?>"
        >

        <label>Business Phone Number</label>
        <input
            type="text"
            name="business_phone"
            placeholder="Business Phone Number"
            value="<?= htmlspecialchars(
                $contractor['business_phone']
                ?? $contractor['phone']
                ?? ''
            ) ?>"
        >

        <label>Business Type</label>
        <select name="business_type">
            <option value="">Select Business Type</option>
            <option value="individual" <?= (($contractor['business_type'] ?? '') === 'individual') ? 'selected' : '' ?>>Individual</option>
            <option value="company" <?= (($contractor['business_type'] ?? '') === 'company') ? 'selected' : '' ?>>Company</option>
        </select>

        <label>Year Established</label>
        <input
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

        <label>Website</label>
        <input
            type="url"
            name="website"
            placeholder="https://example.com"
            value="<?= htmlspecialchars(
                $contractor['website']
                ?? ''
            ) ?>"
        >

        <label>Service Area</label>
        <input
            type="text"
            name="service_area"
            placeholder="Denver metro, Boulder, Colorado Springs, etc."
            value="<?= htmlspecialchars(
                $contractor['service_area']
                ?? ''
            ) ?>"
        >

        <label style="display:block; margin-bottom:15px;">
            <input
                type="checkbox"
                name="emergency_availability"
                value="1"
                <?= !empty($contractor['emergency_availability']) ? 'checked' : '' ?>
            >
            Available for emergency work
        </label>

        <label>State Licenses</label>
        <input
            type="text"
            name="state_license"
            placeholder="State License Number(s)"
            value="<?= htmlspecialchars(
                $contractor['state_license']
                ?? $contractor['license_number']
                ?? ''
            ) ?>"
        >

        <label>Local Licenses</label>
        <input
            type="text"
            name="local_license"
            placeholder="Local License Number(s)"
            value="<?= htmlspecialchars(
                $contractor['local_license']
                ?? ''
            ) ?>"
        >

        <label>Sales Tax License</label>
        <input
            type="text"
            name="sales_tax_license"
            placeholder="Sales Tax License"
            value="<?= htmlspecialchars(
                $contractor['sales_tax_license']
                ?? ''
            ) ?>"
        >

        <label>License Expiration Date</label>
        <input
            type="date"
            name="license_expiration_date"
            value="<?= htmlspecialchars(
                substr($contractor['license_expiration_date'] ?? '', 0, 10)
            ) ?>"
        >

        <label>Certificate of Liability Insurance (COI)</label>
        <input
            type="text"
            name="coi_path"
            placeholder="COI file path or reference for now"
            value="<?= htmlspecialchars(
                $contractor['coi_path']
                ?? ''
            ) ?>"
        >

        <label>Insurance Expiration Date</label>
        <input
            type="date"
            name="insurance_expiration_date"
            value="<?= htmlspecialchars(
                substr($contractor['insurance_expiration_date'] ?? '', 0, 10)
            ) ?>"
        >

        <label>Surety Bond</label>
        <input
            type="text"
            name="surety_bond_path"
            placeholder="Surety bond file path or reference for now"
            value="<?= htmlspecialchars(
                $contractor['surety_bond_path']
                ?? ''
            ) ?>"
        >

        <label>Contract / Service Agreement Area</label>
        <textarea
            name="service_agreement"
            placeholder="Paste service agreement text or notes here."
        ><?= htmlspecialchars(
            $contractor['service_agreement']
            ?? ''
        ) ?></textarea>

        <label>Business Bio</label>
        <textarea
            name="bio"
            placeholder="Business Bio"
        ><?= htmlspecialchars(
            $contractor['bio']
            ?? ''
        ) ?></textarea>

        <label style="display:block; margin-bottom:15px;">
            <input
                type="checkbox"
                name="is_public"
                value="1"
                <?= !empty($contractor['is_public']) ? 'checked' : '' ?>
            >
            Show my business profile in public contractor search after approval
        </label>

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
</script>

<?php include 'footer.php'; ?>
