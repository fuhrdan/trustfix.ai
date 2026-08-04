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
        // Get Current Property ID
        //-------------------------------------------------
        $propertyId = (int)($_GET['property_id'] ?? 0);

        if (!$propertyId) {
            throw new Exception('Missing property_id');
        }

        $debug['property_id'] = $propertyId;

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
            "/properties/$propertyId/images",
            [
                'images[]' => $file
            ]
        );

        $debug['upload_result'] = $uploadResult;
        
        if (!is_array($uploadResult)) {
            $debug['upload_result_type'] = gettype($uploadResult);
        }
        

        //-------------------------------------------------
        // Fetch Updated Property
        //-------------------------------------------------
        $property = apiRequest(
            'GET',
            "/properties/$propertyId"
        );

        if (!is_array($property)) {
            throw new Exception(
                "Property fetch failed. Response: " . print_r($propertyResponse, true)
            );
        }

        //-------------------------------------------------
        // Build HTML
        //-------------------------------------------------
        $html = '';

        if (!empty($property['images'])) {

            foreach ($property['images'] as $img) {

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
                $url = storageUrl($imagePath);

                $imageId = (int)($img['id'] ?? 0);
                $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $safePath = htmlspecialchars($imagePath, ENT_QUOTES, 'UTF-8');

                $html .= "
                    <div style='position:relative;display:inline-block;margin:0 15px 15px 0;'>
                        <img
                            src='{$safeUrl}'
                            style='
                                max-width:200px;
                                border:1px solid #ccc;
                                border-radius:8px;
                                display:block;
                            '
                        >
                        <button
                            type='button'
                            onclick='deleteImage({$imageId}, this)'
                            style='
                                position:absolute;
                                top:6px;
                                right:6px;
                                width:32px;
                                height:32px;
                                background:#e53935;
                                color:white;
                                border:none;
                                border-radius:10px;
                                font-size:18px;
                                font-weight:bold;
                                cursor:pointer;
                                line-height:32px;
                                text-align:center;
                            '
                            title='Delete image'
                        >×</button>
                        <div style='font-size:12px;margin-top:4px;'>{$safePath}</div>
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

    exit;
}
