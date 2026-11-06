<?php

require 'config.php';

$kind = (string)($_GET['kind'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$routes = [
    'job-image' => '/private-files/job-images/',
    'property-image' => '/private-files/property-images/',
    'contractor-document' => '/private-files/contractor-documents/',
    'handyman-document' => '/private-files/handyman-documents/',
    'profile-claim-document' => '/private-files/profile-claim-documents/',
];

if ($id <= 0 || !isset($routes[$kind])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File not found.';
    exit;
}

$token = (string)($_SESSION['jwt_token'] ?? '');
if ($token === '') {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Authentication required.';
    exit;
}

// Release the session lock before streaming a potentially large file.
session_write_close();

$url = $apiBase . $routes[$kind] . $id;
$upstreamHeaders = [];
$requestTimeout = max(20, $apiTimeout);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $requestTimeout));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)$verifyApiSsl);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/octet-stream,image/*,application/pdf',
    'Authorization: Bearer ' . $token,
]);
curl_setopt(
    $ch,
    CURLOPT_HEADERFUNCTION,
    static function ($curl, $line) use (&$upstreamHeaders) {
        $length = strlen($line);
        $trimmed = trim($line);

        if (str_starts_with($trimmed, 'HTTP/')) {
            $upstreamHeaders = [];
            return $length;
        }

        if (str_contains($trimmed, ':')) {
            [$name, $value] = array_map('trim', explode(':', $trimmed, 2));
            $upstreamHeaders[strtolower($name)] = $value;
        }

        return $length;
    }
);

$body = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($body === false || $curlError !== '') {
    error_log('TrustFix private-file proxy failure: ' . $curlError);
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File temporarily unavailable.';
    exit;
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(in_array($httpCode, [401, 403, 404], true) ? $httpCode : 502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $httpCode === 403 ? 'Access denied.' : 'File not found.';
    exit;
}

$contentType = $upstreamHeaders['content-type'] ?? 'application/octet-stream';
if (!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+(?:;\s*charset=[a-z0-9._-]+)?$#i', $contentType)) {
    $contentType = 'application/octet-stream';
}

header('Content-Type: ' . $contentType);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('X-Content-Type-Options: nosniff');

if (!empty($upstreamHeaders['content-disposition'])) {
    $disposition = str_replace(["\r", "\n"], '', $upstreamHeaders['content-disposition']);
    header('Content-Disposition: ' . $disposition);
}

header('Content-Length: ' . strlen($body));
echo $body;
