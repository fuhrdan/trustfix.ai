<?php

require 'config.php';
require 'login_security_client.php';
requireRole('admin');

$ipFilter = trim((string)($_GET['ip'] ?? ''));
$emailFilter = trim((string)($_GET['email'] ?? ''));
$resultFilter = trim((string)($_GET['result'] ?? ''));
$riskFilter = trim((string)($_GET['risk'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
    $action = (string)($_POST['security_action'] ?? '');

    if ($action === 'block') {
        $ipAddress = trim((string)($_POST['ip_address'] ?? ''));
        $duration = (string)($_POST['duration'] ?? '24h');
        $reason = trim((string)($_POST['reason'] ?? ''));

        $response = apiRequest('POST', '/admin/login-security/blocked-ips', array_merge([
            'ip_address' => $ipAddress,
            'duration' => $duration,
            'reason' => $reason !== '' ? $reason : 'Blocked from the Login Security dashboard.',
        ], trustFixLoginSecurityClientContext()));

        $code = (int)($response['_http_code'] ?? 500);
        if ($code >= 200 && $code < 300) {
            $_SESSION['flash_success'] = 'IP address ' . $ipAddress . ' was blocked.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The IP address could not be blocked.');
        }
    } elseif ($action === 'unblock') {
        $blockId = (int)($_POST['block_id'] ?? 0);
        $response = apiRequest('DELETE', '/admin/login-security/blocked-ips/' . $blockId);
        $code = (int)($response['_http_code'] ?? 500);

        if ($code >= 200 && $code < 300) {
            $_SESSION['flash_success'] = 'IP address unblocked.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The IP address could not be unblocked.');
        }
    }

    $returnQuery = array_filter([
        'ip' => $_POST['return_ip'] ?? '',
        'email' => $_POST['return_email'] ?? '',
        'result' => $_POST['return_result'] ?? '',
        'risk' => $_POST['return_risk'] ?? '',
        'page' => max(1, (int)($_POST['return_page'] ?? 1)),
    ], static fn($value) => $value !== '');

    header('Location: admin_login_security.php?' . http_build_query($returnQuery));
    exit;
}

$summary = apiRequest('GET', '/admin/login-security/summary');

$query = ['page' => $page];
if ($ipFilter !== '') { $query['ip'] = $ipFilter; }
if ($emailFilter !== '') { $query['email'] = $emailFilter; }
if ($resultFilter !== '') { $query['result'] = $resultFilter; }
if ($riskFilter !== '') { $query['risk'] = $riskFilter; }

$attemptResponse = apiRequest('GET', '/admin/login-security/attempts?' . http_build_query($query));
$attempts = is_array($attemptResponse['data'] ?? null) ? $attemptResponse['data'] : [];
$lastPage = max(1, (int)($attemptResponse['last_page'] ?? 1));
$total = max(0, (int)($attemptResponse['total'] ?? count($attempts)));

$blockResponse = apiRequest('GET', '/admin/login-security/blocked-ips');
$blocks = is_array($blockResponse['data'] ?? null) ? $blockResponse['data'] : [];

function tfSecurityDate($value)
{
    if (empty($value)) {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('America/Denver'))
            ->format('M j, Y g:i A T');
    } catch (Throwable $exception) {
        return (string)$value;
    }
}

function tfSecurityLabel($value)
{
    return ucwords(str_replace('_', ' ', (string)$value));
}

function tfSecurityUrl($newPage)
{
    global $ipFilter, $emailFilter, $resultFilter, $riskFilter;

    return 'admin_login_security.php?' . http_build_query(array_filter([
        'ip' => $ipFilter,
        'email' => $emailFilter,
        'result' => $resultFilter,
        'risk' => $riskFilter,
        'page' => $newPage,
    ], static fn($value) => $value !== ''));
}

$pageTitle = 'Login Security';
$pageContainerClass = 'tf-container-wide';
include 'header.php';
?>

<div class="tf-page-heading">
    <div>
        <h1>Login Security</h1>
        <p class="tf-page-intro">Review successful and unsuccessful sign-ins, identify suspicious login patterns, and block abusive IP addresses.</p>
    </div>
    <span class="tf-count-badge">Administrator only</span>
</div>

