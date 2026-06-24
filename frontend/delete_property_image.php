<?php

require 'config.php';

requireLogin();

header('Content-Type: application/json');

$imageId = (int)($_POST['image_id'] ?? 0);

if (!$imageId)
{
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Missing image_id'
    ]);

    exit;
}

$result = apiRequest(
    'DELETE',
    "/property-images/$imageId"
);

if (is_array($result) && !empty($result['success']))
{
    echo json_encode([
        'success' => true
    ]);

    exit;
}

http_response_code(500);

echo json_encode([
    'success' => false,
    'message' => 'Delete failed',
    'api_response' => $result
]);

exit;
