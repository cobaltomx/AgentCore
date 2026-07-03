<?php
// api/kb-get.php — Obtener documento con chunks
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }

$id = trim($_GET['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['error'=>'id requerido']); exit; }
requireUuid($id, 'id');

$result = apiGet("/kb/{$id}");
$status = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code($status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
