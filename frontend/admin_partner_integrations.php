<?php

if (!headers_sent()) {
    header('Cache-Control: no-store');
}

require 'config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrf();
    $action = (string)($_POST['integration_action'] ?? '');
    $response = null;

    if ($action === 'create_partner') {
        $response = apiRequest('POST', '/admin/partner-clients', [
            'name' => trim((string)($_POST['name'] ?? '')),
            'slug' => strtolower(trim((string)($_POST['slug'] ?? ''))),
            'scopes' => array_values(array_filter((array)($_POST['scopes'] ?? []))),
            'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')) ?: null,
            'active' => true,
        ]);

        if (!empty($response['partner'])) {
            $_SESSION['partner_integration_reveal'] = [
                'type' => 'partner',
                'name' => $response['partner']['name'] ?? 'Partner',
                'client_id' => $response['credentials']['client_id'] ?? '',
                'api_token' => $response['credentials']['api_token'] ?? '',
                'webhook_secret' => $response['credentials']['webhook_secret'] ?? '',
            ];
            $_SESSION['flash_success'] = 'Partner credentials were created. Copy the values shown below before leaving this page.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The partner client could not be created.');
        }
    } elseif ($action === 'create_invite') {
        $response = apiRequest('POST', '/admin/contractor-invitations', [
            'email' => trim((string)($_POST['email'] ?? '')),
            'name' => trim((string)($_POST['invite_name'] ?? '')) ?: null,
            'business_name' => trim((string)($_POST['business_name'] ?? '')) ?: null,
            'service_area' => trim((string)($_POST['service_area'] ?? '')) ?: null,
            'expires_in_hours' => (int)($_POST['expires_in_hours'] ?? 168),
        ]);

        if (!empty($response['invitation'])) {
            $_SESSION['partner_integration_reveal'] = [
                'type' => 'invite',
                'email' => $response['invitation']['email'] ?? '',
                'invite_url' => $response['invite_url'] ?? '',
                'email_queued' => !empty($response['email_queued']),
            ];
            $_SESSION['flash_success'] = 'The contractor invitation was created.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The contractor invitation could not be created.');
        }
    } elseif ($action === 'revoke_invite') {
        $response = apiRequest('POST', '/admin/contractor-invitations/' . (int)($_POST['invite_id'] ?? 0) . '/revoke');
        $_SESSION[!empty($response['revoked']) ? 'flash_success' : 'flash_error'] = !empty($response['revoked'])
            ? 'Contractor invitation revoked.'
            : apiMessage($response, 'The contractor invitation could not be revoked.');
    } elseif ($action === 'rotate_api_token' || $action === 'rotate_webhook_secret') {
        $partnerId = trim((string)($_POST['partner_id'] ?? ''));
        $endpoint = $action === 'rotate_api_token'
            ? '/admin/partner-clients/' . rawurlencode($partnerId) . '/rotate-api-token'
            : '/admin/partner-clients/' . rawurlencode($partnerId) . '/rotate-webhook-secret';
        $response = apiRequest('POST', $endpoint, []);

        if ($action === 'rotate_api_token' && !empty($response['api_token'])) {
            $_SESSION['partner_integration_reveal'] = [
                'type' => 'api_token',
                'client_id' => $response['client_id'] ?? '',
                'api_token' => $response['api_token'],
            ];
            $_SESSION['flash_success'] = 'The previous API token was revoked. Copy the new value below now.';
        } elseif ($action === 'rotate_webhook_secret' && !empty($response['webhook_secret'])) {
            $_SESSION['partner_integration_reveal'] = [
                'type' => 'webhook_secret',
                'client_id' => $response['client_id'] ?? '',
                'webhook_secret' => $response['webhook_secret'],
            ];
            $_SESSION['flash_success'] = 'The previous webhook secret was revoked. Copy the new value below now.';
        } else {
            $_SESSION['flash_error'] = apiMessage($response, 'The credential could not be rotated.');
        }
    } elseif ($action === 'map_member') {
        $partnerId = trim((string)($_POST['partner_id'] ?? ''));
        $response = apiRequest('POST', '/admin/partner-clients/' . rawurlencode($partnerId) . '/member-accounts', [
            'user_id' => (int)($_POST['user_id'] ?? 0),
            'external_member_id' => trim((string)($_POST['external_member_id'] ?? '')),
            'membership_status' => (string)($_POST['membership_status'] ?? 'active'),
        ]);
        $_SESSION[!empty($response['member_account']) ? 'flash_success' : 'flash_error'] = !empty($response['member_account'])
            ? 'Partner member mapping saved.'
            : apiMessage($response, 'The partner member could not be mapped.');
    }

    header('Location: admin_partner_integrations.php');
    exit;
}

$reveal = $_SESSION['partner_integration_reveal'] ?? null;
unset($_SESSION['partner_integration_reveal']);
$partnersResponse = apiRequest('GET', '/admin/partner-clients');
$partners = is_array($partnersResponse) && array_is_list($partnersResponse) ? $partnersResponse : [];
$invitesResponse = apiRequest('GET', '/admin/contractor-invitations?per_page=25');
$invites = is_array($invitesResponse['data'] ?? null) ? $invitesResponse['data'] : [];

