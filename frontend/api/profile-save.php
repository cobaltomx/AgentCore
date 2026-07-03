<?php
/**
 * Proxy: actualizar perfil propio — POST /api/profile-save.php
 * Campos aceptados: name, avatar_url, current_password + new_password
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Solo permitir campos del perfil propio
$allowed = ['name', 'avatar_url', 'current_password', 'new_password'];
$payload = array_intersect_key($body, array_flip($allowed));

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Sin campos válidos']);
    exit;
}

$ch = curl_init(API_BASE . '/auth/me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => 'PATCH',
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . jwtToken(),
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);

$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($resp, true) ?? [];
http_response_code($status);

// Si el nombre fue actualizado, refrescar la sesión
if ($status === 200 && isset($data['name'])) {
    $_SESSION['user']['name']       = $data['name'];
    $_SESSION['user']['avatar_url'] = $data['avatar_url'] ?? $_SESSION['user']['avatar_url'];
}

echo json_encode($data);
