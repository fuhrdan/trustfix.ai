<?php

require 'config.php';
requireLogin();

include 'header.php';

$userId = (int)($_GET['id'] ?? 0);

if (!$userId)
{
    die('Missing user id');
}

$user = apiRequest(
    'GET',
    "/admin/users/$userId"
);

if (!is_array($user))
{
    die('User not found');
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $payload = [
        'name' => $_POST['name'] ?? '',
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'address' => $_POST['address'] ?? '',
        'role' => $_POST['role'] ?? 'customer'
    ];

    apiRequest(
        'PUT',
        "/admin/users/$userId",
        $payload
    );

    $user = apiRequest(
        'GET',
        "/admin/users/$userId"
    );

    $message = "
        <div style='
            background:#dff0d8;
            padding:15px;
            border-radius:8px;
            margin-bottom:20px;
        '>
            User Updated Successfully
        </div>
    ";
}

?>

<h1>Edit User</h1>

<?= $message ?>

<form method="POST">

    <label>Name</label><br>

    <input
        type="text"
        name="name"
        required
        value="<?= htmlspecialchars($user['name'] ?? '') ?>"
    >

    <br><br>

    <label>Email</label><br>

    <input
        type="email"
        name="email"
        required
        value="<?= htmlspecialchars($user['email'] ?? '') ?>"
    >

    <br><br>

    <label>Phone</label><br>

    <input
        type="text"
        name="phone"
        value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
    >

    <br><br>

    <label>Address</label><br>

    <input
        type="text"
        name="address"
        value="<?= htmlspecialchars($user['address'] ?? '') ?>"
    >

    <br><br>

    <label>Role</label><br>

    <select name="role">

        <option
            value="customer"
            <?= ($user['role'] ?? '') === 'customer'
                ? 'selected'
                : '' ?>
        >
            Customer
        </option>

        <option
            value="handyman"
            <?= ($user['role'] ?? '') === 'handyman'
                ? 'selected'
                : '' ?>
        >
            Handyman
        </option>

        <option
            value="company"
            <?= ($user['role'] ?? '') === 'company'
                ? 'selected'
                : '' ?>
        >
            Company
        </option>

        <option
            value="admin"
            <?= ($user['role'] ?? '') === 'admin'
                ? 'selected'
                : '' ?>
        >
            Admin
        </option>

    </select>

    <br><br>

    <button type="submit">
        Update User
    </button>

</form>

<?php include 'footer.php'; ?>