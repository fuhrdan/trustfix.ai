<?php

require 'config.php';
requireLogin();

header('Content-Type: application/json');
requireValidCsrf(true);

$imageId = (int)($_POST['image_id'] ?? 0);
$jobId = (int)($_POST['job_id'] ?? 0);

if (!$imageId || !$jobId) {
    echo json_encode([
        'success' => false,
        'error' => 'Missing job or image ID'
    ]);
    exit;
}

//-------------------------------------------------
// Delete via API
//-------------------------------------------------
$response = apiRequest(
    'DELETE',
    "/jobs/$jobId/images/$imageId"
);

if (!is_array($response)
    || (int)($response['_http_code'] ?? 500) < 200
    || (int)($response['_http_code'] ?? 500) >= 300
    || empty($response['success'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => apiMessage($response, 'Delete failed')
    ]);
    exit;
}

echo json_encode([
    'success' => true
]);
