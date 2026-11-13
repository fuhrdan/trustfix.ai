<?php

require 'config.php';

if (!empty($_SESSION['jwt_token'])) {
    header('Location: dashboard.php');
    exit;
}

$allowedAccountTypes = ['customer', 'contractor'];
$requestedAccountType = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? strtolower(trim((string)($_POST['account_type'] ?? 'customer')))
    : strtolower(trim((string)($_GET['account'] ?? 'customer')));
$accountType = in_array($requestedAccountType, $allowedAccountTypes, true)
    ? $requestedAccountType
    : 'customer';
$isContractor = $accountType === 'contractor';
$error = '';
$success = '';

$formValues = [
    'name' => (string)($_POST['name'] ?? ''),
    'email' => (string)($_POST['email'] ?? ''),
    'phone' => (string)($_POST['phone'] ?? ''),
    'business_name' => (string)($_POST['business_name'] ?? ''),
    'service_area' => (string)($_POST['service_area'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $payload = [
        'name' => $formValues['name'],
        'email' => $formValues['email'],
        'phone' => $formValues['phone'],
        'password' => $_POST['password'] ?? '',
        'password_confirmation' => $_POST['password_confirmation'] ?? '',
        'account_type' => $accountType,
        'business_name' => $isContractor
            ? $formValues['business_name']
            : null,
        'service_area' => $isContractor
            ? $formValues['service_area']
            : null,
    ];

    $result = apiRequest('POST', '/register', $payload);

    if (!empty($result['token'])) {
        session_regenerate_id(true);
        $_SESSION['jwt_token'] = $result['token'];
        $_SESSION['user'] = $result['user'] ?? [];
        header('Location: dashboard.php');
        exit;
    }

    if (!empty($result['requires_email_verification'])) {
        $success = $result['message']
            ?? 'Account created. Check your email to verify your address.';
    } else {
        $error = apiMessage($result, 'Registration failed');
    }
}

$pageTitle = $isContractor
    ? 'Create Contractor Account'
    : 'Create Account';
include 'header.php';
?>

<h1><?= $isContractor ? 'Create a Contractor Account' : 'Create an Account' ?></h1>

<p class="tf-page-intro">
    <?= $isContractor
        ? 'Create one account for your contractor profile. Your profile remains private and pending until TrustFix reviews and approves it.'
        : 'Create a homeowner account, or choose contractor if you provide home-repair services.' ?>
</p>

<?php if (!empty($error)) { ?>
    <div class="tf-alert tf-alert-error" role="alert">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php } ?>

<?php if (!empty($success)) { ?>
    <div class="tf-alert tf-alert-success" role="status">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php } ?>

<?php if (empty($success)) { ?>
<style>
    .tf-account-type-options {
        border: 1px solid #d6dde2;
        border-radius: 10px;
        margin: 0 0 22px;
        padding: 16px 18px;
    }

    .tf-account-type-options legend {
        font-weight: 700;
        padding: 0 6px;
    }

    .tf-account-type-choice {
        align-items: center;
        cursor: pointer;
        display: flex;
        gap: 10px;
        margin: 10px 0;
    }

    .tf-account-type-choice input[type="radio"] {
        margin: 0;
        width: auto;
    }
</style>

<form method="POST">
    <?= csrfField() ?>

    <fieldset class="tf-account-type-options">
        <legend>Account Type</legend>

        <label class="tf-account-type-choice">
            <input
                type="radio"
                name="account_type"
                value="customer"
                <?= !$isContractor ? 'checked' : '' ?>
            >
            Homeowner / Customer
        </label>

        <label class="tf-account-type-choice">
            <input
                type="radio"
                name="account_type"
                value="contractor"
                <?= $isContractor ? 'checked' : '' ?>
            >
            Contractor / Service Professional
        </label>
    </fieldset>

    <label for="register_name">Full Name</label>
    <input
        id="register_name"
        type="text"
        name="name"
        autocomplete="name"
        value="<?= htmlspecialchars($formValues['name'], ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <label for="register_email">Email Address</label>
    <input
        id="register_email"
        type="email"
        name="email"
        autocomplete="email"
        value="<?= htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8') ?>"
        required
    >

    <label for="register_phone">Phone Number <span aria-hidden="true">(optional)</span></label>
    <input
        id="register_phone"
        type="tel"
        name="phone"
        autocomplete="tel"
        value="<?= htmlspecialchars($formValues['phone'], ENT_QUOTES, 'UTF-8') ?>"
    >

    <div id="contractor-registration-fields" <?= !$isContractor ? 'hidden' : '' ?>>
        <label for="register_business_name">Business Name</label>
        <input
            id="register_business_name"
            type="text"
            name="business_name"
            autocomplete="organization"
            value="<?= htmlspecialchars($formValues['business_name'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $isContractor ? 'required' : '' ?>
        >

        <label for="register_service_area">Location / Service Area</label>
        <input
            id="register_service_area"
            type="text"
            name="service_area"
            placeholder="Denver metro, Boulder, Colorado Springs, etc."
            value="<?= htmlspecialchars($formValues['service_area'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $isContractor ? 'required' : '' ?>
        >

        <p class="tf-page-intro">
            After email verification, complete your licenses, insurance, bio, and other profile information. Job access and payout setup remain unavailable until TrustFix approval.
        </p>
    </div>

    <label for="register_password">Password</label>
    <input
        id="register_password"
        type="password"
        name="password"
        autocomplete="new-password"
        minlength="8"
        required
    >

    <label for="register_password_confirmation">Confirm Password</label>
    <input
        id="register_password_confirmation"
        type="password"
        name="password_confirmation"
        autocomplete="new-password"
        minlength="8"
        required
    >

    <button type="submit">
        <?= $isContractor ? 'Create Contractor Account' : 'Create Account' ?>
    </button>
</form>

<script>
(() => {
    const accountTypeInputs = document.querySelectorAll(
        'input[name="account_type"]'
    );
    const contractorFields = document.getElementById(
        'contractor-registration-fields'
    );
    const requiredContractorFields = contractorFields.querySelectorAll(
        'input[name="business_name"], input[name="service_area"]'
    );

    const updateAccountType = () => {
        const selected = document.querySelector(
            'input[name="account_type"]:checked'
        );
        const contractorSelected = selected && selected.value === 'contractor';

        contractorFields.hidden = !contractorSelected;
        requiredContractorFields.forEach((field) => {
            field.required = contractorSelected;
        });
    };

    accountTypeInputs.forEach((input) => {
        input.addEventListener('change', updateAccountType);
    });

    updateAccountType();
})();
</script>
<?php } ?>

<p>
    Already have an account?
    <a href="login.php">Sign In</a>
</p>

<?php include 'footer.php'; ?>
