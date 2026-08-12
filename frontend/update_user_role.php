<?php

require 'config.php';
requireRole('admin');

requireValidCsrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: list_users.php');
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
$role = $_POST['role'] ?? '';

$allowedRoles = [
    'customer',
    'handyman',
    'company',
    'admin',
];

if ($userId <= 0 || !in_array($role, $allowedRoles, true)) {
    $_SESSION['flash_error'] = 'Invalid user role update.';
    header('Location: list_users.php');
    exit;
}

$currentUser = apiRequest('GET', "/admin/users/$userId");

if (!is_array($currentUser) || isset($currentUser['error'])) {
    $_SESSION['flash_error'] = 'Unable to load user before updating role.';
    header('Location: list_users.php');
    exit;
}

$payload = [
    'name' => $currentUser['name'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'phone' => $currentUser['phone'] ?? '',
    'address' => $currentUser['address'] ?? '',
    'role' => $role,
];

$response = apiRequest(
    'PUT',
    "/admin/users/$userId",
    $payload
);

if (($response['success'] ?? false) === true) {
    $_SESSION['flash_success'] = 'User role updated.';
} else {
    $_SESSION['flash_error'] = 'User role update failed.';
}

header('Location: list_users.php');
exit;
