<?php

require_once 'includes/auth.php';

$imageId = $_POST['image_id'];

$url =
    API_BASE_URL .
    "/property-images/" .
    $imageId;

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

curl_setopt(
    $ch,
    CURLOPT_HTTPHEADER,
    [
        "Authorization: Bearer " .
        $_SESSION['token']
    ]
);

$response = curl_exec($ch);

curl_close($ch);

header(
    "Location: " .
    $_SERVER['HTTP_REFERER']
);

exit;