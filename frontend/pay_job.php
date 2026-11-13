<?php

require __DIR__ . '/config.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
requireLogin();

$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
if (!$secure || !$verifyApiSsl) {
    renderFrontendError(403, 'Secure checkout unavailable', 'Open this page using the secure TrustFix address.');
}

$jobId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($jobId === false) {
    renderFrontendError(400, 'Missing job', 'Choose a job before opening payment terms.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    renderFrontendError(405, 'Method unavailable', 'Open the payment page from the job workspace.');
}
if ($method === 'POST') {
    requireValidCsrf();
}

$user = currentUser(true);
$actorId = (int) ($user['id'] ?? 0);
$jobPath = '/jobs/' . $jobId;
$job = apiRequest('GET', $jobPath);
if ($actorId < 1 || (int) ($job['id'] ?? 0) !== $jobId) {
    renderFrontendError(404, 'Job unavailable', 'This job is unavailable or you cannot view it.');
}
$isCustomer = (int) ($job['customer_id'] ?? 0) === $actorId;
if (!$isCustomer && (int) ($job['handyman_id'] ?? 0) !== $actorId) {
    renderFrontendError(403, 'Payment terms unavailable', 'Only the customer and assigned contractor can review these terms.');
}

$config = apiRequest('GET', '/payments/config');
$publishableKey = $config['publishable_key'] ?? null;
$enabled = ($config['enabled'] ?? false) === true && is_string($publishableKey)
    && preg_match('/\Apk_test_[A-Za-z0-9_-]+\z/', $publishableKey) === 1;
$agreement = null;
$checkout = null;
$message = null;
$error = null;
$review = false;
$action = $_POST['action'] ?? null;

if ($method === 'POST') {
    if (!in_array($action, ['review', 'accept', 'revoke', 'checkout'], true)) {
        renderFrontendError(400, 'Invalid payment action', 'Refresh the payment page and try again.');
    }
    if (!$enabled && $action !== 'revoke') {
        $error = 'Payment checkout is currently unavailable.';
    } elseif ($action === 'review') {
        $review = true;
    } else {
        $agreementId = filter_var($_POST['agreement_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $hash = $_POST['agreement_sha256'] ?? null;
        if ($agreementId === false || !is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            renderFrontendError(400, 'Invalid payment agreement', 'Refresh the terms before continuing.');
        }
        if ($action === 'checkout') {
            if (!$isCustomer) {
                renderFrontendError(403, 'Customer checkout required', 'Only the customer can open checkout.');
            }
            $checkout = apiRequest('POST', $jobPath . '/payment-intent', [
                'agreement_id' => $agreementId, 'agreement_sha256' => $hash,
            ]);
            if (empty($checkout['checkout_status'])) {
                $error = apiMessage($checkout, 'Checkout is temporarily unavailable.');
            }
        } else {
            $key = $_POST['decision_key'] ?? null;
            if (!is_string($key) || preg_match('/\A[a-f0-9]{32}\z/', $key) !== 1) {
                renderFrontendError(400, 'Invalid decision', 'Refresh the terms before continuing.');
            }
            $decision = apiRequest('POST', '/payment-agreements/' . $agreementId . '/decisions', [
                'agreement_sha256' => $hash,
                'event_type' => $action === 'accept' ? 'scope_accepted' : 'consent_revoked',
                'decision_key' => $key,
            ]);
            if (isset($decision['decision']['id'])) {
                $message = $action === 'accept' ? 'Your acceptance was recorded.' : 'Your acceptance was revoked.';
            } else {
                $error = apiMessage($decision, 'The decision could not be recorded.');
            }
            $review = $enabled;
        }
    }
}

if ($review) {
    $proposal = apiRequest('POST', $jobPath . '/payment-agreement');
    $agreement = $proposal['agreement'] ?? null;
    if (!is_array($agreement)) {
        $agreement = null;
        $error ??= apiMessage($proposal, 'Payment terms are temporarily unavailable.');
    }
}
if ($isCustomer && empty($checkout['checkout_status'])) {
    $status = apiRequest('GET', $jobPath . '/payment-intent');
    if (is_string($status['checkout_status'] ?? null)) {
        $checkout = $status;
    } elseif ($enabled && ($status['_http_code'] ?? null) !== 404) {
        $error ??= apiMessage($status, 'Payment status is temporarily unavailable.');
    }
}

$state = is_string($checkout['checkout_status'] ?? null) ? $checkout['checkout_status'] : null;
$secret = $isCustomer && $method === 'POST' && $action === 'checkout' && $state === 'ready'
    ? ($checkout['client_secret'] ?? null) : null;
$resume = ($checkout['stripe_status'] ?? null) === 'requires_action';
$states = [
    'ready' => $resume ? 'Stripe requires verification. Open secure checkout to continue.' : 'Checkout is ready.',
    'pending' => 'Checkout is being prepared. Review the terms to try again.',
    'busy' => 'Checkout is being prepared. Wait a moment, then refresh payment status.',
    'processing' => 'Stripe is processing the payment.',
    'awaiting_confirmation' => 'TrustFix is waiting for Stripe to confirm the payment.',
    'awaiting_cancellation' => 'TrustFix is waiting for Stripe to confirm the cancellation.',
    'succeeded' => 'TrustFix confirmed your payment.',
    'cancelled' => 'This payment was cancelled.',
    'refunded' => 'This payment was refunded.',
    'reconciliation_required' => 'This checkout needs review. Contact TrustFix support.',
];
$blocked = in_array($state, [
    'busy', 'processing', 'awaiting_confirmation', 'awaiting_cancellation',
    'succeeded', 'cancelled', 'refunded', 'reconciliation_required',
], true);
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn ($cents): string => '$' . number_format((int) $cents / 100, 2);
$pageTitle = 'Payment Agreement';
include __DIR__ . '/header.php';
?>
<section style="max-width:650px;margin:0 auto">
    <p><a href="job_workspace.php?id=<?= $jobId ?>">&larr; Return to job</a></p>
    <h1>Payment agreement for job #<?= $jobId ?></h1>
    <?php if ($error !== null) { ?>
        <p class="tf-alert tf-alert-error" role="alert"><?= $escape($error) ?></p>
    <?php } ?>
    <?php if ($message !== null) { ?>
        <p role="status"><?= $escape($message) ?></p>
    <?php } ?>
    <?php if (isset($states[$state])) { ?>
        <p role="status"><?= $escape($states[$state]) ?></p>
    <?php } ?>
    <?php if (($checkout['payment_failed'] ?? false) === true) { ?>
        <p class="tf-alert tf-alert-error">The last payment attempt did not complete. You can try another payment method.</p>
    <?php } ?>
    <?php if ($isCustomer) { ?>
        <p><a href="pay_job.php?id=<?= $jobId ?>">Refresh payment status</a></p>
    <?php } ?>

    <?php if ($enabled && is_string($secret) && $secret !== '') { ?>
        <p><strong><?= $escape($money($checkout['payment']['amount_cents'])) ?> USD</strong></p>
        <form id="payment-form" method="post"
              data-key="<?= $escape($publishableKey) ?>"
              data-secret="<?= $escape($secret) ?>"
              data-resume="<?= $resume ? 'true' : 'false' ?>"
              data-status-url="pay_job.php?id=<?= $jobId ?>">
            <div id="payment-element"></div>
            <p id="payment-message" role="alert"></p>
            <button id="payment-submit" type="submit" disabled><?= $resume ? 'Continue verification' : 'Pay securely' ?></button>
        </form>
        <script src="https://js.stripe.com/v3/"></script>
        <script>
        (() => {
            const form = document.getElementById('payment-form');
            const submit = document.getElementById('payment-submit');
            const message = document.getElementById('payment-message');
            if (window.location.protocol !== 'https:' || typeof Stripe !== 'function') {
                message.textContent = 'Secure checkout is temporarily unavailable.';
                return;
            }
            const stripe = Stripe(form.dataset.key);
            const resume = form.dataset.resume === 'true';
            let elements;
            if (!resume) {
                elements = stripe.elements({clientSecret: form.dataset.secret});
                elements.create('payment').mount('#payment-element');
            }
            submit.disabled = false;
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                submit.disabled = true;
                message.textContent = '';
                try {
                    const result = resume
                        ? await stripe.handleNextAction({clientSecret: form.dataset.secret})
                        : await stripe.confirmPayment({elements, redirect: 'if_required'});
                    if (result.error) {
                        message.textContent = result.error.message || 'The payment could not be completed.';
                    } else {
                        window.location.assign(form.dataset.statusUrl);
                        return;
                    }
                } catch {
                    message.textContent = 'Checkout was interrupted. Check payment status before trying again.';
                }
                submit.disabled = false;
            });
        })();
        </script>
    <?php } elseif ($agreement !== null) { ?>
        <?php
            $scope = $agreement['scope'] ?? [];
            $consents = $agreement['consents'] ?? [];
            $accepted = ($consents[$isCustomer ? 'customer' : 'contractor'] ?? false) === true;
            $bothAccepted = ($consents['customer'] ?? false) === true && ($consents['contractor'] ?? false) === true;
            $reserved = ($agreement['checkout_reserved'] ?? false) === true;
            $skills = array_filter((array) ($scope['skills'] ?? []), 'is_string');
        ?>
        <h2>Terms version <?= (int) $agreement['version'] ?></h2>
        <p><strong>Total due upfront</strong> <?= $escape($money($agreement['amount_cents'])) ?> USD</p>
        <p><strong>Platform fee deducted from contractor proceeds</strong>
            <?= $escape($money($agreement['platform_fee_cents'])) ?> USD</p>
        <p><?= $escape($scope['address'] ?? '') ?></p>
        <p style="white-space:pre-wrap"><?= $escape($scope['description'] ?? '') ?></p>
        <?php if ($skills !== []) { ?>
            <p><strong>Skills</strong> <?= $escape(implode(', ', $skills)) ?></p>
        <?php } ?>
        <?php if (!empty($scope['change_orders'])) { ?>
            <h3>Approved changes included in this total</h3>
            <ul>
                <?php foreach ($scope['change_orders'] as $change) { ?>
                    <li><?= $escape($change['description']) ?> (<?= $escape($money($change['price_delta_cents'])) ?>)</li>
                <?php } ?>
            </ul>
        <?php } ?>
        <p>Customer <?= !empty($consents['customer']) ? 'accepted' : 'awaiting acceptance' ?>.
            Contractor <?= !empty($consents['contractor']) ? 'accepted' : 'awaiting acceptance' ?>.</p>
        <form method="post" action="pay_job.php?id=<?= $jobId ?>">
            <?= csrfField() ?>
            <input type="hidden" name="agreement_id" value="<?= (int) $agreement['id'] ?>">
            <input type="hidden" name="agreement_sha256" value="<?= $escape($agreement['agreement_sha256']) ?>">
            <input type="hidden" name="decision_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <?php if (!$reserved) { ?>
                <button type="submit" name="action" value="<?= $accepted ? 'revoke' : 'accept' ?>">
                    <?= $accepted ? 'Revoke my acceptance' : 'Accept these terms' ?>
                </button>
            <?php } ?>
            <?php if ($isCustomer && $bothAccepted && !$blocked) { ?>
                <button type="submit" name="action" value="checkout">Open secure checkout</button>
            <?php } ?>
        </form>
    <?php } ?>

    <?php if ($enabled && $secret === null && !in_array($state, ['succeeded', 'cancelled', 'refunded'], true)) { ?>
        <form method="post" action="pay_job.php?id=<?= $jobId ?>">
            <?= csrfField() ?>
            <button type="submit" name="action" value="review"><?= $agreement ? 'Refresh payment terms' : 'Review payment terms' ?></button>
        </form>
    <?php } elseif (!$enabled && $state === null && $error === null) { ?>
        <p>Payment checkout is currently unavailable.</p>
    <?php } ?>
</section>
<?php include __DIR__ . '/footer.php'; ?>
