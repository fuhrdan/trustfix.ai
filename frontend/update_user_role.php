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
$submittedRoles = $_POST['roles'] ?? [];
$emailVerified = (string)($_POST['email_verified'] ?? '');

$allowedRoles = [
    'customer',
    'handyman',
    'company',
    'admin',
];
$roles = [];
$rolesAreValid = is_array($submittedRoles) && $submittedRoles !== [];

if ($rolesAreValid) {
    foreach ($submittedRoles as $submittedRole) {
        if (
            !is_string($submittedRole)
            || !in_array($submittedRole, $allowedRoles, true)
            || in_array($submittedRole, $roles, true)
        ) {
            $rolesAreValid = false;
            break;
        }

        $roles[] = $submittedRole;
    }
}

if (
    $userId <= 0
    || !in_array($role, $allowedRoles, true)
    || !$rolesAreValid
    || !in_array($role, $roles, true)
    || !in_array($emailVerified, ['0', '1'], true)
) {
    $_SESSION['flash_error'] = 'Choose valid assigned roles, a primary role included in those assignments, and an email verification state.';
    header('Location: ' . $returnUrl);
    exit;
}

$currentUser = apiRequest('GET', "/admin/users/$userId");

if (
    !is_array($currentUser)
    || (int)($currentUser['_http_code'] ?? 500) >= 400
    || isset($currentUser['error'])
) {
    $_SESSION['flash_error'] = 'Unable to load user before updating the account.';
    header('Location: ' . $returnUrl);
    exit;
}

$payload = [
    'name' => $currentUser['name'] ?? '',
    'email' => $currentUser['email'] ?? '',
    'phone' => $currentUser['phone'] ?? '',
    'address' => $currentUser['address'] ?? '',
    'role' => $role,
    'roles' => $roles,
    'email_verified' => $emailVerified === '1',
];

$response = apiRequest(
    'PUT',
    "/admin/users/$userId",
    $payload
);

if (($response['success'] ?? false) === true) {
    $_SESSION['flash_success'] = 'User roles, primary role, and email verification updated.';
} else {
    $_SESSION['flash_error'] = apiMessage($response, 'User account update failed.');
}

header('Location: ' . $returnUrl);
exit;