function tfIntegrationDate($value)
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

function tfIntegrationInviteStatus($invite)
{
    if (!empty($invite['accepted_at'])) {
        return 'Accepted';
    }
    if (!empty($invite['revoked_at'])) {
        return 'Revoked';
    }
    if (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) <= time()) {
        return 'Expired';
    }

    return 'Active';
}

$pageTitle = 'Partner Integrations';
$pageContainerClass = 'tf-container-wide';
include 'header.php';
?>

<div class="tf-page-heading">
    <div>
        <h1>Partner Integrations</h1>
        <p class="tf-page-intro">Create revocable partner API credentials, map eligible members to TrustFix accounts, and issue secure contractor invitations.</p>
    </div>
    <span class="tf-count-badge">Administrator only</span>
</div>

<?php if (!empty($reveal) && is_array($reveal)): ?>
    <section class="tf-section-block" style="border:2px solid #2d6cdf;" aria-labelledby="copy-now-heading">
        <div class="tf-section-heading">
            <div>
                <h2 id="copy-now-heading">Copy this value now</h2>
                <p>For security, TrustFix does not display generated credentials or invitation links again after this page load.</p>
            </div>
        </div>
        <?php if (!empty($reveal['client_id'])): ?>
            <label>Client ID<input readonly autocomplete="off" value="<?= htmlspecialchars($reveal['client_id'], ENT_QUOTES, 'UTF-8') ?>"></label>
        <?php endif; ?>
        <?php if (!empty($reveal['api_token'])): ?>
            <label>Partner API token<input readonly autocomplete="off" value="<?= htmlspecialchars($reveal['api_token'], ENT_QUOTES, 'UTF-8') ?>"></label>
        <?php endif; ?>
        <?php if (!empty($reveal['webhook_secret'])): ?>
            <label>Webhook signing secret<input readonly autocomplete="off" value="<?= htmlspecialchars($reveal['webhook_secret'], ENT_QUOTES, 'UTF-8') ?>"></label>
        <?php endif; ?>
        <?php if (!empty($reveal['invite_url'])): ?>
            <label>Contractor invitation for <?= htmlspecialchars($reveal['email'] ?? '', ENT_QUOTES, 'UTF-8') ?><input readonly autocomplete="off" value="<?= htmlspecialchars($reveal['invite_url'], ENT_QUOTES, 'UTF-8') ?>"></label>
            <p class="tf-muted">Email queued: <?= !empty($reveal['email_queued']) ? 'yes' : 'no — use this link through an approved channel.' ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="tf-operations-grid">
    <section class="tf-section-block">
        <div class="tf-section-heading"><div><h2>Create partner API client</h2><p>Credentials are scoped, individually revocable, and separate from customer JWTs.</p></div></div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="integration_action" value="create_partner">
            <label for="partner_name">Partner name</label>
            <input id="partner_name" name="name" required maxlength="191" placeholder="Crane Concierge">
            <label for="partner_slug">Partner slug</label>
            <input id="partner_slug" name="slug" required maxlength="64" pattern="[a-z0-9][a-z0-9-]*" placeholder="crane">
            <fieldset style="margin:18px 0;"><legend>Permissions</legend>
                <label><input type="checkbox" name="scopes[]" value="service_requests:read" checked> Read request status and activity</label><br>
                <label><input type="checkbox" name="scopes[]" value="service_requests:write" checked> Submit service requests</label><br>
                <label><input type="checkbox" name="scopes[]" value="service_requests:schedule" checked> Record scheduling confirmations</label><br>
                <label><input type="checkbox" name="scopes[]" value="members:read"> Read member mappings</label>
            </fieldset>
            <label for="webhook_url">Status webhook URL <span class="tf-muted">(optional)</span></label>
            <input id="webhook_url" name="webhook_url" type="url" maxlength="2048" placeholder="https://partner.example/webhooks/trustfix">
            <button type="submit">Create partner client</button>
        </form>
    </section>

    <section class="tf-section-block">
        <div class="tf-section-heading"><div><h2>Invite a contractor</h2><p>The email link is one-time, expires automatically, and creates a pending contractor profile.</p></div></div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="integration_action" value="create_invite">
            <label for="invite_email">Email address</label>
            <input id="invite_email" name="email" type="email" required maxlength="191" placeholder="contractor@example.com">
            <label for="invite_name">Contractor name</label>
            <input id="invite_name" name="invite_name" maxlength="191" placeholder="Jordan Smith">
            <label for="invite_business">Business name <span class="tf-muted">(optional)</span></label>
            <input id="invite_business" name="business_name" maxlength="191" placeholder="Smith Home Repair">
            <label for="invite_area">Service area <span class="tf-muted">(optional)</span></label>
            <input id="invite_area" name="service_area" maxlength="191" placeholder="Denver metro">
            <label for="invite_expiry">Expires in</label>
            <select id="invite_expiry" name="expires_in_hours"><option value="24">24 hours</option><option value="72">3 days</option><option value="168" selected>7 days</option><option value="336">14 days</option></select>
            <button type="submit">Create contractor invitation</button>
        </form>
    </section>
