<?php

require 'config.php';

if (!empty($_SESSION['jwt_token'])) {

    header('Location: dashboard.php');
    exit;
}

$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payload = [
        'email' => $_POST['email'],
        'password' => $_POST['password']
    ];

    $result = apiRequest(
        'POST',
        '/login',
        $payload
    );

    if (!empty($result['token'])) {

        $_SESSION['jwt_token'] = $result['token'];

        $_SESSION['user'] = $result['user'] ?? [];

        header('Location: dashboard.php');
        exit;
    }

    $error = $result['message'] ?? 'Login failed';
}

include 'header.php';
?>

<h1>Login</h1>

<?php if (!empty($error)) { ?>
    <div style="color:red;margin-bottom:20px;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php } ?>

<form method="POST">

    <input
        type="email"
        name="email"
        placeholder="Email"
        required
    >

    <input
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