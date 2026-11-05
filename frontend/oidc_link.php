<?php

if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

require 'config.php';

$incomingToken = trim((string)($_GET['token'] ?? ''));
if ($incomingToken !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $incomingToken)) {
        $error = 'This account-linking request is invalid or has expired.';
    } else {
        $_SESSION['oidc_link_token'] = $incomingToken;
        header('Location: oidc_link.php');
        exit;
    }
}

$linkToken = (string)($_SESSION['oidc_link_token'] ?? '');
if (!isset($error) && $linkToken === '') {
    $error = 'This account-linking request is invalid or has expired.';
}

if (!isset($error) && !empty($_SESSION['jwt_token'])) {
    $result = apiRequest('POST', '/auth/oidc/link', ['link_token' => $linkToken]);
    unset($_SESSION['oidc_link_token']);

    if (!empty($result['linked'])) {
        $_SESSION['flash_success'] = 'Your Crane identity is now linked to this TrustFix account.';
        header('Location: dashboard.php');
        exit;
    }

    $error = apiMessage($result, 'TrustFix could not link this external identity.');
}

if (!isset($error)) {
    header('Location: login.php?oidc_link=1');
    exit;
}

$pageTitle = 'Link Your Account';
include 'header.php';
?>

<section class="tf-empty-state" role="alert">
    <div class="tf-empty-state-icon" aria-hidden="true">!</div>
    <h1>Account linking needs attention</h1>
    <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="tf-button" href="login.php">Return to sign in</a>
</section>

<?php include 'footer.php'; ?>