</div>

<section class="tf-section-block" aria-labelledby="partner-list-heading">
    <div class="tf-section-heading"><div><h2 id="partner-list-heading">Partner clients</h2><p>Rotate a credential immediately if it is copied to an unintended location.</p></div></div>
    <div class="tf-table-wrap"><table class="tf-audit-table"><thead><tr><th>Partner</th><th>Permissions</th><th>Usage</th><th>Mappings</th><th>Credential actions</th></tr></thead><tbody>
    <?php if (empty($partners)): ?><tr><td colspan="5">No partner API clients have been created.</td></tr><?php endif; ?>
    <?php foreach ($partners as $partner): ?>
        <tr>
            <td><strong><?= htmlspecialchars($partner['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong><br><span class="tf-muted"><?= htmlspecialchars($partner['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= !empty($partner['active']) ? 'active' : 'inactive' ?></span></td>
            <td><?= htmlspecialchars(implode(', ', (array)($partner['scopes'] ?? [])), ENT_QUOTES, 'UTF-8') ?></td>
            <td>Last used: <?= htmlspecialchars(tfIntegrationDate($partner['last_used_at'] ?? null), ENT_QUOTES, 'UTF-8') ?><br><span class="tf-muted">Requests: <?= (int)($partner['service_requests_count'] ?? 0) ?></span></td>
            <td><?= (int)($partner['member_accounts_count'] ?? 0) ?> mapped members</td>
            <td>
                <form method="POST" style="display:inline-block;margin:0 6px 6px 0;"><?= csrfField() ?><input type="hidden" name="integration_action" value="rotate_api_token"><input type="hidden" name="partner_id" value="<?= htmlspecialchars($partner['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><button class="tf-button tf-button-secondary" type="submit">Rotate API token</button></form>
                <form method="POST" style="display:inline-block;margin:0;"><?= csrfField() ?><input type="hidden" name="integration_action" value="rotate_webhook_secret"><input type="hidden" name="partner_id" value="<?= htmlspecialchars($partner['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><button class="tf-button tf-button-secondary" type="submit">Rotate webhook secret</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<?php if (!empty($partners)): ?>
<section class="tf-section-block" aria-labelledby="member-map-heading">
    <div class="tf-section-heading"><div><h2 id="member-map-heading">Map a partner member to a TrustFix account</h2><p>Use this for a member who is already registered before their OIDC identity is linked. Find the TrustFix user ID in Manage Users.</p></div></div>
    <form class="tf-filter-bar" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="integration_action" value="map_member">
        <div><label for="map_partner">Partner</label><select id="map_partner" name="partner_id" required><?php foreach ($partners as $partner): ?><option value="<?= htmlspecialchars($partner['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($partner['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div><label for="map_user">TrustFix user ID</label><input id="map_user" name="user_id" type="number" min="1" required></div>
        <div class="tf-filter-grow"><label for="map_external">Partner member ID</label><input id="map_external" name="external_member_id" maxlength="191" required></div>
        <div><label for="map_status">Status</label><select id="map_status" name="membership_status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <button type="submit">Save mapping</button>
    </form>
</section>
<?php endif; ?>

<section class="tf-section-block" aria-labelledby="invite-list-heading">
    <div class="tf-section-heading"><div><h2 id="invite-list-heading">Recent contractor invitations</h2><p>Invitations are not accepted on page load, which avoids accidental activation by email security scanners.</p></div></div>
    <div class="tf-table-wrap"><table class="tf-audit-table"><thead><tr><th>Contractor</th><th>Business / area</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead><tbody>
    <?php if (empty($invites)): ?><tr><td colspan="5">No contractor invitations have been created.</td></tr><?php endif; ?>
    <?php foreach ($invites as $invite): ?>
        <?php $status = tfIntegrationInviteStatus($invite); ?>
        <tr>
            <td><strong><?= htmlspecialchars($invite['name'] ?: $invite['email'], ENT_QUOTES, 'UTF-8') ?></strong><br><span class="tf-muted"><?= htmlspecialchars($invite['email'], ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= htmlspecialchars($invite['business_name'] ?: '—', ENT_QUOTES, 'UTF-8') ?><br><span class="tf-muted"><?= htmlspecialchars($invite['service_area'] ?: '', ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(tfIntegrationDate($invite['expires_at'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?php if ($status === 'Active'): ?><form method="POST"><?= csrfField() ?><input type="hidden" name="integration_action" value="revoke_invite"><input type="hidden" name="invite_id" value="<?= (int)$invite['id'] ?>"><button class="tf-button tf-button-secondary" type="submit">Revoke</button></form><?php else: ?><span class="tf-muted">No action</span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>

<?php include 'footer.php'; ?>
