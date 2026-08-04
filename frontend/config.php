<?php

session_start();

// A local file is convenient on shared hosting and is ignored by Git.
// It may set $apiBase, $apiTimeout, and $verifyApiSsl.
$apiBase = getenv('TRUSTFIX_API_BASE') ?: 'https://api.lakehousesoftware.com/api';
$apiTimeout = (int)(getenv('TRUSTFIX_API_TIMEOUT') ?: 75);
$verifyApiSsl = getenv('TRUSTFIX_VERIFY_API_SSL') !== 'false';
$localConfig = __DIR__ . '/config.local.php';

if (is_file($localConfig)) {
    require $localConfig;
}

$apiBase = rtrim($apiBase, '/');

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
    global $apiBase, $jwtToken, $apiTimeout, $verifyApiSsl;

    $url = $apiBase . $endpoint;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(20, $apiTimeout));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)$verifyApiSsl);
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
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'success' => false,
            'message' => 'Curl Error: ' . $error
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'API returned non-JSON response',
            'raw_response' => $response
        ];
    }

    if (is_array($decoded)) {
        $decoded['_http_code'] = $httpCode;
    }

    return $decoded;
}

function storageUrl($path)
{
    global $apiBase;

    if (empty($path)) {
        return '';
    }

    if (preg_match('/^https?:\/\//', $path)) {
        return $path;
    }

    $base = preg_replace('/\/api\/?$/', '', rtrim($apiBase, '/'));

    return $base . '/storage/' . ltrim($path, '/');
}
