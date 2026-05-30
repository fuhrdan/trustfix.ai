<?php

require 'config.php';
requireLogin();

header('Content-Type: application/json');

$imageId = (int)($_POST['image_id'] ?? 0);

if (!$imageId) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing image ID'
    ]);
    exit;
}

//-------------------------------------------------
// Delete via API
//-------------------------------------------------
$response = apiRequest(
    'DELETE',
    "/job-images/$imageId"
);

if (!$response) {
    echo json_encode([
        'success' => false,
        'error' => 'Delete failed'
    ]);
    exit;
}

echo json_encode([
    'success' => true
]);