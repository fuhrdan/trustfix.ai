<?php
require 'config.php';
requireLogin();

$jobId = (int)($_GET['id'] ?? 0);
$job = apiRequest('GET', '/jobs/' . $jobId);
$stripeConfig = apiRequest('GET', '/payments/config');
$intent = apiRequest('POST', '/jobs/' . $jobId . '/payment-intent');

if (empty($intent['client_secret']) || empty($stripeConfig['publishable_key'])) {
    include 'header.php';
    echo '<div class="tf-alert tf-alert-error">' .
        htmlspecialchars($intent['message'] ?? $intent['error'] ?? 'Payment is not ready for this job.') .
        '</div><p><a href="job_workspace.php?id=' . $jobId . '">&larr; Return to job</a></p>';
    include 'footer.php';
    exit;
}

include 'header.php';
$returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' .
    $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') .
    '/payment_return.php?job_id=' . $jobId;
?>
<script src="https://js.stripe.com/v3/"></script>
<div style="max-width:650px;margin:0 auto">
    <p><a href="job_workspace.php?id=<?= $jobId ?>">&larr; Return to job</a></p>
    <div style="background:white;border:1px solid #ddd;border-radius:12px;padding:26px">
        <h1>Pay for Job #<?= $jobId ?></h1>
        <p><?= htmlspecialchars($job['address'] ?? '') ?></p>
        <p style="font-size:26px;font-weight:bold">$<?= number_format((float)($job['agreed_price'] ?? 0), 2) ?></p>
        <form id="payment-form">
            <div id="payment-element"></div>
            <div id="payment-message" style="color:#b42318;margin:15px 0"></div>
            <button id="submit" type="submit" style="margin-top:20px">Pay securely</button>
        </form>
    </div>
</div>
<script>
const stripe = Stripe(<?= json_encode($stripeConfig['publishable_key']) ?>);
const elements = stripe.elements({clientSecret: <?= json_encode($intent['client_secret']) ?>});
elements.create('payment').mount('#payment-element');
document.getElementById('payment-form').addEventListener('submit', async function(event) {
    event.preventDefault();
    const button = document.getElementById('submit');
    button.disabled = true;
    const result = await stripe.confirmPayment({
        elements: elements,
        confirmParams: {return_url: <?= json_encode($returnUrl) ?>}
    });
    if (result.error) {
        document.getElementById('payment-message').textContent = result.error.message;
        button.disabled = false;
    }
});
</script>
<?php include 'footer.php'; ?>
