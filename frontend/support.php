<?php

require 'config.php';
requireLogin();

$user = currentUser(true);
$categories = [
    'account' => 'Account access',
    'technical' => 'Technical problem',
    'payment' => 'Payment or billing',
    'job' => 'Job or project',
    'contractor' => 'Contractor concern',
    'safety' => 'Safety concern',
    'security' => 'Security or privacy',
    'other' => 'Something else',
];
$impacts = [
    'normal' => 'Normal — I can still use TrustFix',
    'high' => 'High — work or payment is blocked',
    'urgent' => 'Urgent — immediate attention is needed',
];
$form = [
    'category' => 'technical',
    'impact' => 'normal',
    'job_id' => '',
    'subject' => '',
    'description' => '',
];
$formError = '';
$casePage = max(1, (int)($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    foreach ($form as $key => $value) {
        $form[$key] = trim((string)($_POST[$key] ?? $value));
    }

    if (!isset($categories[$form['category']]) || !isset($impacts[$form['impact']])) {
        $formError = 'Choose a valid support category and impact level.';
    } elseif (mb_strlen($form['subject']) < 5 || mb_strlen($form['description']) < 20) {
        $formError = 'Add a short subject and at least 20 characters of detail.';
    } else {
        $payload = [
            'category' => $form['category'],
            'impact' => $form['impact'],
            'subject' => $form['subject'],
            'description' => $form['description'],
            'job_id' => $form['job_id'] !== '' ? (int)$form['job_id'] : null,
        ];
        $created = apiRequest('POST', '/support-cases', $payload);

        if ((int)($created['_http_code'] ?? 500) === 201 && !empty($created['case_number'])) {
            $_SESSION['flash_success'] = 'Support case ' . $created['case_number'] . ' was created.';
            header('Location: support.php');
            exit;
        }

        $formError = apiMessage($created, 'The support case could not be created. Please try again.');
    }
}

$jobsResponse = apiRequest('GET', '/jobs/my');
$jobs = is_array($jobsResponse) && array_is_list($jobsResponse) ? $jobsResponse : [];
$casesResponse = apiRequest('GET', '/support-cases/my?page=' . $casePage);
$cases = is_array($casesResponse['data'] ?? null) ? $casesResponse['data'] : [];
$caseCurrentPage = max(1, (int)($casesResponse['current_page'] ?? $casePage));
$caseLastPage = max(1, (int)($casesResponse['last_page'] ?? 1));
$caseTotal = max(0, (int)($casesResponse['total'] ?? count($cases)));
$casesLoadError = !isset($casesResponse['data'])
    ? apiMessage($casesResponse, 'Your support history is temporarily unavailable.')
    : '';

function tfSupportDate($value)
{
    if (empty($value)) {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('America/Denver'))
            ->format('M j, Y g:i A T');
    } catch (Throwable $exception) {
        return (string)$value;
    }
}

function tfSupportLabel($value)
{
    return ucwords(str_replace('_', ' ', (string)$value));
}

$pageTitle = 'Support';
include 'header.php';
?>

<div class="tf-page-heading">
    <div>
        <h1>TrustFix Support</h1>
        <p class="tf-page-intro">Tell us what is blocking you. Your request receives a case number, response target, and visible status.</p>
    </div>
    <span class="tf-count-badge"><?= $caseTotal ?> case<?= $caseTotal === 1 ? '' : 's' ?></span>
</div>

<div class="tf-safety-notice" role="note">
    <strong>Immediate danger?</strong>
    TrustFix support is not an emergency service. Call 911 or your local emergency service first, then create a safety case when you are safe.
</div>

