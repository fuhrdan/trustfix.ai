<?php

require 'config.php';
include 'header.php';

requireLogin();

$result = apiRequest('GET', '/admin/users');

//***************************DEBUG*********************************************
//echo '<pre>';
//print_r($result);
//echo '</pre>';
//*************************END DEBUG*******************************************

$users = $result['data'] ?? [];
?>

<h1>Manage Users (test)</h1>

<table border="1" cellpadding="8">

<tr>
    <th>ID</th>
    <th>Name</th>
    <th>Email</th>
    <th>Phone</th>
    <th>Address</th>
    <th>Business Name</th>
    <th>Role</th>
    <th>Edit</th>
    <th>Delete</th>
</tr>

<?php foreach ($users as $user): ?>

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
        <?= htmlspecialchars($user['role'] ?? '') ?>
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