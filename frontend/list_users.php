<?php

require 'config.php';
include 'header.php';

requireLogin();

$result = apiRequest('GET', '/admin/users');

$users = $result['data'] ?? [];
?>

<h1>Manage Users</h1>

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

<?php include 'footer.php'; ?>
