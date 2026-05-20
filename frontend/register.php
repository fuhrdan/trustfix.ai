<?php

require 'config.php';

if (!empty($_SESSION['jwt_token'])) {

    header('Location: dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

// Find the erorr <sic>    
//echo "<pre>";
//print_r($result);
//echo "</pre>";
//exit;

    if (!empty($result['token'])) {

        $_SESSION['jwt_token'] = $result['token'];

        $_SESSION['user'] = $result['user'] ?? [];

        header('Location: dashboard.php');
        exit;
    }

    $error = $result['message'] ?? 'Registration failed';
}

include 'header.php';
?>

<h1>Register</h1>

<?php if (!empty($error)) { ?>
    <div style="color:red;margin-bottom:20px;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php } ?>

<form method="POST">

    <input
        type="text"
        name="name"
        placeholder="Full Name"
        required
    >

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

    <input
        type="password"
        name="password_confirmation"
        placeholder="Confirm Password"
        required
    >

    <button type="submit">
        Register
    </button>

</form>

<p>
    Already have an account?
    <a href="login.php">Login Here</a>
</p>

<?php include 'footer.php'; ?>