<div class="tf-operations-grid">
    <article class="tf-metric-card">
        <h3>Attempts / 24h</h3>
        <strong class="tf-metric-value"><?= (int)($summary['attempts_24h'] ?? 0) ?></strong>
        <p><?= (int)($summary['successful_24h'] ?? 0) ?> successful · <?= (int)($summary['failed_24h'] ?? 0) ?> failed</p>
    </article>
    <article class="tf-metric-card">
        <h3>Failed / 1h</h3>
        <strong class="tf-metric-value"><?= (int)($summary['failed_1h'] ?? 0) ?></strong>
        <p>Recent unsuccessful sign-in attempts.</p>
    </article>
    <article class="tf-metric-card">
        <h3>High risk / 24h</h3>
        <strong class="tf-metric-value"><?= (int)($summary['high_risk_24h'] ?? 0) ?></strong>
        <p>Repeated failures or multi-account targeting.</p>
    </article>
    <article class="tf-metric-card">
        <h3>Blocked IPs</h3>
        <strong class="tf-metric-value"><?= (int)($summary['active_blocks'] ?? 0) ?></strong>
        <p><?= (int)($summary['unique_ips_24h'] ?? 0) ?> unique login IPs in 24 hours.</p>
    </article>
</div>

<section class="tf-section-block" aria-labelledby="manual-block-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="manual-block-heading">Block an IP address</h2>
            <p>Exact IPv4 and IPv6 addresses are supported. TrustFix prevents you from blocking your current administrator IP.</p>
        </div>
    </div>

    <form class="tf-filter-bar" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="security_action" value="block">
        <div>
            <label for="manual_ip">IP address</label>
            <input id="manual_ip" name="ip_address" required placeholder="203.0.113.42">
        </div>
        <div>
            <label for="manual_duration">Duration</label>
            <select id="manual_duration" name="duration">
                <option value="1h">1 hour</option>
                <option value="24h" selected>24 hours</option>
                <option value="7d">7 days</option>
                <option value="permanent">Permanent</option>
            </select>
        </div>
        <div class="tf-filter-grow">
            <label for="manual_reason">Reason</label>
            <input id="manual_reason" name="reason" maxlength="1000" placeholder="Repeated failed logins, known abusive source, etc.">
        </div>
        <button type="submit">Block IP</button>
    </form>
</section>

