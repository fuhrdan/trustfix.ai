<?php

require 'config.php';
requireRole('admin');

$jobId = filter_input(INPUT_GET, 'job_id', FILTER_VALIDATE_INT);
if (!$jobId || $jobId < 1) {
    http_response_code(400);
    exit('A valid job is required.');
}

$result = apiRequest('GET', '/admin/jobs/' . $jobId . '/payment-release');
$loadError = (($result['_http_code'] ?? 500) !== 200)
    ? apiMessage($result, 'Unable to load the payment release record.')
    : '';
$payment = is_array($result['payment'] ?? null) ? $result['payment'] : null;
$contractor = is_array($result['contractor'] ?? null) ? $result['contractor'] : null;
$release = is_array($result['release'] ?? null) ? $result['release'] : [
    'state' => 'unavailable',
    'can_release' => false,
    'reason' => 'Payment release information is unavailable.',
    'stripe_transfer_id' => null,
];
$notice = $_GET['result'] ?? '';

function adminPaymentMoney(int $cents, string $currency): string
{
    return strtoupper($currency) . ' ' . number_format($cents / 100, 2);
}

$pageTitle = 'Payment Release';
include 'header.php';

?>

<div class="tf-page-heading">
    <div>
        <h1>Payment release for job #<?= (int)$jobId ?></h1>
        <p class="tf-page-intro">Review verified funding and manually release only the recorded contractor proceeds.</p>
    </div>
    <a href="manage_jobs.php">Back to jobs</a>
</div>

<?php if ($notice === 'completed'): ?>
    <div class="tf-alert tf-alert-success" role="status">
        Contractor proceeds were released successfully.
    </div>
<?php elseif ($notice === 'pending'): ?>
    <div class="tf-alert" role="status">
        Stripe did not confirm the release. The same idempotent request can be retried.
    </div>
<?php elseif ($notice === 'failed'): ?>
    <div class="tf-alert tf-alert-error" role="alert">
        The release was not completed. Review the current release status below.
    </div>
<?php endif; ?>

<?php if ($loadError !== ''): ?>
    <div class="tf-alert tf-alert-error" role="alert">
        <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php else: ?>
    <div class="tf-card" style="max-width:760px;">
        <h2>Release status</h2>
        <dl style="display:grid;grid-template-columns:minmax(180px,1fr) 2fr;gap:10px 18px;">
            <dt>Job status</dt>
            <dd><?= htmlspecialchars((string)($result['job_status'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8') ?></dd>
            <dt>Release state</dt>
            <dd><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$release['state'])), ENT_QUOTES, 'UTF-8') ?></strong></dd>
            <dt>Contractor</dt>
            <dd><?= htmlspecialchars((string)($contractor['name'] ?? 'Unavailable'), ENT_QUOTES, 'UTF-8') ?></dd>
            <?php if ($payment !== null): ?>
                <dt>Customer paid</dt>
                <dd><?= htmlspecialchars(adminPaymentMoney((int)$payment['amount_cents'], (string)$payment['currency']), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>TrustFix platform fee</dt>
                <dd><?= htmlspecialchars(adminPaymentMoney((int)$payment['platform_fee_cents'], (string)$payment['currency']), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Contractor proceeds</dt>
                <dd><strong><?= htmlspecialchars(adminPaymentMoney((int)$payment['contractor_proceeds_cents'], (string)$payment['currency']), ENT_QUOTES, 'UTF-8') ?></strong></dd>
            <?php endif; ?>
        </dl>

        <p><?= htmlspecialchars((string)$release['reason'], ENT_QUOTES, 'UTF-8') ?></p>

        <?php if (!empty($release['stripe_transfer_id'])): ?>
            <p><strong>Stripe transfer:</strong>
                <?= htmlspecialchars((string)$release['stripe_transfer_id'], ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($release['can_release']) && $payment !== null): ?>
            <form
                method="POST"
                action="release_payment.php"
                onsubmit="return confirm('Release the displayed contractor proceeds now? This creates a Stripe transfer and cannot be undone here.');"
            >
                <?= csrfField() ?>
                <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                <label style="display:flex;gap:10px;align-items:flex-start;margin:18px 0;">
                    <input
                        type="checkbox"
                        name="confirmation"
                        value="release_contractor_proceeds"
                        required
                        style="width:auto;margin-top:4px;"
                    >
                    <span>I verified the completed job, payment, dispute status, and contractor payout account. Release
                        <strong><?= htmlspecialchars(adminPaymentMoney((int)$payment['contractor_proceeds_cents'], (string)$payment['currency']), ENT_QUOTES, 'UTF-8') ?></strong>
                        to this contractor.
                    </span>
                </label>
                <button type="submit" style="width:auto;">
                    <?= $release['state'] === 'pending' ? 'Retry release' : 'Release contractor proceeds' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>