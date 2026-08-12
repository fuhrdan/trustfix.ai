<?php

require 'config.php';
$currentAdmin = requireRole('admin');

$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$query = ['page' => $page];

if ($search !== '') {
    $query['q'] = $search;
}

$result = apiRequest('GET', '/admin/users?' . http_build_query($query));

$users = is_array($result['data'] ?? null) ? $result['data'] : [];
$currentPage = max(1, (int)($result['current_page'] ?? $page));
$lastPage = max(1, (int)($result['last_page'] ?? 1));
$totalUsers = max(0, (int)($result['total'] ?? count($users)));
$loadError = !isset($result['data'])
    ? apiMessage($result, 'Unable to load user accounts right now.')
    : '';

function adminUserListUrl($targetPage, $search)
{
    $params = ['page' => max(1, (int)$targetPage)];

    if ($search !== '') {
        $params['q'] = $search;
    }

    return 'list_users.php?' . http_build_query($params);
}

$passwordMessage = $_SESSION['user_password_message'] ?? null;
unset($_SESSION['user_password_message']);

$pageTitle = 'Manage Users';
$pageContainerClass = 'tf-container-wide';
include 'header.php';
?>

<div class="tf-page-heading">
    <div>
        <h1>Manage Users</h1>
        <p class="tf-page-intro">Review accounts, contractor approvals, roles, and administrative actions.</p>
    </div>
    <span class="tf-count-badge"><?= $totalUsers ?> user<?= $totalUsers === 1 ? '' : 's' ?></span>
</div>

<form class="tf-admin-search" method="GET" role="search">
    <div>
        <label for="admin_user_search">Search users</label>
        <input
            id="admin_user_search"
            type="search"
            name="q"
            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Name, email, or phone"
        >
    </div>
    <button type="submit">Search</button>
    <?php if ($search !== ''): ?>
        <a href="list_users.php">Clear</a>
    <?php endif; ?>
</form>

