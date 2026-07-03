<?php
/**
 * GET /api/conversations-today.php
 * Devuelve stats de conversaciones: hoy, últimos 7 días, por canal.
 */
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$ch = curl_init(API_BASE . '/conversations/stats');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . jwtToken(),
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => defined('APP_ENV') && APP_ENV === 'production',
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($status ?: 200);
echo $body !== false ? $body : json_encode(['error' => 'Sin respuesta del backend']);
