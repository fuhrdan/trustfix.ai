<?php

if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

require 'config.php';

$incomingToken = trim((string)($_GET['token'] ?? ''));
if ($incomingToken !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $incomingToken)) {
        $error = 'This contractor invitation is invalid or has expired.';
    } else {
        $_SESSION['contractor_invitation_token'] = $incomingToken;
        header('Location: contractor_join.php');
        exit;
    }
}

$inviteToken = (string)($_SESSION['contractor_invitation_token'] ?? '');
if (!isset($error) && $inviteToken === '') {
    $error = 'This contractor invitation is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($error)) {
    requireValidCsrf();
    $result = apiRequest('POST', '/contractor-invitations/accept', [
        'invitation_token' => $inviteToken,
    ]);

    if (!empty($result['token'])) {
        unset($_SESSION['contractor_invitation_token']);
        session_regenerate_id(true);
        $_SESSION['jwt_token'] = $result['token'];
        $_SESSION['user'] = $result['user'] ?? [];
        $_SESSION['flash_success'] = $result['message'] ?? 'Your contractor account is ready for profile review.';
        header('Location: edit_profile.php');
        exit;
    }

    // Expired, revoked, and consumed links should not linger in the browser
    // session after a failed acceptance attempt.
    unset($_SESSION['contractor_invitation_token']);
    $error = apiMessage($result, 'TrustFix could not accept this contractor invitation.');
}

$pageTitle = 'Join as a Contractor';
include 'header.php';
?>

<section class="tf-section-block" style="max-width:720px;margin:36px auto;">
    <div class="tf-section-heading">
        <div>
            <h1>Join TrustFix as a contractor</h1>
            <p>Your secure invitation creates a contractor account and starts the verification process. You will be able to complete your profile next; job acceptance stays unavailable until TrustFix approves your contractor profile.</p>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="tf-alert tf-alert-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a class="tf-button" href="login.php">Return to sign in</a></p>
    <?php else: ?>
        <form method="POST">
            <?= csrfField() ?>
            <button type="submit" class="tf-button">Accept invitation and start profile</button>
        </form>
    <?php endif; ?>
</section>

<?php include 'footer.php'; ?>
