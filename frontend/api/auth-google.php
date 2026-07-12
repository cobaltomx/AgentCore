<?php
/**
 * Proxy de "Iniciar sesión con Google" — POST /api/auth-google.php
 * Recibe { credential } (ID token de Google Identity Services) → lo manda
 * al backend para verificar → guarda sesión igual que un login normal.
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body       = json_decode(file_get_contents('php://input'), true);
$credential = trim($body['credential'] ?? '');

if (!$credential) {
    echo json_encode(['ok' => false, 'error' => 'Falta el token de Google.']);
    exit;
}

$ch = curl_init(API_BASE . '/auth/google');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['credential' => $credential]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($body, true) ?? [];

if ($status !== 200 || empty($data['token'])) {
    $error = $data['error'] ?? 'No se pudo iniciar sesión con Google.';
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

echo json_encode(establishSession($data));
