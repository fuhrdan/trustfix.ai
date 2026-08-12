<?php

require 'config.php';

$message = '';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireValidCsrf();

    $payload = [
        'token' => $_POST['token'],
        'email' => $_POST['email'],
        'password' => $_POST['password'],
        'password_confirmation' =>
            $_POST['password_confirmation']
    ];

    $result = apiRequest(
        'POST',
        '/reset-password',
        $payload
    );

    if (isset($result['message'])) {

        $message =
            '<p style="color:green;">' .
            htmlspecialchars($result['message']) .
            '</p>';

    } else {

        $message =
            '<p style="color:red;">Password reset failed.</p>';
    }
}

include 'header.php';
?>

<h1>Reset Password</h1>

<?= $message ?>

<form method="POST">
    <?= csrfField() ?>

    <input
        type="hidden"
        name="token"
        value="<?= htmlspecialchars($token) ?>"
    >

    <label for="reset_email">Email Address</label>
    <input
        id="reset_email"
        type="email"
        name="email"
        value="<?= htmlspecialchars($email) ?>"
        required
    >

    <label for="reset_password">New Password</label>
    <input
        id="reset_password"
        type="password"
        name="password"
        placeholder="New Password"
        required
    >

    <label for="reset_password_confirmation">Confirm Password</label>
    <input
        id="reset_password_confirmation"
        type="password"
        name="password_confirmation"
        placeholder="Confirm Password"
        required
    >

    <button type="submit">
        Reset Password
    </button>

</form>

<?php include 'footer.php'; ?>
