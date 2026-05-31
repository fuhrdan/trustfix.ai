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
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());

    $headers = [
        'Accept: application/json'
    ];

    if (!empty($jwtToken)) {

        $headers[] =
            'Authorization: Bearer ' . $jwtToken;
    }

    $isMultipart = false;

    if (is_array($data))
    {
        foreach ($data as $value)
        {
            if ($value instanceof CURLFile)
            {
                $isMultipart = true;
                break;
            }
        }
    }

    if ($data !== null)
    {
        if ($isMultipart)
        {
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                $data
            );
        }
        else
        {
            $headers[] =
                'Content-Type: application/json';

            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($data)
            );
        }
    }

    curl_setopt(
        $ch,
        CURLOPT_HTTPHEADER,
        $headers
    );

//*************DEBUG*************
//echo "<pre>";
//print_r($headers);
//echo "</pre>";
//*********END DEBUG*************

    $response = curl_exec($ch);

    if ($response === false) {
        die(
            'Curl Error: ' .
            curl_error($ch)
        );
    }

    curl_close($ch);

    return json_decode($response, true);
}