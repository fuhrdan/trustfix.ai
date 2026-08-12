<?php

require 'config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    requireValidCsrf();
    $userId = (int)(
        $_POST['user_id'] ?? 0
    );

    if (!$userId)
    {
        die('Missing user id');
    }

    apiRequest(
        'DELETE',
        "/admin/users/$userId"
    );

    header(
        'Location: list_users.php'
    );

    exit;
}
