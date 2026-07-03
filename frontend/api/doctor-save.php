<?php
/**
 * Proxy: Crear / actualizar / eliminar doctor
 * POST /api/doctor-save.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn())                         { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if (!isAdmin())                            { http_response_code(403); echo json_encode(['error' => 'Solo admins']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id']     ?? '');
$action = trim($body['action'] ?? '');
unset($body['id'], $body['action']);

if ($action === 'delete') {
    $result = apiDelete("/doctors/{$id}");
} elseif ($id) {
    $result = apiPatch("/doctors/{$id}", $body);
} else {
    $result = apiPost('/doctors', $body);
}

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
