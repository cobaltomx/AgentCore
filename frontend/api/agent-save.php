<?php
/**
 * API Proxy: Guardar agente (crear o actualizar)
 * POST /api/agent-save.php
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$id   = trim($body['id'] ?? '');
unset($body['id']);

if ($id) {
    requireUuid($id, 'id');
    // Actualizar — backend expone PUT /agents/:id
    $result = apiPut("/agents/{$id}", $body);
} else {
    // Crear
    $result = apiPost('/agents', $body);
}

http_response_code($result['_status'] ?? 200);
echo json_encode($result);
