<?php
// api/kb-update.php — Actualizar título o contenido de un documento KB
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = $body['id'] ?? '';
if (!$id) { http_response_code(400); echo json_encode(['error'=>'id requerido']); exit; }

$payload = [];
if (!empty($body['title']))   $payload['title']   = $body['title'];
if (!empty($body['content'])) $payload['content'] = $body['content'];

if (empty($payload)) { http_response_code(400); echo json_encode(['error'=>'Sin campos']); exit; }

$result = apiPatch("/kb/{$id}", $payload);
$status = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code(in_array($status, [200,201]) ? 200 : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
