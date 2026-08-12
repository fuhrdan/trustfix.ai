<?php

require 'config.php';
requireRole('admin');

requireValidCsrf();

$returnQuery = trim(substr((string)($_POST['return_q'] ?? ''), 0, 255));
$returnPage = max(1, (int)($_POST['return_page'] ?? 1));
$returnParams = ['page' => $returnPage];

if ($returnQuery !== '') {
    $returnParams['q'] = $returnQuery;
}

$returnUrl = 'list_users.php?' . http_build_query($returnParams);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnUrl);
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
    header('Location: ' . $returnUrl);
    exit;
}

$currentUser = apiRequest('GET', "/admin/users/$userId");

if (
    !is_array($currentUser)
    || (int)($currentUser['_http_code'] ?? 500) >= 400
    || isset($currentUser['error'])
) {
    $_SESSION['flash_error'] = 'Unable to load user before updating role.';
    header('Location: ' . $returnUrl);
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
    $_SESSION['flash_error'] = apiMessage($response, 'User role update failed.');
}

header('Location: ' . $returnUrl);
exit;
