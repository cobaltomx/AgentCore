<?php
/**
 * Proxy superadmin: pool de números Twilio
 * POST /api/superadmin-numbers.php  { action, ... }
 *   action = "create" → POST   /superadmin/numbers
 *   action = "update" → PATCH  /superadmin/numbers/:id   (incluye asignar/liberar)
 *   action = "delete" → DELETE /superadmin/numbers/:id
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn() || userRole() !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim($body['action'] ?? 'create');
$id     = trim($body['id'] ?? '');
unset($body['action'], $body['id']);

switch ($action) {
    case 'create':
        $result = apiPost('/superadmin/numbers', $body);
        break;
    case 'update':
        if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $result = apiPatch("/superadmin/numbers/{$id}", $body);
        break;
    case 'delete':
        if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error' => 'ID inválido']); exit; }
        $result = apiDelete("/superadmin/numbers/{$id}");
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
        exit;
}

http_response_code($result['_status'] ?? 200);
unset($result['_status']);
echo json_encode($result);
