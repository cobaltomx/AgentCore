<?php
/**
 * Proxy: Crear o actualizar usuario del tenant
 * POST /api/user-save.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado — sesión expirada']);
    exit;
}
// Nota: el backend valida role con requireAdmin — no doble-validamos aquí
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id'] ?? '');
$action = trim($body['action'] ?? '');
unset($body['id'], $body['action']);

if ($action === 'delete') {
    // Desactivar usuario
    $result = apiDelete("/users/{$id}");
} elseif ($id) {
    // Actualizar
    $result = apiPatch("/users/{$id}", $body);
} else {
    // Crear nuevo
    $result = apiPost('/users', $body);
}

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
