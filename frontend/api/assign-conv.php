<?php
require_once __DIR__ . '/../includes/config.php';
requireAuth();

header('Content-Type: application/json');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id      = $body['id']      ?? '';
$user_id = $body['user_id'] ?? null;   // null = desasignar

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID requerido']);
    exit;
}
requireUuid($id, 'id');

$result = apiPatch('/conversations/' . rawurlencode($id) . '/assign', [
    'user_id' => $user_id,
]);

if (isset($result['error'])) {
    http_response_code(400);
}
echo json_encode($result);
