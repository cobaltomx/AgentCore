<?php
/**
 * Proxy: validar reset token — GET /api/auth-validate-token.php?token=...
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$token = trim($_GET['token'] ?? '');

$ch = curl_init(API_BASE . '/auth/validate-reset-token?token=' . urlencode($token));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true) ?? ['valid' => false];
http_response_code($status);
echo json_encode($data);
