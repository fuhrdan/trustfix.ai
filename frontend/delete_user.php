<?php

require 'config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
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