<?php

function trustFixLoginSecurityClientContext(): array
{
    global $trustfixProxySecret;

    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        return [];
    }

    $secret = isset($trustfixProxySecret)
        ? trim((string)$trustfixProxySecret)
        : trim((string)(getenv('TRUSTFIX_PROXY_SECRET') ?: ''));

    if ($secret === '') {
        return [];
    }

    $timestamp = time();
    $signature = hash_hmac('sha256', $ipAddress . '|' . $timestamp, $secret);

    return [
        '_client_ip' => $ipAddress,
        '_client_ip_ts' => $timestamp,
        '_client_ip_sig' => $signature,
    ];
}
