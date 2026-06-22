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
        // Get/Create Draft Property
        //-------------------------------------------------
        $propertyId = $_SESSION['draft_property_id'];

        if (!$propertyId) {

            $payload = [
                'address' => 'Draft Property',
                'lat' => 0,
                'lng' => 0,
                'initial_description' => 'Draft',
                'agreed_price' => 0
            ];

            $property = apiRequest(
                'POST',
                '/properties',
                $payload
            );

$debug['create_property_raw'] = $property;

            if (!is_array($property) || !isset($property['id'])) {

                throw new Exception(
                    'Failed creating draft property: '
                    . print_r($property, true)
                );
            }

            $propertyId = $property['id'];

            $_SESSION['draft_property_id'] = $propertyId;
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

    exit;
}
