<?php

require 'config.php';
requireRole('admin');

$supportStatus = trim((string)($_GET['support_status'] ?? ''));
$supportSeverity = trim((string)($_GET['support_severity'] ?? ''));
$supportPage = max(1, (int)($_GET['support_page'] ?? 1));
$auditAction = trim((string)($_GET['audit_action'] ?? ''));
$auditPage = max(1, (int)($_GET['audit_page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();

    $caseId = (int)($_POST['case_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $level = (int)($_POST['escalation_level'] ?? 1);
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    $allowedStatuses = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    if ($caseId <= 0 || !in_array($status, $allowedStatuses, true) || $level < 1 || $level > 3) {
        $_SESSION['flash_error'] = 'The support case update was invalid.';
    } else {
        $response = apiRequest('PATCH', '/admin/support-cases/' . $caseId, [
            'status' => $status,
            'escalation_level' => $level,
            'admin_notes' => $notes !== '' ? $notes : null,
        ]);
        $responseCode = (int)($response['_http_code'] ?? 500);

        if ($responseCode >= 200 && $responseCode < 300) {
            $_SESSION['flash_success'] = 'Support case ' . ($response['case_number'] ?? '#' . $caseId) . ' was updated.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The support case could not be updated.');
        }
    }

    $returnParams = array_filter([
        'support_status' => $_POST['return_support_status'] ?? '',
        'support_severity' => $_POST['return_support_severity'] ?? '',
        'support_page' => max(1, (int)($_POST['return_support_page'] ?? 1)),
        'audit_action' => $_POST['return_audit_action'] ?? '',
        'audit_page' => max(1, (int)($_POST['return_audit_page'] ?? 1)),
    ], static fn($value) => $value !== '');
    header('Location: admin_operations.php?' . http_build_query($returnParams));
    exit;
}

$summary = apiRequest('GET', '/admin/operations/summary');
$summaryError = empty($summary['server_time'])
    ? apiMessage($summary, 'Operations status is temporarily unavailable.')
    : '';

$supportQuery = ['page' => $supportPage];
if ($supportStatus !== '') {
    $supportQuery['status'] = $supportStatus;
}
if ($supportSeverity !== '') {
    $supportQuery['severity'] = $supportSeverity;
}
$supportResponse = apiRequest('GET', '/admin/support-cases?' . http_build_query($supportQuery));
$supportCases = is_array($supportResponse['data'] ?? null) ? $supportResponse['data'] : [];
$supportLastPage = max(1, (int)($supportResponse['last_page'] ?? 1));
$supportTotal = max(0, (int)($supportResponse['total'] ?? count($supportCases)));

$auditQuery = ['page' => $auditPage];
if ($auditAction !== '') {
    $auditQuery['action'] = $auditAction;
}
$auditResponse = apiRequest('GET', '/admin/audit-logs?' . http_build_query($auditQuery));
$auditLogs = is_array($auditResponse['data'] ?? null) ? $auditResponse['data'] : [];
$auditLastPage = max(1, (int)($auditResponse['last_page'] ?? 1));
$auditTotal = max(0, (int)($auditResponse['total'] ?? count($auditLogs)));

function tfOpsDate($value)
{
    if (empty($value)) {
        return 'Not recorded';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('America/Denver'))
            ->format('M j, Y g:i A T');
    } catch (Throwable $exception) {
        return (string)$value;
    }
}

function tfOpsLabel($value)
{
    return ucwords(str_replace('_', ' ', (string)$value));
}

function tfOpsUrl($overrides = [])
{
    global $supportStatus, $supportSeverity, $supportPage, $auditAction, $auditPage;

    return 'admin_operations.php?' . http_build_query(array_filter(array_merge([
        'support_status' => $supportStatus,
        'support_severity' => $supportSeverity,
        'support_page' => $supportPage,
        'audit_action' => $auditAction,
        'audit_page' => $auditPage,
    ], $overrides), static fn($value) => $value !== ''));
}

$backup = is_array($summary['backup'] ?? null) ? $summary['backup'] : [];
$latestBackup = is_array($backup['latest'] ?? null) ? $backup['latest'] : [];
$monitoring = is_array($summary['monitoring'] ?? null) ? $summary['monitoring'] : [];
$supportSummary = is_array($summary['support'] ?? null) ? $summary['support'] : [];
$auditSummary = is_array($summary['audit'] ?? null) ? $summary['audit'] : [];
$statusOptions = [
    '' => 'All statuses',
    'open' => 'Open',
    'in_progress' => 'In progress',
    'waiting_customer' => 'Waiting for customer',
    'resolved' => 'Resolved',
    'closed' => 'Closed',
];
$severityOptions = [
    '' => 'All priorities',
    'normal' => 'Normal',
    'high' => 'High',
    'urgent' => 'Urgent',
];

$pageTitle = 'Operations';
$pageContainerClass = 'tf-container-wide';
include 'header.php';
?>

<div class="tf-page-heading">
    <div>
        <h1>Operations &amp; Support</h1>
        <p class="tf-page-intro">One view for database protection, service availability, support response, and administrator accountability.</p>
    </div>
    <span class="tf-count-badge">Administrator only</span>
</div>

<?php if ($summaryError !== ''): ?>
    <div class="tf-alert tf-alert-error" role="alert"><?= htmlspecialchars($summaryError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section aria-labelledby="operations-health-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="operations-health-heading">Operational health</h2>
            <p>Green means the scheduled task has reported within its expected window.</p>
        </div>
        <span class="tf-muted">Server time: <?= htmlspecialchars(tfOpsDate($summary['server_time'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="tf-operations-grid">
        <article class="tf-metric-card">
            <div class="tf-metric-heading">
                <span class="tf-health-dot <?= !empty($backup['healthy']) ? 'is-healthy' : 'is-warning' ?>" aria-hidden="true"></span>
                <h3>Daily backup</h3>
            </div>
            <strong class="tf-metric-value"><?= !empty($backup['healthy']) ? 'Protected' : (!empty($latestBackup) ? 'Attention' : 'Pending first run') ?></strong>
            <p><?= htmlspecialchars($latestBackup['summary'] ?? 'No backup run has been recorded.', ENT_QUOTES, 'UTF-8') ?></p>
            <dl class="tf-compact-details">
                <div><dt>Last run</dt><dd><?= htmlspecialchars(tfOpsDate($latestBackup['started_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Retention</dt><dd><?= (int)($backup['retention_days'] ?? 0) ?> days</dd></div>
                <?php if (!empty($latestBackup['artifact_size_bytes'])): ?>
                    <div><dt>Size</dt><dd><?= number_format(((int)$latestBackup['artifact_size_bytes']) / 1048576, 2) ?> MB</dd></div>
                <?php endif; ?>
            </dl>
        </article>

        <article class="tf-metric-card">
            <div class="tf-metric-heading">
                <span class="tf-health-dot <?= !empty($monitoring['scheduler_healthy']) ? 'is-healthy' : 'is-warning' ?>" aria-hidden="true"></span>
                <h3>Monitoring scheduler</h3>
            </div>
            <strong class="tf-metric-value"><?= !empty($monitoring['scheduler_healthy']) ? 'Reporting' : 'Stale or pending' ?></strong>
            <p>The web and API endpoints are checked every five minutes.</p>
            <dl class="tf-compact-details">
                <div><dt>Last run</dt><dd><?= htmlspecialchars(tfOpsDate($monitoring['latest_run']['started_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Result</dt><dd><?= htmlspecialchars(tfOpsLabel($monitoring['latest_run']['status'] ?? 'pending'), ENT_QUOTES, 'UTF-8') ?></dd></div>
            </dl>
        </article>

        <article class="tf-metric-card">
            <div class="tf-metric-heading">
                <span class="tf-health-dot <?= ((int)($supportSummary['overdue'] ?? 0) === 0) ? 'is-healthy' : 'is-danger' ?>" aria-hidden="true"></span>
                <h3>Support queue</h3>
            </div>
            <strong class="tf-metric-value"><?= (int)($supportSummary['open'] ?? 0) ?> open</strong>
            <p><?= (int)($supportSummary['urgent_open'] ?? 0) ?> urgent · <?= (int)($supportSummary['overdue'] ?? 0) ?> overdue</p>
        </article>

        <article class="tf-metric-card">
            <div class="tf-metric-heading">
                <span class="tf-health-dot is-neutral" aria-hidden="true"></span>
                <h3>Administrator audit</h3>
            </div>
            <strong class="tf-metric-value"><?= (int)($auditSummary['events_24h'] ?? 0) ?> events</strong>
            <p>Recorded during the last 24 hours. Retained for <?= (int)($auditSummary['retention_days'] ?? 0) ?> days.</p>
        </article>
    </div>

    <div class="tf-monitor-grid">
        <?php foreach (($monitoring['targets'] ?? []) as $target): ?>
            <?php $targetStatus = $target['status'] ?? 'pending'; ?>
            <article class="tf-monitor-card">
                <div class="tf-monitor-card-heading">
                    <div>
                        <span class="tf-eyebrow">Uptime target</span>
                        <h3><?= htmlspecialchars($target['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                    <span class="tf-status-pill tf-status-<?= $targetStatus === 'up' ? 'normal' : ($targetStatus === 'down' ? 'urgent' : 'neutral') ?>">
                        <?= htmlspecialchars(tfOpsLabel($targetStatus), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <p class="tf-monitor-url"><?= htmlspecialchars($target['url'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                <div class="tf-monitor-stats">
                    <span><strong><?= $target['uptime_percent_24h'] === null ? '—' : number_format((float)$target['uptime_percent_24h'], 2) . '%' ?></strong>24-hour checks</span>
                    <span><strong><?= isset($target['response_time_ms']) ? (int)$target['response_time_ms'] . ' ms' : '—' ?></strong>latest response</span>
                    <span><strong><?= (int)($target['status_code'] ?? 0) ?: '—' ?></strong>HTTP status</span>
                </div>
                <p class="tf-muted">Checked <?= htmlspecialchars(tfOpsDate($target['checked_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></p>
                <?php if (!empty($target['error_message'])): ?>
                    <p class="tf-inline-error"><?= htmlspecialchars($target['error_message'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="tf-section-block" aria-labelledby="support-queue-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="support-queue-heading">Support &amp; escalation queue</h2>
            <p><?= $supportTotal ?> total case<?= $supportTotal === 1 ? '' : 's' ?>. Updating a case assigns it to you and notifies the customer when the status changes.</p>
        </div>
    </div>

    <div class="tf-runbook-grid">
        <div><span>Level 1</span><strong>Standard</strong><small>Account and technical issues. Respond within 24 hours.</small></div>
        <div><span>Level 2</span><strong>High</strong><small>Blocked work or payments. Respond within 4 hours.</small></div>
        <div><span>Level 3</span><strong>Urgent</strong><small>Safety or security. Respond within 1 hour; emergency services come first.</small></div>
    </div>

    <form class="tf-filter-bar" method="GET">
        <div>
            <label for="support_status">Status</label>
            <select id="support_status" name="support_status">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $supportStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="support_severity">Priority</label>
            <select id="support_severity" name="support_severity">
                <?php foreach ($severityOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $supportSeverity === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="audit_action" value="<?= htmlspecialchars($auditAction, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filter Cases</button>
        <a href="admin_operations.php">Clear</a>
    </form>

    <?php if (empty($supportCases)): ?>
        <div class="tf-empty-state"><h3>No support cases match this filter</h3></div>
    <?php else: ?>
        <div class="tf-support-admin-list">
            <?php foreach ($supportCases as $case): ?>
                <?php
                    $severity = $case['severity'] ?? 'normal';
                    $status = $case['status'] ?? 'open';
                ?>
                <article class="tf-support-admin-card">
                    <div class="tf-support-case-heading">
                        <div>
                            <span class="tf-case-number"><?= htmlspecialchars($case['case_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            <h3><?= htmlspecialchars($case['subject'] ?? '', ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="tf-muted">
                                <?= htmlspecialchars($case['user']['name'] ?? 'Unknown user', ENT_QUOTES, 'UTF-8') ?> ·
                                <a href="mailto:<?= htmlspecialchars($case['user']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($case['user']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></a>
                            </p>
                        </div>
                        <div class="tf-status-group">
                            <span class="tf-status-pill tf-status-<?= htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(tfOpsLabel($severity), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="tf-status-pill tf-status-neutral"><?= htmlspecialchars(tfOpsLabel($status), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <p><?= nl2br(htmlspecialchars($case['description'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>

                    <div class="tf-case-meta">
                        <span>Category: <?= htmlspecialchars(tfOpsLabel($case['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Opened: <?= htmlspecialchars(tfOpsDate($case['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Response due: <?= htmlspecialchars(tfOpsDate($case['first_response_due_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                        <span>Resolution due: <?= htmlspecialchars(tfOpsDate($case['resolution_due_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($case['job_id'])): ?><span>Job #<?= (int)$case['job_id'] ?></span><?php endif; ?>
                    </div>

                    <form class="tf-support-admin-form" method="POST" action="admin_operations.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="case_id" value="<?= (int)($case['id'] ?? 0) ?>">
                        <input type="hidden" name="return_support_status" value="<?= htmlspecialchars($supportStatus, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_support_severity" value="<?= htmlspecialchars($supportSeverity, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_support_page" value="<?= $supportPage ?>">
                        <input type="hidden" name="return_audit_action" value="<?= htmlspecialchars($auditAction, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="return_audit_page" value="<?= $auditPage ?>">

                        <div>
                            <label for="case_status_<?= (int)$case['id'] ?>">Status</label>
                            <select id="case_status_<?= (int)$case['id'] ?>" name="status">
                                <?php foreach (array_slice($statusOptions, 1, null, true) as $value => $label): ?>
                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $status === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="case_level_<?= (int)$case['id'] ?>">Escalation level</label>
                            <select id="case_level_<?= (int)$case['id'] ?>" name="escalation_level">
                                <?php for ($level = 1; $level <= 3; $level++): ?>
                                    <option value="<?= $level ?>" <?= (int)($case['escalation_level'] ?? 1) === $level ? 'selected' : '' ?>>Level <?= $level ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="tf-support-notes-field">
                            <label for="case_notes_<?= (int)$case['id'] ?>">Internal notes</label>
                            <textarea id="case_notes_<?= (int)$case['id'] ?>" name="admin_notes" maxlength="5000" placeholder="Document the response, next owner, or resolution."><?= htmlspecialchars($case['admin_notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <button type="submit">Save Case</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($supportLastPage > 1): ?>
        <nav class="tf-pagination" aria-label="Support case pages">
            <div class="tf-pagination-links">
                <?php if ($supportPage > 1): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfOpsUrl(['support_page' => $supportPage - 1]), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
                <span>Page <?= $supportPage ?> of <?= $supportLastPage ?></span>
                <?php if ($supportPage < $supportLastPage): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfOpsUrl(['support_page' => $supportPage + 1]), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>

<section class="tf-section-block" id="audit-log" aria-labelledby="audit-log-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="audit-log-heading">Administrator audit log</h2>
            <p><?= $auditTotal ?> recorded state-changing action<?= $auditTotal === 1 ? '' : 's' ?>. Passwords, messages, case descriptions, and notes are redacted.</p>
        </div>
    </div>

    <form class="tf-filter-bar" method="GET" action="admin_operations.php#audit-log">
        <div class="tf-filter-grow">
            <label for="audit_action">Filter by action</label>
            <input id="audit_action" type="search" name="audit_action" maxlength="180" value="<?= htmlspecialchars($auditAction, ENT_QUOTES, 'UTF-8') ?>" placeholder="Example: users.update_user">
        </div>
        <input type="hidden" name="support_status" value="<?= htmlspecialchars($supportStatus, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="support_severity" value="<?= htmlspecialchars($supportSeverity, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filter Log</button>
        <?php if ($auditAction !== ''): ?><a href="<?= htmlspecialchars(tfOpsUrl(['audit_action' => '', 'audit_page' => 1]) . '#audit-log', ENT_QUOTES, 'UTF-8') ?>">Clear</a><?php endif; ?>
    </form>

    <div class="tf-table-wrap">
        <table class="tf-audit-table">
            <caption class="tf-sr-only">Administrator audit events</caption>
            <thead>
                <tr>
                    <th scope="col">Time</th>
                    <th scope="col">Administrator</th>
                    <th scope="col">Action</th>
                    <th scope="col">Resource</th>
                    <th scope="col">Result</th>
                    <th scope="col">Source</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditLogs)): ?>
                    <tr><td colspan="6">No audit events match this filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($auditLogs as $event): ?>
                    <tr>
                        <td><?= htmlspecialchars(tfOpsDate($event['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <strong><?= htmlspecialchars($event['administrator']['name'] ?? 'Deleted administrator', ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <span class="tf-muted"><?= htmlspecialchars($event['administrator']['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td><code><?= htmlspecialchars($event['action'] ?? '', ENT_QUOTES, 'UTF-8') ?></code><br><span class="tf-muted"><?= htmlspecialchars($event['http_method'] ?? '', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($event['route_path'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars(tfOpsLabel($event['resource_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?><?= !empty($event['resource_id']) ? ' #' . htmlspecialchars($event['resource_id'], ENT_QUOTES, 'UTF-8') : '' ?></td>
                        <td><span class="tf-status-pill <?= (int)($event['status_code'] ?? 500) < 400 ? 'tf-status-normal' : 'tf-status-urgent' ?>">HTTP <?= (int)($event['status_code'] ?? 0) ?></span></td>
                        <td><?= htmlspecialchars($event['ip_address'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?><br><span class="tf-muted"><?= htmlspecialchars(mb_strimwidth($event['user_agent'] ?? '', 0, 70, '…'), ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($auditLastPage > 1): ?>
        <nav class="tf-pagination" aria-label="Audit log pages">
            <div class="tf-pagination-links">
                <?php if ($auditPage > 1): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfOpsUrl(['audit_page' => $auditPage - 1]) . '#audit-log', ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
                <span>Page <?= $auditPage ?> of <?= $auditLastPage ?></span>
                <?php if ($auditPage < $auditLastPage): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfOpsUrl(['audit_page' => $auditPage + 1]) . '#audit-log', ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>

<?php include 'footer.php'; ?>