<?php if ($formError !== ''): ?>
    <div class="tf-alert tf-alert-error" role="alert">
        <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="tf-support-layout">
    <section class="tf-card tf-support-form-card">
        <h2>Create a support case</h2>
        <p class="tf-muted">Safety and security requests are automatically escalated. Payment cases start at priority level 2.</p>

        <form method="POST" action="support.php">
            <?= csrfField() ?>

            <div class="tf-form-grid">
                <div>
                    <label for="support_category">What do you need help with?</label>
                    <select id="support_category" name="category" required>
                        <?php foreach ($categories as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $form['category'] === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="support_impact">How much is this affecting you?</label>
                    <select id="support_impact" name="impact" required>
                        <?php foreach ($impacts as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $form['impact'] === $value ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label for="support_job_id">Related job <span class="tf-muted">(optional)</span></label>
            <select id="support_job_id" name="job_id">
                <option value="">No related job</option>
                <?php foreach ($jobs as $job): ?>
                    <?php
                        $jobId = (int)($job['id'] ?? 0);
                        $jobDescription = trim((string)($job['initial_description'] ?? ''));
                        $jobDescription = mb_strimwidth($jobDescription, 0, 65, '…');
                    ?>
                    <option value="<?= $jobId ?>" <?= (string)$jobId === $form['job_id'] ? 'selected' : '' ?>>
                        Job #<?= $jobId ?> — <?= htmlspecialchars($jobDescription, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="support_subject">Subject</label>
            <input
                id="support_subject"
                type="text"
                name="subject"
                maxlength="180"
                value="<?= htmlspecialchars($form['subject'], ENT_QUOTES, 'UTF-8') ?>"
                placeholder="A short description of the problem"
                required
            >

            <label for="support_description">What happened?</label>
            <textarea
                id="support_description"
                name="description"
                minlength="20"
                maxlength="10000"
                placeholder="Include what you were trying to do, what happened, and any error message you saw. Do not include passwords or payment card numbers."
                required
            ><?= htmlspecialchars($form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>

            <button type="submit">Submit Support Case</button>
        </form>
    </section>

    <aside class="tf-card tf-response-guide">
        <h2>What happens next</h2>
        <ol class="tf-procedure-list">
            <li>
                <span>1</span>
                <div><strong>Level 1 — Standard</strong><small>Account and technical help. First response target: 24 hours.</small></div>
            </li>
            <li>
                <span>2</span>
                <div><strong>Level 2 — High</strong><small>Blocked jobs or payments. First response target: 4 hours.</small></div>
            </li>
            <li>
                <span>3</span>
                <div><strong>Level 3 — Urgent</strong><small>Safety or security. First response target: 1 hour.</small></div>
            </li>
        </ol>
        <p class="tf-muted">Targets are measured continuously. An overdue open case is moved to the next escalation level and administrators are alerted.</p>
    </aside>
</div>

<section class="tf-section-block">
    <h2>Your support cases</h2>

    <?php if ($casesLoadError !== ''): ?>
        <div class="tf-alert tf-alert-error" role="alert"><?= htmlspecialchars($casesLoadError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (empty($cases)): ?>
        <div class="tf-empty-state">
            <div class="tf-empty-state-icon" aria-hidden="true">?</div>
            <h3>No support cases yet</h3>
            <p>Your submitted requests and their current status will appear here.</p>
        </div>
    <?php else: ?>
        <div class="tf-support-case-list">
            <?php foreach ($cases as $case): ?>
                <?php
                    $severity = $case['severity'] ?? 'normal';
                    $status = $case['status'] ?? 'open';
                ?>
                <article class="tf-support-case">
                    <div class="tf-support-case-heading">
                        <div>
                            <span class="tf-case-number"><?= htmlspecialchars($case['case_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            <h3><?= htmlspecialchars($case['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <div class="tf-status-group">
                            <span class="tf-status-pill tf-status-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tfSupportLabel($severity), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="tf-status-pill tf-status-neutral"><?= htmlspecialchars(tfSupportLabel($status), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <p><?= nl2br(htmlspecialchars($case['description'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
                    <div class="tf-case-meta">
                        <span>Level <?= (int)($case['escalation_level'] ?? 1) ?></span>
                        <span>Opened <?= htmlspecialchars(tfSupportDate($case['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Response target <?= htmlspecialchars(tfSupportDate($case['first_response_due_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($caseLastPage > 1): ?>
            <nav class="tf-pagination" aria-label="Support case pages">
                <div class="tf-pagination-links">
                    <?php if ($caseCurrentPage > 1): ?>
                        <a class="tf-button tf-button-secondary" href="support.php?page=<?= $caseCurrentPage - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $caseCurrentPage ?> of <?= $caseLastPage ?></span>
                    <?php if ($caseCurrentPage < $caseLastPage): ?>
                        <a class="tf-button tf-button-secondary" href="support.php?page=<?= $caseCurrentPage + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php include 'footer.php'; ?>
