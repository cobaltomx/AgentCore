<?php
/**
 * order-status.php — actualizar estado de un pedido
 * POST { id, status }
 */
require_once __DIR__ . '/../includes/config.php';
requireAuth();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error'=>'No autorizado']); exit; }
header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = trim($body['id'] ?? '');
$status = trim($body['status'] ?? '');

if (!$id || !isValidUuid($id)) { http_response_code(400); echo json_encode(['error'=>'id inválido']); exit; }
if (!in_array($status, ['pending','paid','fulfilled','cancelled'], true)) {
    http_response_code(400); echo json_encode(['error'=>'Estado inválido']); exit;
}

$result = apiPatch("/orders/{$id}", ['status' => $status]);
$s = $result['_status'] ?? 500;
unset($result['_status']);
http_response_code(in_array($s, [200,201]) ? 200 : $s);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