<section class="tf-section-block" aria-labelledby="blocked-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="blocked-heading">IP deny list</h2>
            <p>Temporary entries automatically stop blocking after their expiration time. They remain visible for audit history.</p>
        </div>
    </div>

    <div class="tf-table-wrap">
        <table class="tf-audit-table">
            <thead>
                <tr>
                    <th>IP address</th>
                    <th>Status</th>
                    <th>Blocked</th>
                    <th>Expires</th>
                    <th>Reason</th>
                    <th>Administrator</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($blocks)): ?>
                <tr><td colspan="7">No IP addresses have been blocked.</td></tr>
            <?php endif; ?>
            <?php foreach ($blocks as $block): ?>
                <?php
                    $isActive = !empty($block['active'])
                        && (empty($block['blocked_until']) || strtotime((string)$block['blocked_until']) > time());
                ?>
                <tr>
                    <td><code><?= htmlspecialchars($block['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><span class="tf-status-pill <?= $isActive ? 'tf-status-urgent' : 'tf-status-neutral' ?>"><?= $isActive ? 'Blocked' : 'Inactive' ?></span></td>
                    <td><?= htmlspecialchars(tfSecurityDate($block['blocked_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= empty($block['blocked_until']) ? 'Permanent' : htmlspecialchars(tfSecurityDate($block['blocked_until']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($block['reason'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($block['administrator']['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($isActive): ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="security_action" value="unblock">
                                <input type="hidden" name="block_id" value="<?= (int)($block['id'] ?? 0) ?>">
                                <button type="submit" class="tf-button tf-button-secondary">Unblock</button>
                            </form>
                        <?php else: ?>
                            <span class="tf-muted">No action</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="tf-section-block" aria-labelledby="attempts-heading">
    <div class="tf-section-heading">
        <div>
            <h2 id="attempts-heading">Login attempts</h2>
            <p><?= $total ?> recorded attempt<?= $total === 1 ? '' : 's' ?>. Passwords are never stored.</p>
        </div>
    </div>

    <form class="tf-filter-bar" method="GET">
        <div>
            <label for="filter_ip">IP</label>
            <input id="filter_ip" type="search" name="ip" value="<?= htmlspecialchars($ipFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="203.0.113.42">
        </div>
        <div class="tf-filter-grow">
            <label for="filter_email">Email</label>
            <input id="filter_email" type="search" name="email" value="<?= htmlspecialchars($emailFilter, ENT_QUOTES, 'UTF-8') ?>" placeholder="user@example.com">
        </div>
        <div>
            <label for="filter_result">Result</label>
            <select id="filter_result" name="result">
                <option value="">All</option>
                <option value="success" <?= $resultFilter === 'success' ? 'selected' : '' ?>>Successful</option>
                <option value="failure" <?= $resultFilter === 'failure' ? 'selected' : '' ?>>Failed</option>
            </select>
        </div>
        <div>
            <label for="filter_risk">Risk</label>
            <select id="filter_risk" name="risk">
                <option value="">All</option>
                <option value="normal" <?= $riskFilter === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="elevated" <?= $riskFilter === 'elevated' ? 'selected' : '' ?>>Elevated</option>
                <option value="high" <?= $riskFilter === 'high' ? 'selected' : '' ?>>High</option>
            </select>
        </div>
        <button type="submit">Filter</button>
        <a href="admin_login_security.php">Clear</a>
    </form>

    <div class="tf-table-wrap">
        <table class="tf-audit-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Account</th>
                    <th>IP address</th>
                    <th>Result</th>
                    <th>Reason</th>
                    <th>Risk</th>
                    <th>Browser / device</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($attempts)): ?>
                <tr><td colspan="8">No login attempts match this filter.</td></tr>
            <?php endif; ?>
            <?php foreach ($attempts as $attempt): ?>
                <?php
                    $successful = !empty($attempt['successful']);
                    $risk = (string)($attempt['risk_level'] ?? 'normal');
                    $riskClass = $risk === 'high' ? 'tf-status-urgent' : ($risk === 'elevated' ? 'tf-status-high' : 'tf-status-normal');
                ?>
                <tr>
                    <td><?= htmlspecialchars(tfSecurityDate($attempt['created_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <strong><?= htmlspecialchars($attempt['email'] ?? 'No email', ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (!empty($attempt['user']['name'])): ?><br><span class="tf-muted"><?= htmlspecialchars($attempt['user']['name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($attempt['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td><span class="tf-status-pill <?= $successful ? 'tf-status-normal' : 'tf-status-urgent' ?>"><?= $successful ? 'Success' : 'Failed' ?></span></td>
                    <td><?= htmlspecialchars(tfSecurityLabel($attempt['outcome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="tf-status-pill <?= $riskClass ?>"><?= htmlspecialchars(ucfirst($risk), ENT_QUOTES, 'UTF-8') ?></span><br>
                        <span class="tf-muted">Score <?= (int)($attempt['risk_score'] ?? 0) ?> · <?= (int)($attempt['recent_ip_failures'] ?? 0) ?> recent failures · <?= (int)($attempt['targeted_accounts'] ?? 0) ?> accounts</span>
                    </td>
                    <td><?= htmlspecialchars(mb_strimwidth($attempt['user_agent'] ?? '', 0, 90, '…'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="security_action" value="block">
                            <input type="hidden" name="ip_address" value="<?= htmlspecialchars($attempt['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="reason" value="Blocked from login attempt <?= (int)($attempt['id'] ?? 0) ?>.">
                            <input type="hidden" name="return_ip" value="<?= htmlspecialchars($ipFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="return_email" value="<?= htmlspecialchars($emailFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="return_result" value="<?= htmlspecialchars($resultFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="return_risk" value="<?= htmlspecialchars($riskFilter, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="return_page" value="<?= $page ?>">
                            <select name="duration" aria-label="Block duration">
                                <option value="1h">1 hour</option>
                                <option value="24h" selected>24 hours</option>
                                <option value="7d">7 days</option>
                                <option value="permanent">Permanent</option>
                            </select>
                            <button type="submit" class="tf-button tf-button-secondary">Block IP</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($lastPage > 1): ?>
        <nav class="tf-pagination" aria-label="Login attempt pages">
            <div class="tf-pagination-links">
                <?php if ($page > 1): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfSecurityUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php endif; ?>
                <span>Page <?= $page ?> of <?= $lastPage ?></span>
                <?php if ($page < $lastPage): ?><a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(tfSecurityUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>

<p class="tf-muted">Login records use Laravel's resolved request IP. If TrustFix is later placed behind Cloudflare or another reverse proxy, configure Laravel trusted proxies before relying on forwarded client addresses.</p>

<?php include 'footer.php'; ?>
