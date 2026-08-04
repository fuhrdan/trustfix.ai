<?php

require 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

$debug = [];

try {

    //-------------------------------------------------
    // Get/Create Draft Job
    //-------------------------------------------------
    $jobId = $_SESSION['draft_job_id'] ?? null;

    if (!$jobId) {

        $propertyId = (int)($_POST['property_id'] ?? 0);

        if ($propertyId <= 0) {
            throw new Exception('Please select a job address before uploading pictures.');
        }

        $job = apiRequest(
            'POST',
            '/jobs',
            [
                'property_id' => $propertyId,
                'address' => 'Draft Job',
                'lat' => 0,
                'lng' => 0,
                'initial_description' => 'Draft Job',
                'agreed_price' => 0
            ]
        );

        $debug['create_job_response'] = $job;

        if (!is_array($job) || !isset($job['id'])) {
            throw new Exception(
                'Failed creating draft job: ' . print_r($job, true)
            );
        }

        $jobId = $job['id'];
        $_SESSION['draft_job_id'] = $jobId;
    }

    $debug['job_id'] = $jobId;

    //-------------------------------------------------
    // Validate Upload
    //-------------------------------------------------
    if (!isset($_FILES['image']) || empty($_FILES['image']['tmp_name'])) {
        throw new Exception('No uploaded file received');
    }

    $debug['file'] = [
        'name' => $_FILES['image']['name'] ?? '',
        'type' => $_FILES['image']['type'] ?? '',
        'size' => $_FILES['image']['size'] ?? 0,
        'error' => $_FILES['image']['error'] ?? null
    ];

    if (!empty($_FILES['image']['error'])) {
        throw new Exception('PHP upload error code: ' . $_FILES['image']['error']);
    }

    //-------------------------------------------------
    // Upload To API
    //-------------------------------------------------
    $file = new CURLFile(
        $_FILES['image']['tmp_name'],
        $_FILES['image']['type'],
        $_FILES['image']['name']
    );

    $uploadResult = apiRequest(
        'POST',
        "/jobs/$jobId/images",
        [
            'images[]' => $file
        ]
    );

    $debug['upload_result'] = $uploadResult;

    if (!is_array($uploadResult) || empty($uploadResult['success'])) {
        throw new Exception(
            'API image upload failed: ' . print_r($uploadResult, true)
        );
    }

    //-------------------------------------------------
    // Fetch Updated Job
    //-------------------------------------------------
    $job = apiRequest(
        'GET',
        "/jobs/$jobId"
    );

    $debug['job_response'] = $job;

    if (!is_array($job)) {
        throw new Exception(
            'Job fetch failed. Response: ' . print_r($job, true)
        );
    }

    //-------------------------------------------------
    // Build HTML
    //-------------------------------------------------
    $html = '';

    if (!empty($job['images'])) {
        foreach ($job['images'] as $img) {
            $imagePath = $img['image_path'] ?? '';
            $url = storageUrl($imagePath);

            $html .= "
                <div style='margin-bottom:15px;'>
                    <img
                        src='" . htmlspecialchars($url) . "'
                        style='max-width:200px;border:1px solid #ccc;border-radius:8px;'
                    >
                </div>
            ";
        }
    }

    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'html' => $html,
        'debug' => $debug
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug
    ]);
}

exit;
