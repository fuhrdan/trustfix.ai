<?php

require 'config.php';

$message = '';

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

    <input
        type="hidden"
        name="token"
        value="<?= htmlspecialchars($token) ?>"
    >

    <input
        type="email"
        name="email"
        value="<?= htmlspecialchars($email) ?>"
        required
    >

    <input
        type="password"
        name="password"
        placeholder="New Password"
        required
    >

    <input
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