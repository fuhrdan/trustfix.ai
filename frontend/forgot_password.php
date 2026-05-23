<?php

require 'config.php';

include 'header.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'email' => $_POST['email']
    ];

    $result = apiRequest(
        'POST',
        '/forgot-password',
        $payload
    );

    if (!empty($result['message'])) {

        $message = $result['message'];

    } else {

        $error = 'Unable to send reset email.';
    }
}

?>

<h1>Forgot Password</h1>

<?php if (!empty($message)) { ?>

    <div style="color:green;">
        <?= htmlspecialchars($message) ?>
    </div>

<?php } ?>

<?php if (!empty($error)) { ?>

    <div style="color:red;">
        <?= htmlspecialchars($error) ?>
    </div>

<?php } ?>

<form method="POST">

    <input
        type="email"
        name="email"
        placeholder="Email Address"
        required
    >

    <button type="submit">
        Send Password Reset Link
    </button>

</form>

<?php include 'footer.php'; ?>