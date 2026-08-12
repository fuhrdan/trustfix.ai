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
$frontendSupportEmail = getenv('TRUSTFIX_SUPPORT_EMAIL') ?: 't.tyler@trustfixai.com';

$jwtToken = $_SESSION['jwt_token'] ?? '';

function requireLogin()
{
    if (empty($_SESSION['jwt_token'])) {

        header('Location: login.php');
        exit;
    }
}

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField()
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function requireValidCsrf($jsonResponse = false)
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $submittedToken = $_POST['csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $valid = is_string($submittedToken)
        && hash_equals(csrfToken(), $submittedToken);

    if ($valid) {
        return;
    }

    http_response_code(419);

    if ($jsonResponse) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Your session expired. Refresh the page and try again.'
        ]);
        exit;
    }

    renderFrontendError(
        419,
        'Session Expired',
        'Refresh the page and try that action again.'
    );
}

function currentUser($refresh = false)
{
    if (!$refresh && !empty($_SESSION['user']['role'])) {
        return $_SESSION['user'];
    }

    $user = apiRequest('GET', '/me');

    if (is_array($user) && !empty($user['role'])) {
        $_SESSION['user'] = $user;
        return $user;
    }

    return [];
}

function messageNotificationSummary($refresh = false)
{
    if (empty($_SESSION['jwt_token'])) {
        return [
            'unread_count' => 0,
            'latest_job_id' => null,
        ];
    }

    $cacheKey = 'message_notification_summary';
    $cacheTtl = 45;
    $cached = $_SESSION[$cacheKey] ?? null;

    if (
        !$refresh
        && is_array($cached)
        && isset($cached['fetched_at'], $cached['summary'])
        && (time() - (int)$cached['fetched_at']) < $cacheTtl
    ) {
        return $cached['summary'];
    }

    $fallback = is_array($cached['summary'] ?? null)
        ? $cached['summary']
        : [
            'unread_count' => 0,
            'latest_job_id' => null,
        ];

    // Keep navigation responsive if the API is temporarily constrained.
    $response = apiRequest('GET', '/notifications/messages', null, 5);
    $httpCode = (int)($response['_http_code'] ?? 0);

    if (is_array($response) && $httpCode >= 200 && $httpCode < 300) {
        $fallback = [
            'unread_count' => max(0, (int)($response['unread_count'] ?? 0)),
            'latest_job_id' => !empty($response['latest_job_id'])
                ? (int)$response['latest_job_id']
                : null,
        ];
    }

    $_SESSION[$cacheKey] = [
        'fetched_at' => time(),
        'summary' => $fallback,
    ];

    return $fallback;
}

function forgetMessageNotificationSummary()
{
    unset($_SESSION['message_notification_summary']);
}

function requireRole($roles)
{
    requireLogin();

    $roles = (array)$roles;
    $user = currentUser(true);

    if (!in_array($user['role'] ?? '', $roles, true)) {
        renderFrontendError(
            403,
            'Access Denied',
            'You do not have permission to view this page.'
        );
    }

    return $user;
}

function renderFrontendError($status, $title, $message)
{
    http_response_code((int)$status);
    $pageTitle = (string)$title;

    include __DIR__ . '/header.php';
    ?>
        <section class="tf-empty-state" role="alert">
            <div class="tf-empty-state-icon" aria-hidden="true">!</div>
            <h1><?= htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></p>
            <a class="tf-button" href="dashboard.php">Return to Dashboard</a>
        </section>
    <?php
    include __DIR__ . '/footer.php';
    exit;
}

function supportEmail()
{
    global $frontendSupportEmail;

    return $frontendSupportEmail;
}

function apiRequest($method, $endpoint, $data = null, $timeout = null)
{
    global $apiBase, $jwtToken, $apiTimeout, $verifyApiSsl;

    $url = $apiBase . $endpoint;

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $requestTimeout = $timeout === null
        ? max(20, $apiTimeout)
        : max(2, (int)$timeout);

    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $requestTimeout));
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