<?php if ($loadError !== ''): ?>
    <div class="tf-alert tf-alert-error" role="alert">
        <?= htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($passwordMessage): ?>
    <div style="
        padding:12px;
        margin-bottom:15px;
        border-radius:6px;
        background:<?= $passwordMessage['success'] ? '#dff0d8' : '#f8d7da' ?>;
    ">
        <?= htmlspecialchars($passwordMessage['text'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="tf-table-wrap tf-admin-users-table-wrap">
<table class="tf-admin-users-table">
    <caption class="tf-sr-only">TrustFix user accounts</caption>
    <thead>
        <tr>
            <th scope="col">User</th>
            <th scope="col">Contact</th>
            <th scope="col">Contractor</th>
            <th scope="col">Last Login</th>
            <th scope="col">Role</th>
            <th scope="col">Actions</th>
        </tr>
    </thead>
    <tbody>

<?php if (empty($users)): ?>
    <tr class="tf-admin-empty-row">
        <td colspan="6">
            <?= $search !== '' ? 'No users match your search.' : 'No user accounts were returned.' ?>
        </td>
    </tr>
<?php endif; ?>

<?php foreach ($users as $user): ?>

<?php
    $pendingApprovals = (int)(
        $user['pending_contractor_document_count']
        ?? 0
    );
    $isCurrentAdmin = (int)($user['id'] ?? 0) === (int)($currentAdmin['id'] ?? 0);
?>

<tr>
    <td data-label="User">
        <strong><?= htmlspecialchars($user['name'] ?? 'Unnamed user') ?></strong><br>
        <span class="tf-muted">User #<?= (int)($user['id'] ?? 0) ?></span>
    </td>

    <td data-label="Contact">
        <a href="mailto:<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($user['email'] ?? '') ?>
        </a>
        <?php if (!empty($user['phone'])): ?>
            <br><?= htmlspecialchars($user['phone']) ?>
        <?php endif; ?>
        <?php if (!empty($user['address'])): ?>
            <br><span class="tf-muted"><?= htmlspecialchars($user['address']) ?></span>
        <?php endif; ?>
    </td>

    <td data-label="Contractor">
        <?php if (!empty($user['contractor_profile']['business_name'])): ?>
            <strong><?= htmlspecialchars($user['contractor_profile']['business_name']) ?></strong><br>
        <?php else: ?>
            <span class="tf-muted">No contractor profile</span><br>
        <?php endif; ?>

        <?php if ($pendingApprovals > 0): ?>
            <strong class="tf-status-warning"><?= $pendingApprovals ?> pending approval<?= $pendingApprovals === 1 ? '' : 's' ?></strong>
        <?php else: ?>
            <span class="tf-status-success">No pending approvals</span>
        <?php endif; ?>
    </td>

    <td data-label="Last Login">
        <?php if (!empty($user['last_login_at'])): ?>
            <?= htmlspecialchars(
                date(
                    'm/d/Y g:i A',
                    strtotime($user['last_login_at'])
                )
            ) ?>
        <?php else: ?>
            <span style="color:#777;">Never</span>
        <?php endif; ?>
    </td>

    <td data-label="Role">
        <form method="POST" action="update_user_role.php" class="tf-role-form">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
            <input type="hidden" name="return_q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_page" value="<?= $currentPage ?>">

            <select name="role" aria-label="Role for <?= htmlspecialchars($user['name'] ?? $user['email'] ?? 'user', ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach (['customer' => 'Customer', 'handyman' => 'Handyman', 'company' => 'Company', 'admin' => 'Admin'] as $roleValue => $roleLabel): ?>
                    <option
                        value="<?= htmlspecialchars($roleValue) ?>"
                        <?= ($user['role'] ?? '') === $roleValue ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($roleLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Save Role</button>
        </form>
    </td>

    <td data-label="Actions">
        <div class="tf-admin-user-actions">
            <a class="tf-button tf-button-secondary" href="edit_user.php?id=<?= (int)($user['id'] ?? 0) ?>">Edit</a>
            <button
                class="tf-button-secondary"
                type="button"
                onclick="openPasswordReset(<?= (int)$user['id'] ?>, <?= htmlspecialchars(json_encode($user['name'] ?? $user['email'] ?? 'this user'), ENT_QUOTES, 'UTF-8') ?>)"
            >Reset Password</button>

            <form method="POST" action="delete_user.php" onsubmit="return confirm('Delete this user?');">
                <?= csrfField() ?>
                <input type="hidden" name="user_id" value="<?= (int)($user['id'] ?? 0) ?>">
                <input type="hidden" name="return_q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="return_page" value="<?= $currentPage ?>">
                <?php if ($isCurrentAdmin): ?>
                    <span class="tf-muted">Current account</span>
                <?php else: ?>
                    <button
                        class="tf-button-danger"
                        type="submit"
                    >Delete</button>
                <?php endif; ?>
            </form>
        </div>
    </td>
</tr>

<?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($lastPage > 1): ?>
    <nav class="tf-pagination" aria-label="User list pages">
        <div class="tf-pagination-links">
            <?php if ($currentPage > 1): ?>
                <a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(adminUserListUrl($currentPage - 1, $search), ENT_QUOTES, 'UTF-8') ?>">Previous</a>
            <?php endif; ?>

            <span>Page <?= $currentPage ?> of <?= $lastPage ?></span>

            <?php if ($currentPage < $lastPage): ?>
                <a class="tf-button tf-button-secondary" href="<?= htmlspecialchars(adminUserListUrl($currentPage + 1, $search), ENT_QUOTES, 'UTF-8') ?>">Next</a>
            <?php endif; ?>
        </div>
        <span class="tf-muted">
            Showing <?= (int)($result['from'] ?? 0) ?>–<?= (int)($result['to'] ?? 0) ?> of <?= $totalUsers ?>
        </span>
    </nav>
<?php endif; ?>


<div
    id="passwordResetModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,0.55);
        z-index:9999;
        align-items:center;
        justify-content:center;
    "
>
    <div style="background:white;padding:24px;border-radius:10px;width:min(420px,90%);">
        <h2 style="margin-top:0;">Reset User Password</h2>

        <p id="passwordResetUser"></p>

        <form method="POST" action="reset_user_password.php" onsubmit="return validatePasswordReset();">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" id="passwordResetUserId">
            <input type="hidden" name="return_q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_page" value="<?= $currentPage ?>">

            <label for="newPassword">New Password</label><br>
            <input type="password" name="password" id="newPassword" minlength="8" required style="width:100%;box-sizing:border-box;">

            <br><br>

            <label for="confirmPassword">Confirm New Password</label><br>
            <input type="password" name="password_confirmation" id="confirmPassword" minlength="8" required style="width:100%;box-sizing:border-box;">

            <div id="passwordResetError" style="color:#b00020;margin-top:10px;"></div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" onclick="closePasswordReset()" style="width:auto;margin:0;">Cancel</button>
                <button type="submit" style="width:auto;margin:0;">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPasswordReset(userId, userName)
{
    document.getElementById('passwordResetUserId').value = userId;
    document.getElementById('passwordResetUser').textContent = 'Set a new password for ' + userName + '.';
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    document.getElementById('passwordResetError').textContent = '';
    document.getElementById('passwordResetModal').style.display = 'flex';
    document.getElementById('newPassword').focus();
}

function closePasswordReset()
{
    document.getElementById('passwordResetModal').style.display = 'none';
}

function validatePasswordReset()
{
    const password = document.getElementById('newPassword').value;
    const confirmation = document.getElementById('confirmPassword').value;
    const error = document.getElementById('passwordResetError');

    if (password.length < 8)
    {
        error.textContent = 'Password must be at least 8 characters.';
        return false;
    }

    if (password !== confirmation)
    {
        error.textContent = 'The passwords do not match.';
        return false;
    }

    return confirm('Reset this user\'s password?');
}

window.addEventListener('click', function(event)
{
    const modal = document.getElementById('passwordResetModal');

    if (event.target === modal)
    {
        closePasswordReset();
    }
});
</script>

<?php include 'footer.php'; ?>
