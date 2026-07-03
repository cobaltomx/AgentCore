<?php
// api/kb-reingest.php
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$body = json_decode(file_get_contents('php://input'), true);
$id   = trim($body['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
requireUuid($id, 'id');

$result = apiPost("/kb/{$id}/reingest", []);
http_response_code($result['_status'] ?? 200);
echo json_encode($result);
