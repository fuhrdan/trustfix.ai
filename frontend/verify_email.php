<?php

require 'config.php';

$signedPath = (string)($_GET['path'] ?? '');
$parts = parse_url($signedPath);
$path = is_array($parts) ? (string)($parts['path'] ?? '') : '';
$queryString = is_array($parts) ? (string)($parts['query'] ?? '') : '';

parse_str($queryString, $query);

$validPath = preg_match(
    '#^/api/email/verify/[0-9]+/[a-f0-9]{40}$#i',
    $path
);
$validQuery = !empty($query['expires'])
    && !empty($query['signature'])
    && ctype_digit((string)$query['expires'])
    && preg_match('/^[a-f0-9]{64}$/i', (string)$query['signature']);
$isRelative = !isset($parts['scheme']) && !isset($parts['host']);

if (!$validPath || !$validQuery || !$isRelative) {
    renderFrontendError(
        400,
        'Invalid Verification Link',
        'This verification link is incomplete. Request a new link from the sign-in page.'
    );
}

$apiEndpoint = substr($path, strlen('/api')) . '?' . http_build_query([
    'expires' => $query['expires'],
    'signature' => $query['signature'],
]);
$result = apiRequest('GET', $apiEndpoint);
$httpCode = (int)($result['_http_code'] ?? $result['http_code'] ?? 0);

if (($result['success'] ?? false) === true || ($httpCode >= 200 && $httpCode < 300)) {
    header('Location: login.php?verified=1');
    exit;
}

renderFrontendError(
    $httpCode === 403 ? 403 : 422,
    'Verification Link Unavailable',
    'This link is invalid or has expired. Request a new verification email and try again.'
);
