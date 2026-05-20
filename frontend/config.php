<?php

session_start();

//The api base needs to be updated for "real" deployment
$apiBase = 'https://api.lakehousesoftware.com/api';

$jwtToken = $_SESSION['jwt_token'] ?? ''; // Token for login

function requireLogin()
{
    if (empty($_SESSION['jwt_token'])) {

        header('Location: login.php');
        exit;
    }
}

function apiRequest($method, $endpoint, $data = null)
{
    global $apiBase, $jwtToken;

    $url = $apiBase . $endpoint;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $headers = [
        'Content-Type: application/json'
    ];

    if (!empty($jwtToken)) {

        $headers[] =
            'Authorization: Bearer ' . $jwtToken;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data !== null) {

        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($data)
        );
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {

        die(
            'Curl Error: ' .
            curl_error($ch)
        );
    }

    curl_close($ch);

    return json_decode($response, true);
}