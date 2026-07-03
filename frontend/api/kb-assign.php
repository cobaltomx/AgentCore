<?php
// api/kb-assign.php — Asignar / desasignar documento KB a un agente
require_once __DIR__ . '/../includes/config.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'No autorizado']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$docId  = $body['doc_id']   ?? '';
$agentId = $body['agent_id'] ?? null;  // null = desasignar

if (!$docId) { http_response_code(400); echo json_encode(['error'=>'doc_id requerido']); exit; }

// PATCH /kb/:id con agent_id (puede ser null para desasignar)
$payload = ['agent_id' => $agentId];
$result  = apiPatch("/kb/{$docId}", $payload);
$status  = $result['_status'] ?? 200;
unset($result['_status']);
http_response_code(in_array($status, [200,201]) ? 200 : $status);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
