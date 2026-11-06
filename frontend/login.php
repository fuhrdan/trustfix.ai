<?php

require 'config.php';
require 'login_security_client.php';

if (!empty($_SESSION['jwt_token'])) {

    header('Location: dashboard.php');
    exit;
}

$error = '';
$incomingLinkToken = (string)($_GET['oidc_link_token'] ?? '');
if ($incomingLinkToken !== '') {
    if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $incomingLinkToken)) {
        $_SESSION['oidc_link_token'] = $incomingLinkToken;
        header('Location: login.php?oidc_link=1');
        exit;
    }

    $error = 'The account-linking request is invalid or has expired.';
}

$success = !empty($_GET['verified'])
    ? 'Your email is verified. You can now sign in.'
    : '';

if (!empty($_GET['oidc_error'])) {
    $error = substr(trim((string)$_GET['oidc_error']), 0, 500);
}


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

        $linkToken = (string)($_SESSION['oidc_link_token'] ?? '');
        if ($linkToken !== '') {
            unset($_SESSION['oidc_link_token']);
            $linkResult = apiRequest('POST', '/auth/oidc/link', [
                'link_token' => $linkToken,
            ]);

            if (!empty($linkResult['linked'])) {
                $_SESSION['flash_success'] = 'Your Crane identity is now linked to this TrustFix account.';
            } else {
                $_SESSION['flash_error'] = 'You signed in, but TrustFix could not link the external identity. Start the Crane sign-in process again if you still need to link it.';
            }
        }

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

<?php if (craneOidcEnabled()): ?>
    <div style="display:flex;align-items:center;gap:12px;margin:24px 0;color:#667085;">
        <span style="height:1px;background:#d0d5dd;flex:1;"></span>
        <span>or</span>
        <span style="height:1px;background:#d0d5dd;flex:1;"></span>
    </div>
    <p>
        <a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(oidcStartUrl('crane'), ENT_QUOTES, 'UTF-8') ?>">Sign in with Crane</a>
    </p>
<?php endif; ?>

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
