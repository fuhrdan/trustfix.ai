<?php

require 'config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST')
{
    header('Location: list_users.php');
    exit;
}

requireValidCsrf();

$userId = (int)($_POST['user_id'] ?? 0);
$password = $_POST['password'] ?? '';
$passwordConfirmation = $_POST['password_confirmation'] ?? '';

if ($userId <= 0)
{
    $_SESSION['user_password_message'] = [
        'success' => false,
        'text' => 'Invalid user.'
    ];

    header('Location: list_users.php');
    exit;
}

$response = apiRequest(
    'POST',
    "/admin/users/$userId/reset-password",
    [
        'password' => $password,
        'password_confirmation' => $passwordConfirmation
    ]
);

$success = ($response['success'] ?? false) === true;

$message = $success
    ? 'User password reset successfully.'
    : ($response['message'] ?? 'Password reset failed.');

if (!$success && !empty($response['errors']['password'][0]))
{
    $message = $response['errors']['password'][0];
}

$_SESSION['user_password_message'] = [
    'success' => $success,
    'text' => $message
];

header('Location: list_users.php');
exit;
