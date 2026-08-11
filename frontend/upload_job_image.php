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

        if (!is_array($job) || !isset($job['id'])) {
            throw new RuntimeException(apiMessage($job, 'Unable to start the job draft.'));
        }

        $jobId = $job['id'];
        $_SESSION['draft_job_id'] = $jobId;
    }

    //-------------------------------------------------
    // Validate Upload
    //-------------------------------------------------
    if (!isset($_FILES['image']) || empty($_FILES['image']['tmp_name'])) {
        throw new Exception('No uploaded file received');
    }

    if (!empty($_FILES['image']['error'])) {
        throw new RuntimeException('The uploaded file could not be read.');
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

    if (!is_array($uploadResult) || empty($uploadResult['success'])) {
        throw new RuntimeException(apiMessage($uploadResult, 'The image could not be uploaded.'));
    }

    //-------------------------------------------------
    // Fetch Updated Job
    //-------------------------------------------------
    $job = apiRequest(
        'GET',
        "/jobs/$jobId"
    );

    if (!is_array($job)) {
        throw new RuntimeException('The updated job could not be loaded.');
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
        'html' => $html
    ]);

} catch (Throwable $e) {
    error_log(sprintf('Job image upload failed for job %d: %s', $jobId ?? 0, $e->getMessage()));

    http_response_code(422);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

exit;
