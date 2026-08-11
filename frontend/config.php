<?php

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $secureRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwardedProto === 'https';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

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

$jwtToken = $_SESSION['jwt_token'] ?? '';

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

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);

        error_log(sprintf('TrustFix API connection failure for %s: %s', $endpoint, $error));

        return [
            'success' => false,
            'message' => 'TrustFix is temporarily unavailable. Please try again.'
        ];
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log(sprintf('TrustFix API returned invalid JSON for %s (HTTP %d).', $endpoint, $httpCode));

        return [
            'success' => false,
            'http_code' => $httpCode,
            'message' => 'TrustFix returned an unexpected response. Please try again.'
        ];
    }

    if (is_array($decoded) && !array_is_list($decoded)) {
        $decoded['_http_code'] = $httpCode;
    }

    return $decoded;
}

function apiMessage($response, $fallback = 'Something went wrong. Please try again.')
{
    if (!is_array($response)) {
        return $fallback;
    }

    foreach (['message', 'error'] as $key) {
        if (isset($response[$key]) && is_string($response[$key]) && trim($response[$key]) !== '') {
            return trim($response[$key]);
        }
    }

    return $fallback;
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
