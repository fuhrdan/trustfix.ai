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

    try {

        //-------------------------------------------------
        // Get/Create Draft Job
        //-------------------------------------------------
        $jobId = $_SESSION['draft_job_id'] ?? null;

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

            if (!isset($job['id'])) {

                throw new Exception(
                    'Failed creating draft job: '
                    . print_r($job, true)
                );
            }

            $jobId = $job['id'];

            $_SESSION['draft_job_id'] = $jobId;
        }

        //-------------------------------------------------
        // Validate Upload
        //-------------------------------------------------
        if (
            !isset($_FILES['image'])
            || empty($_FILES['image']['tmp_name'])
        ) {

            throw new Exception('No uploaded file received');
        }

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

        //-------------------------------------------------
        // DEBUG
        //-------------------------------------------------
        file_put_contents(
            '/tmp/upload_result.txt',
            print_r($uploadResult, true)
        );

if (
    isset($uploadResult['error']) ||
    isset($uploadResult['message'])
) {

    echo json_encode([
        'success' => false,
        'error' => $uploadResult
    ]);


        //-------------------------------------------------
        // Fetch Updated Job
        //-------------------------------------------------
        $job = json_decode(apiRequest(
            'GET',
            "/jobs/$jobId"
        ), true);

/*
        file_put_contents(
            '/tmp/job_debug.txt',
            print_r($job, true)
        );
*/
//NEXT DEBUG
file_put_contents('/tmp/before_job_get.txt', "ABOUT TO CALL JOB GET\n");
file_put_contents('/tmp/job_response_raw.txt', print_r($job, true));

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
                    'https://trustfix.lakehousesoftware.com/storage/'
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
            'upload_result' => $uploadResult
        ]);

    } catch (Exception $e) {

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
}

echo json_encode([
    'success' => false,
    'error' => 'Invalid request'
]);