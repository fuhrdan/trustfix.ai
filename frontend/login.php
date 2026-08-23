<?php

require 'config.php';
require 'login_security_client.php';

if (!empty($_SESSION['jwt_token'])) {

    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = !empty($_GET['verified'])
    ? 'Your email is verified. You can now sign in.'
    : '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    requireValidCsrf();

    $payload = array_merge([
        'email' => $_POST['email'],
        'password' => $_POST['password']
    ], trustFixLoginSecurityClientContext());

    $result = apiRequest(
        'POST',
        '/login',
        $payload
    );

    if (!empty($result['token'])) {

        session_regenerate_id(true);

        $_SESSION['jwt_token'] = $result['token'];

        $_SESSION['user'] = $result['user'] ?? [];

        header('Location: dashboard.php');
        exit;
    }

    $error = apiMessage($result, 'Login failed');
}

include 'header.php';
?>

<h1>Login</h1>

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

<form method="POST">
    <?= csrfField() ?>

    <label for="login_email">Email Address</label>
    <input
        id="login_email"
        type="email"
        name="email"
        placeholder="Email"
        required
    >

    <label for="login_password">Password</label>
    <input
        id="login_password"
        type="password"
        name="password"
        placeholder="Password"
        required
    >

    <button type="submit">
        Login
    </button>

</form>

<p>
    Need an account?
    <a href="register.php">Register Here</a>
</p>

<p>
    <a href="forgot_password.php">
        Forgot Password?
    </a>
</p>

<?php include 'footer.php'; ?>
