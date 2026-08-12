<?php

require 'config.php';

if (!empty($_SESSION['jwt_token'])) {

    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireValidCsrf();

    $payload = [
        'name' => $_POST['name'],
        'email' => $_POST['email'],
        'password' => $_POST['password'],
        'password_confirmation' => $_POST['password_confirmation']
    ];

    $result = apiRequest(
        'POST',
        '/register',
        $payload
    );

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

include 'header.php';
?>

<h1>Register</h1>

<?php if (!empty($error)) { ?>
    <div style="color:red;margin-bottom:20px;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php } ?>

<?php if (!empty($success)) { ?>
    <div class="tf-alert tf-alert-success">
        <?= htmlspecialchars($success) ?>
    </div>
<?php } ?>

<?php if (empty($success)) { ?>
<form method="POST">
    <?= csrfField() ?>

    <label for="register_name">Full Name</label>
    <input
        id="register_name"
        type="text"
        name="name"
        placeholder="Full Name"
        required
    >

    <label for="register_email">Email Address</label>
    <input
        id="register_email"
        type="email"
        name="email"
        placeholder="Email"
        required
    >

    <label for="register_password">Password</label>
    <input
        id="register_password"
        type="password"
        name="password"
        placeholder="Password"
        required
    >

    <label for="register_password_confirmation">Confirm Password</label>
    <input
        id="register_password_confirmation"
        type="password"
        name="password_confirmation"
        placeholder="Confirm Password"
        required
    >

    <button type="submit">
        Register
    </button>

</form>
<?php } ?>

<p>
    Already have an account?
    <a href="login.php">Login Here</a>
</p>

<?php include 'footer.php'; ?>
