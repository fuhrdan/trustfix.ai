<?php

if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

require 'config.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $token)) {
    $error = 'This single sign-on completion link is invalid or has expired.';
} else {
    $result = apiRequest('POST', '/auth/oidc/complete', [
        'completion_token' => $token,
    ]);

    if (!empty($result['token'])) {
        session_regenerate_id(true);
        $_SESSION['jwt_token'] = $result['token'];
        $_SESSION['user'] = $result['user'] ?? [];
        $_SESSION['flash_success'] = 'You are signed in with Crane.';
        header('Location: dashboard.php');
        exit;
    }

    $error = apiMessage($result, 'TrustFix could not complete single sign-on. Start again from the sign-in page.');
}

$pageTitle = 'Single Sign-On';
include 'header.php';
?>

<section class="tf-empty-state" role="alert">
    <div class="tf-empty-state-icon" aria-hidden="true">!</div>
    <h1>Single sign-on was not completed</h1>
    <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="tf-button" href="login.php">Return to sign in</a>
</section>

<?php include 'footer.php'; ?>
