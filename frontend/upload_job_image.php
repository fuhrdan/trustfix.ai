<?php

require 'config.php';

session_start();

requireLogin();

header('Content-Type: application/json');

//=========================================================
// AJAX IMAGE UPLOAD
//=========================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax_upload'])
) {

$debug = [];

    try {

        //-------------------------------------------------
        // Get/Create Draft Job
        //-------------------------------------------------
        $jobId = $_SESSION['draft_job_id'];

        if (!$jobId) {

            $payload = [
                'address' => 'Draft Job',
                'lat' => 0,
                'lng' => 0,
                'initial_description' => 'Draft',
                'agreed_price' => 0
            ];

            $job = apiRequest(
                'POST',
                '/jobs',
                $payload
            );

$debug['create_job_raw'] = $job;

            if (!is_array($job) || !isset($job['id'])) {

                throw new Exception(
                    'Failed creating draft job: '
                    . print_r($job, true)
                );
            }

            $jobId = $job['id'];

            $_SESSION['draft_job_id'] = $jobId;
        }

$debug['job_id'] = $jobId;

        //-------------------------------------------------
        // Validate Upload
        //-------------------------------------------------
        if (
            !isset($_FILES['image'])
            || empty($_FILES['image']['tmp_name'])
        ) {

            throw new Exception('No uploaded file received');
        }

        $debug['file'] = [
            'name' => $_FILES['image']['name'],
            'type' => $_FILES['image']['type'],
            'size' => $_FILES['image']['size']
        ];
        
        //-------------------------------------------------
        // Build CURL File
        //-------------------------------------------------
        $file = new CURLFile(
            $_FILES['image']['tmp_name'],
            $_FILES['image']['type'],
            $_FILES['image']['name']
        );

        //-------------------------------------------------
        // Upload To API
        //-------------------------------------------------
        $uploadResult = apiRequest(
            'POST',
            "/jobs/$jobId/images",
            [
                'images[]' => $file
            ]
        );

        $debug['upload_result'] = $uploadResult;
        
        if (!is_array($uploadResult)) {
            $debug['upload_result_type'] = gettype($uploadResult);
        }
        

        //-------------------------------------------------
        // Fetch Updated Job
        //-------------------------------------------------
        $job = apiRequest(
            'GET',
            "/jobs/$jobId"
        );

file_put_contents(
    '/tmp/job_fetch.txt',
    print_r($job, true),
    FILE_APPEND
);

        if (!is_array($job)) {
            throw new Exception(
                "Job fetch failed. Response: " . print_r($jobResponse, true)
            );
        }

        //-------------------------------------------------
        // Build HTML
        //-------------------------------------------------
        $html = '';

        if (!empty($job['images'])) {

            foreach ($job['images'] as $img) {

                //-----------------------------------------
                // Possible DB field names
                //-----------------------------------------
                $imagePath =
                    $img['image_path']
                    ?? $img['path']
                    ?? $img['url']
                    ?? '';

                //-----------------------------------------
                // Full URL
                //-----------------------------------------
                $url =
                    '/storage/'
                    . ltrim($imagePath, '/');

                $html .= "
                    <div style='margin-bottom:15px;'>

                        <img
                            src='{$url}'
                            style='
                                max-width:200px;
                                border:1px solid #ccc;
                                border-radius:8px;
                            '
                        >
                    {$imagePath}
                    </div>
                ";
            }
        }

        //-------------------------------------------------
        // Success Response
        //-------------------------------------------------
        echo json_encode([
            'success' => true,
            'html' => $html,
            'debug' => $debug,
            'upload_result' => $uploadResult
        ]);

    } catch (Exception $e) {

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => $debug
        ]);

        http_response_code(500);
    }

$debug['upload_result'] = $uploadResult;

file_put_contents(
    '/tmp/upload_result.txt',
    print_r($uploadResult, true),
    FILE_APPEND
);

    exit;
}
