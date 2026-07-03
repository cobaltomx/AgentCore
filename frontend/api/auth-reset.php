<?php
/**
 * Proxy: reset password — POST /api/auth-reset.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true);

$ch = curl_init(API_BASE . '/auth/reset-password');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'token'    => $body['token']    ?? '',
        'password' => $body['password'] ?? '',
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true) ?? [];
http_response_code($status);
echo json_encode($data);
