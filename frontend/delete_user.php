<?php

require 'config.php';
requireRole('admin');

$returnQuery = trim(substr((string)($_POST['return_q'] ?? ''), 0, 255));
$returnPage = max(1, (int)($_POST['return_page'] ?? 1));
$returnParams = ['page' => $returnPage];

if ($returnQuery !== '') {
    $returnParams['q'] = $returnQuery;
}

$returnUrl = 'list_users.php?' . http_build_query($returnParams);

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    requireValidCsrf();
    $userId = (int)(
        $_POST['user_id'] ?? 0
    );

    if (!$userId)
    {
        $_SESSION['flash_error'] = 'Missing user id.';
        header('Location: ' . $returnUrl);
        exit;
    }

    $response = apiRequest(
        'DELETE',
        "/admin/users/$userId"
    );

    if (($response['success'] ?? false) === true) {
        $_SESSION['flash_success'] = 'User deleted.';
    } else {
        $_SESSION['flash_error'] = apiMessage($response, 'User delete failed.');
    }

    header(
        'Location: ' . $returnUrl
    );

    exit;
}

header('Location: ' . $returnUrl);
exit;
