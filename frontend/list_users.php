<?php

require 'config.php';
include 'header.php';

requireLogin();

$result = apiRequest('GET', '/admin/users');

$users = $result['data'] ?? [];

$passwordMessage = $_SESSION['user_password_message'] ?? null;
unset($_SESSION['user_password_message']);
?>

<h1>Manage Users</h1>

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

<table border="1" cellpadding="8">

<tr>
    <th>ID</th>
    <th>Name</th>
    <th>Email</th>
    <th>Phone</th>
    <th>Address</th>
    <th>Business Name</th>
    <th>Last Login</th>
    <th>Pending Approvals</th>
    <th>Role</th>
    <th>Password</th>
    <th>Edit</th>
    <th>Delete</th>
</tr>

<?php foreach ($users as $user): ?>

<?php
    $pendingApprovals = (int)(
        $user['pending_contractor_document_count']
        ?? 0
    );
?>

<tr>

    <td><?= $user['id'] ?></td>

    <td><?= htmlspecialchars($user['name'] ?? '') ?></td>

    <td><?= htmlspecialchars($user['email'] ?? '') ?></td>

    <td><?= htmlspecialchars($user['phone'] ?? '') ?></td>

    <td><?= htmlspecialchars($user['address'] ?? '') ?></td>

    <td>
        <?= htmlspecialchars(
            $user['contractor_profile']['business_name']
            ?? ''
        ) ?>
    </td>

    <td>
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

    <td>
        <?php if ($pendingApprovals > 0): ?>
            <strong style="color:#b36b00;">
                <?= $pendingApprovals ?> pending
            </strong>
        <?php else: ?>
            <span style="color:#4b7f2a;">0 pending</span>
        <?php endif; ?>
    </td>

    <td>
        <form method="POST" action="update_user_role.php" style="display:flex;gap:8px;align-items:center;margin:0;">
            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

            <select name="role" style="min-width:130px;margin:0;">
                <?php foreach (['customer' => 'Customer', 'handyman' => 'Handyman', 'company' => 'Company', 'admin' => 'Admin'] as $roleValue => $roleLabel): ?>
                    <option
                        value="<?= htmlspecialchars($roleValue) ?>"
                        <?= ($user['role'] ?? '') === $roleValue ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($roleLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" style="width:auto;margin:0;padding:8px 12px;">
                Save
            </button>
        </form>
    </td>

    <td>
        <button
            type="button"
            onclick="openPasswordReset(<?= (int)$user['id'] ?>, <?= htmlspecialchars(json_encode($user['name'] ?? $user['email'] ?? 'this user'), ENT_QUOTES, 'UTF-8') ?>)"
            style="width:auto;margin:0;padding:8px 12px;"
        >
            Reset Password
        </button>
    </td>

    <td>
        <a href="edit_user.php?id=<?= $user['id'] ?>">
            Edit
        </a>
    </td>

    <td>

        <form
            method="POST"
            action="delete_user.php"
            onsubmit="return confirm('Delete this user?');"
        >

            <input
                type="hidden"
                name="user_id"
                value="<?= $user['id'] ?>"
            >

            <button
                type="submit"
                style="color:red;"
            >
                Delete
            </button>

        </form>

    </td>

</tr>

<?php endforeach; ?>

</table>


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
            <input type="hidden" name="user_id" id="passwordResetUserId">

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
